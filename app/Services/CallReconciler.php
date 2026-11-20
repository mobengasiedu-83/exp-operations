<?php

namespace App\Services;

use App\Models\CdrRecord;
use App\Models\CelRecord;
use App\Models\CorrelationException;
use App\Models\Interaction;
use App\Models\PbxConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CallReconciler
{
    public function __construct(private TelemetryCoverage $coverage) {}

    public function reconcile(Interaction $interaction): Interaction
    {
        return DB::transaction(function () use ($interaction): Interaction {
            PbxConnection::whereKey($interaction->pbx_connection_id)->lockForUpdate()->firstOrFail();
            $interaction->refresh();
            $hasGap = $this->coverage->invalidate($interaction);
            $interaction->exceptions()->where('category', 'reconciliation')->delete();
            if ($interaction->status !== 'completed') {
                $interaction->update(['reconciliation_status' => 'pending', 'reconciled_at' => null]);

                return $interaction;
            }
            $tolerance = max(0, min(300, (int) config('services.exp_pbx.reconciliation_tolerance_seconds', 2)));
            $legs = $interaction->legs()->get();
            $aliases = $interaction->correlation_aliases ?? [$interaction->correlation_key];
            $cdr = CdrRecord::where('pbx_connection_id', $interaction->pbx_connection_id)->where(fn ($query) => $query->whereIn('uniqueid', $legs->pluck('uniqueid'))->orWhereIn('linkedid', $aliases))->get();
            $cel = CelRecord::where('pbx_connection_id', $interaction->pbx_connection_id)->where(fn ($query) => $query->whereIn('uniqueid', $legs->pluck('uniqueid'))->orWhereIn('linkedid', $aliases))->get();
            $timeline = $interaction->events()->orderBy('occurred_at')->orderBy('id')->get();
            $unprocessed = CorrelationException::query()->where('pbx_connection_id', $interaction->pbx_connection_id)->where('code', 'event_processing_failed')->exists();
            if ($unprocessed) {
                $this->issue($interaction, 'unprocessed_ami_evidence', (string) $interaction->pbx_connection_id);
            }
            $incomplete = $unprocessed || $hasGap || $legs->isEmpty() || $interaction->exceptions()->where('category', 'projection')->exists() || $interaction->timing_quality !== 'pbx';
            $mismatch = false;
            foreach (['cdr' => $cdr, 'cel' => $cel] as $kind => $sourceRows) {
                foreach ($sourceRows as $source) {
                    if (! $legs->contains('uniqueid', $source->uniqueid)) {
                        $this->issue($interaction, 'unmatched_'.$kind.'_leg', $source->uniqueid ?? '(missing ID)');
                        $incomplete = true;
                    }
                }
            }
            foreach ($legs as $leg) {
                $records = $cdr->where('uniqueid', $leg->uniqueid);
                $events = $cel->where('uniqueid', $leg->uniqueid);
                if ($records->isEmpty()) {
                    $this->issue($interaction, 'missing_cdr', $leg->uniqueid);
                    $incomplete = true;
                } else {
                    $starts = $records->pluck('start')->filter()->sort();
                    if ($leg->observed_creation && $leg->started_at !== null && $starts->isNotEmpty()) {
                        $mismatch = $this->compare($interaction, 'cdr_start_mismatch', $leg->uniqueid, $leg->started_at, CarbonImmutable::instance($starts->first()), $tolerance) || $mismatch;
                    }
                    $answers = $records->pluck('answer')->filter()->sort();
                    $ends = $records->pluck('end')->filter()->sort();
                    foreach (['start' => $starts->isEmpty(), 'answer' => $leg->answered_at !== null && $answers->isEmpty(), 'end' => $ends->isEmpty()] as $field => $missing) {
                        if ($missing) {
                            $this->issue($interaction, 'missing_cdr_'.$field, $leg->uniqueid);
                            $incomplete = true;
                        }
                    }
                    if ($leg->answered_at !== null && $answers->isNotEmpty()) {
                        $mismatch = $this->compare($interaction, 'cdr_answer_mismatch', $leg->uniqueid, $leg->answered_at, CarbonImmutable::instance($answers->first()), $tolerance) || $mismatch;
                    }
                    if ($leg->ended_at !== null && $ends->isNotEmpty()) {
                        $mismatch = $this->compare($interaction, 'cdr_hangup_mismatch', $leg->uniqueid, $leg->ended_at, CarbonImmutable::instance($ends->last()), $tolerance) || $mismatch;
                    }
                    $cdrAnswered = $records->contains(fn (CdrRecord $record): bool => $record->disposition === 'ANSWERED');
                    if ($records->whereNotNull('disposition')->isEmpty()) {
                        $this->issue($interaction, 'missing_cdr_disposition', $leg->uniqueid);
                        $incomplete = true;
                    } elseif ($cdrAnswered !== ($leg->answered_at !== null)) {
                        $this->issue($interaction, 'cdr_answer_state_mismatch', $leg->uniqueid);
                        $mismatch = true;
                    }
                    if ($records->count() === 1 && $leg->answered_at !== null && $leg->ended_at !== null && $records->first()->billsec !== null) {
                        $seconds = $leg->answered_at->diffInMicroseconds($leg->ended_at) / 1000000;
                        if (abs($seconds - $records->first()->billsec) > $tolerance) {
                            $this->issue($interaction, 'cdr_connected_duration_mismatch', $leg->uniqueid, ['ami_seconds' => $seconds, 'cdr_seconds' => $records->first()->billsec]);
                            $mismatch = true;
                        }
                    }
                }
                if ($events->isEmpty()) {
                    $this->issue($interaction, 'missing_cel', $leg->uniqueid);
                    $incomplete = true;
                } else {
                    $start = $events->filter(fn (CelRecord $record): bool => strtoupper($record->event_type ?? '') === 'CHAN_START')->sortBy('event_time')->first();
                    if ($start?->event_time === null) {
                        $this->issue($interaction, 'missing_cel_start', $leg->uniqueid);
                        $incomplete = true;
                    } elseif ($leg->observed_creation && $leg->started_at !== null) {
                        $mismatch = $this->compare($interaction, 'cel_start_mismatch', $leg->uniqueid, $leg->started_at, CarbonImmutable::parse($start->event_time, 'UTC'), $tolerance) || $mismatch;
                    }
                    $hangup = $events->sortByDesc('event_time')->first(fn (CelRecord $record): bool => in_array(strtoupper($record->event_type ?? ''), ['HANGUP', 'CHAN_END'], true));
                    if ($hangup === null || $hangup->event_time === null) {
                        $this->issue($interaction, 'missing_cel_hangup', $leg->uniqueid);
                        $incomplete = true;
                    } elseif ($leg->ended_at !== null) {
                        $mismatch = $this->compare($interaction, 'cel_hangup_mismatch', $leg->uniqueid, $leg->ended_at, CarbonImmutable::parse($hangup->event_time, 'UTC'), $tolerance) || $mismatch;
                    }
                    $expectedEntries = $timeline->filter(fn ($event): bool => $event->event_name === 'BridgeEnter' && $event->data['primary_id'] === $leg->uniqueid)->count();
                    $sourceEntries = $events->filter(fn (CelRecord $record): bool => in_array(strtoupper($record->event_type ?? ''), ['BRIDGE_ENTER', 'BRIDGE_START'], true))->count();
                    $sourceExits = $events->filter(fn (CelRecord $record): bool => in_array(strtoupper($record->event_type ?? ''), ['BRIDGE_EXIT', 'BRIDGE_END'], true))->count();
                    if ($sourceEntries !== $expectedEntries || $sourceExits !== $expectedEntries) {
                        $this->issue($interaction, 'cel_bridge_evidence_mismatch', $leg->uniqueid, ['ami_entries' => $expectedEntries, 'cel_entries' => $sourceEntries, 'cel_exits' => $sourceExits]);
                        $mismatch = true;
                    }
                    foreach (['BridgeEnter' => ['BRIDGE_ENTER', 'BRIDGE_START'], 'BridgeLeave' => ['BRIDGE_EXIT', 'BRIDGE_END']] as $amiName => $celTypes) {
                        $observed = $timeline->filter(fn ($event): bool => $event->event_name === $amiName && $event->data['primary_id'] === $leg->uniqueid)->values();
                        $sourceEvents = $events->filter(fn (CelRecord $record): bool => in_array(strtoupper($record->event_type ?? ''), $celTypes, true))->sortBy('event_time')->values();
                        if ($observed->count() !== $sourceEvents->count()) {
                            $this->issue($interaction, 'incomplete_bridge_timeline', $leg->uniqueid.':'.$amiName);
                            $incomplete = true;

                            continue;
                        }
                        foreach ($observed as $index => $event) {
                            $sourceTime = $sourceEvents[$index]->event_time;
                            if ($sourceTime === null) {
                                $this->issue($interaction, 'missing_cel_bridge_time', $leg->uniqueid.':'.$amiName);
                                $incomplete = true;
                            } else {
                                $mismatch = $this->compare($interaction, 'cel_bridge_time_mismatch', $leg->uniqueid.':'.$amiName.':'.$index, $event->occurred_at, CarbonImmutable::parse($sourceTime, 'UTC'), $tolerance) || $mismatch;
                            }
                        }
                    }
                    $answer = $events->sortBy('event_time')->first(fn (CelRecord $record): bool => strtoupper($record->event_type ?? '') === 'ANSWER');
                    if ($leg->answered_at === null) {
                        if ($answer !== null) {
                            $this->issue($interaction, 'cel_answer_state_mismatch', $leg->uniqueid);
                            $mismatch = true;
                        }
                    } elseif ($answer === null || $answer->event_time === null) {
                        $this->issue($interaction, 'missing_cel_answer', $leg->uniqueid);
                        $incomplete = true;
                    } else {
                        $mismatch = $this->compare($interaction, 'cel_answer_mismatch', $leg->uniqueid, $leg->answered_at, CarbonImmutable::parse($answer->event_time, 'UTC'), $tolerance) || $mismatch;
                    }
                }
            }
            $interaction->update([
                'reconciliation_status' => $mismatch ? 'discrepancy' : ($incomplete ? 'insufficient' : 'matched'),
                'reconciled_at' => now(),
            ]);

            return $interaction;
        }, 3);
    }

    private function compare(Interaction $interaction, string $code, string $subject, CarbonImmutable $observed, CarbonImmutable $source, int $tolerance): bool
    {
        $delta = abs($observed->diffInMicroseconds($source) / 1000000);
        if ($delta <= $tolerance) {
            return false;
        }
        $this->issue($interaction, $code, $subject, ['delta_seconds' => $delta]);

        return true;
    }

    /** @param array<string, mixed> $details */
    private function issue(Interaction $interaction, string $code, string $subject, array $details = []): void
    {
        CorrelationException::firstOrCreate(['fingerprint' => hash('sha256', $interaction->id.':reconciliation:'.$code.':'.$subject)], [
            'pbx_connection_id' => $interaction->pbx_connection_id, 'interaction_id' => $interaction->id,
            'category' => 'reconciliation', 'code' => $code, 'subject' => mb_substr($subject, 0, 255), 'details' => $details,
        ]);
    }
}
