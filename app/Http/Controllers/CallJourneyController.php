<?php

namespace App\Http\Controllers;

use App\Models\CallerCase;
use App\Models\CorrelationException;
use App\Models\Dentist;
use App\Models\Interaction;
use App\Models\PbxConnection;
use App\Models\ScreeningProtocol;
use App\Models\TransferAttempt;
use App\Models\User;
use App\Services\CallHistoryRecord;
use App\Services\CallJourneyTimeline;
use App\Services\CallReconciler;
use App\Services\GhanaPhoneNumber;
use App\Services\LiveOperationsSnapshot;
use App\Services\TelemetryCoverage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CallJourneyController extends Controller
{
    /** Query-string keys accepted by the history list, detail return link and CSV export. */
    private const FILTERS = [
        'status', 'reconciliation_status', 'connection', 'scope', 'connection_id', 'key',
        'number', 'caller', 'call_id', 'agent_id', 'dentist_id', 'from', 'to',
    ];

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canAny(['view pbx', 'view call history']), 403);
        $filters = $this->filters($request);

        return Inertia::render('Calls/Index', [
            'interactions' => $this->filteredQuery($filters, $request->user())
                ->with($this->historyRelations())
                ->withCount(['legs', 'events', 'exceptions'])
                ->orderByDesc('started_at')->orderByDesc('id')->paginate(25)->withQueryString()
                ->through(fn (Interaction $interaction): array => [
                    ...$interaction->makeHidden(['legs', 'callerCase', 'attempts'])->append(['caller_number', 'inbound_line', 'dentist_number'])->toArray(),
                    ...collect(app(CallHistoryRecord::class)->summary($interaction, $request->user()))->except('case_record')->all(),
                ]),
            'filters' => array_filter($filters, fn (mixed $value): bool => filled($value)),
            'agents' => User::query()
                ->whereIn('id', CallerCase::query()->whereNotNull('assigned_to')->select('assigned_to'))
                ->orderBy('name')->get(['id', 'name']),
            'dentists' => Dentist::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, Interaction $interaction): Response
    {
        abort_unless($request->user()?->canAny(['view pbx', 'view call history']), 403);

        abort_unless(Interaction::query()->visibleInHistory($request->user())->whereKey($interaction->id)->exists(), 404);
        $interaction->load(['callerCase.agent:id,name', 'attempts.dentist:id,name', 'attempts.session']);
        $record = app(CallHistoryRecord::class)->summary($interaction, $request->user());
        $case = $record['case_record'];
        $case?->load(['screenings.answers', 'screeningDrafts', 'consents', 'notes']);
        activity('call-history')->causedBy($request->user())->performedOn($interaction)
            ->withProperties(['case_visible' => $case !== null])->log('Call detail viewed');

        return Inertia::render('Calls/Show', [
            'record' => $record,
            'filters' => collect($request->only(self::FILTERS))->filter(fn (mixed $value): bool => filled($value))->all(),
            'durations' => fn (): array => app(CallHistoryRecord::class)->durations($interaction),
            'authors' => $this->authors($case),
            'audit' => fn (): array => $this->auditTrail($interaction, $case),
            'telemetry' => fn (): array => [
                'freshness' => app(LiveOperationsSnapshot::class)->connectionFreshness(PbxConnection::find($interaction->pbx_connection_id)),
                'gaps' => app(TelemetryCoverage::class)->gapsFor($interaction)->map(fn ($gap): array => $gap->only(['id', 'started_at', 'ended_at', 'reason'])),
                'unprocessed_events' => CorrelationException::query()->where('pbx_connection_id', $interaction->pbx_connection_id)->where('code', 'event_processing_failed')->count(),
            ],
            'protocols' => $case === null ? [] : ScreeningProtocol::query()->with('questions')->whereIn('id',
                $case->screenings->pluck('screening_protocol_id')->merge($case->screeningDrafts->pluck('screening_protocol_id'))->merge($case->consents->pluck('screening_protocol_id'))->unique()
            )->get(),
            'attempts' => $interaction->attempts->map(fn (TransferAttempt $attempt): array => [
                ...$attempt->attributesToArray(),
                'dentist' => $attempt->dentist?->name,
                'session' => $attempt->session,
                'status_label' => Str::headline($attempt->status),
                'human_outcome_label' => $attempt->human_outcome === null ? null : Str::headline($attempt->human_outcome),
                'connected' => $attempt->isVerifiedConnection(),
                'unverified' => $attempt->hasUnverifiedBridgeEvidence(),
            ]),
            'timeline' => app(CallJourneyTimeline::class)->for($interaction),

            'interaction' => $interaction->loadMissing('legs')->makeHidden(['legs', 'callerCase', 'attempts'])
                ->append(['caller_number', 'inbound_line', 'dentist_number']),
            'legs' => $interaction->legs()->orderBy('started_at')->orderBy('id')->paginate(50, ['*'], 'legs_page')->withQueryString(),
            'events' => $interaction->events()->orderBy('occurred_at')->orderBy('id')->paginate(50, ['*'], 'events_page')->withQueryString(),
            'bridges' => $interaction->bridges()->orderBy('started_at')->orderBy('id')->paginate(50, ['*'], 'bridges_page')->withQueryString(),
            'metrics' => $interaction->metrics()->orderBy('id')->paginate(50, ['*'], 'metrics_page')->withQueryString(),
            'exceptions' => $interaction->exceptions()->orderBy('category')->orderBy('id')->paginate(50, ['*'], 'exceptions_page')->withQueryString(),
        ]);
    }

    /**
     * Privacy-limited CSV of the filtered history. Export is a separate
     * permission because the blueprint disables it for read-only roles by
     * default, and every export is written to the audit trail.
     */
    public function export(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->canAny(['view pbx', 'view call history']), 403);
        $this->authorize('export call history');
        $filters = $this->filters($request);

        $query = $this->filteredQuery($filters, $request->user())
            ->with($this->historyRelations())
            ->orderByDesc('started_at')->orderByDesc('id');

        activity('call-history')->causedBy($request->user())
            ->withProperties(['filters' => array_filter($filters), 'format' => 'csv'])
            ->log('Call history exported');

        return response()->streamDownload(function () use ($query, $request): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            fputcsv($output, [
                'Call ID', 'Call start (UTC)', 'Caller number', 'Caller name', 'Agent', 'Final verified dentist',
                'Connected number', 'Connection', 'Attempted dentists', 'Attempts', 'Questions answered',
                'Outcome', 'Duration seconds', 'Call state',
            ]);

            $query->chunk(200, function ($interactions) use ($output, $request): void {
                foreach ($interactions as $interaction) {
                    $record = app(CallHistoryRecord::class)->summary($interaction, $request->user());
                    fputcsv($output, array_map(fn (mixed $value): string => $this->csvCell($value), [
                        '#'.$interaction->id,
                        $interaction->started_at?->toIso8601String(),
                        $interaction->caller_number,
                        $record['caller_name'],
                        $record['agent'],
                        $record['connected_dentist'],
                        $record['connected_destination'],
                        $record['connection_label'],
                        implode('; ', $record['attempted_dentists']),
                        $record['attempts_count'],
                        $record['answered_questions'].'/'.$record['question_count'],
                        $record['outcome_label'],
                        $record['duration_seconds'],
                        $interaction->status,
                    ]));
                }
            });

            fclose($output);
        }, 'call-history-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function reconcile(Interaction $interaction, CallReconciler $reconciler): RedirectResponse
    {
        $this->authorize('view pbx');
        $this->authorize('edit settings');
        $reconciler->reconcile($interaction);

        return back();
    }

    /**
     * Match a typed phone number without storing raw numbers. The keyed
     * fingerprint finds an exact caller or dentist number, and the masked
     * variants match the references the listener retained.
     *
     * @param  Builder<Interaction>  $query
     * @return Builder<Interaction>
     */
    private function whereNumber(Builder $query, string $value): Builder
    {
        $hash = GhanaPhoneNumber::fingerprint($value);
        $masked = array_values(array_unique(array_filter([
            GhanaPhoneNumber::mask($value),
            GhanaPhoneNumber::maskReference($value),
            str_contains($value, '*') ? trim($value) : null,
        ])));

        if ($hash === null && $masked === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('legs', function (Builder $legs) use ($hash, $masked): void {
            $legs->where(function (Builder $leg) use ($hash, $masked): void {
                if ($hash !== null) {
                    $leg->orWhere('caller_hash', $hash)->orWhere('destination_hash', $hash);
                }

                foreach ($masked as $candidate) {
                    $leg->orWhere('caller_masked', $candidate)
                        ->orWhere('destination_masked', $candidate)
                        ->orWhere('inbound_masked', $candidate);
                }
            });
        });
    }

    /**
     * History filters, including the blueprint's caller, agent, dentist, date,
     * status, call ID, connection state and "my calls" scoping.
     *
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return $request->validate([
            'status' => ['nullable', Rule::in(['active', 'completed'])],
            'connection' => ['nullable', Rule::in(['verified', 'unverified', 'none'])],
            'scope' => ['nullable', Rule::in(['mine', 'all'])],
            'reconciliation_status' => ['nullable', Rule::in(['pending', 'matched', 'insufficient', 'discrepancy'])],
            'connection_id' => ['nullable', 'integer', 'exists:pbx_connections,id'],
            'key' => ['nullable', 'string', 'max:255'],
            'number' => ['nullable', 'string', 'max:64'],
            'caller' => ['nullable', 'string', 'max:100'],
            'call_id' => ['nullable', 'integer', 'min:1'],
            'agent_id' => ['nullable', 'integer', 'min:1'],
            'dentist_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Interaction>
     */
    private function filteredQuery(array $filters, User $user): Builder
    {
        return Interaction::query()->visibleInHistory($user)
            ->when(($filters['scope'] ?? null) === 'mine', fn (Builder $query) => $query->whereHas('callerCase', fn (Builder $cases) => $cases->where('assigned_to', $user->id)))
            ->when($filters['caller'] ?? null, fn ($query, $value) => $query->whereHas('callerCase', fn ($cases) => $cases->visibleTo($user)->when(! $user->can('view cases'), fn ($cases) => $cases->whereRaw('1 = 0'))->where('preferred_name_hash', CallerCase::nameFingerprint($value))))
            ->when($filters['call_id'] ?? null, fn ($query, $value) => $query->whereKey($value))
            ->when($filters['agent_id'] ?? null, fn ($query, $value) => $query->whereHas('callerCase', fn ($cases) => $cases->where('assigned_to', $value)))
            ->when($filters['dentist_id'] ?? null, fn ($query, $value) => $query->whereHas('attempts', fn ($attempts) => $attempts->where('dentist_id', $value)))
            ->when($filters['from'] ?? null, fn ($query, $value) => $query->whereDate('started_at', '>=', $value))
            ->when($filters['to'] ?? null, fn ($query, $value) => $query->whereDate('started_at', '<=', $value))
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($filters['reconciliation_status'] ?? null, fn ($query, $value) => $query->where('reconciliation_status', $value))
            ->when($filters['connection_id'] ?? null, fn ($query, $value) => $query->where('pbx_connection_id', $value))
            ->when($filters['key'] ?? null, fn ($query, $value) => $query->where(fn ($keys) => $keys->where('correlation_key', $value)->orWhereJsonContains('correlation_aliases', $value)))
            ->when($filters['number'] ?? null, fn (Builder $query, string $value): Builder => $this->whereNumber($query, $value))
            ->when($filters['connection'] ?? null, fn (Builder $query, string $value): Builder => $this->whereConnection($query, $value));
    }

    /**
     * @param  Builder<Interaction>  $query
     * @return Builder<Interaction>
     */
    private function whereConnection(Builder $query, string $state): Builder
    {
        return match ($state) {
            'verified' => $query->whereHas('attempts', fn (Builder $attempts) => $attempts->verifiedConnection()),
            'unverified' => $query
                ->whereHas('attempts', fn (Builder $attempts) => $attempts->unverifiedBridgeEvidence())
                ->whereDoesntHave('attempts', fn (Builder $attempts) => $attempts->verifiedConnection()),
            default => $query
                ->whereDoesntHave('attempts', fn (Builder $attempts) => $attempts->verifiedConnection())
                ->whereDoesntHave('attempts', fn (Builder $attempts) => $attempts->unverifiedBridgeEvidence()),
        };
    }

    /** @return array<int|string, mixed> */
    private function historyRelations(): array
    {
        return [
            'callerCase.agent:id,name',
            'callerCase.screenings.answers' => fn ($answers) => $answers->select('id', 'screening_id', 'answer_status'),
            'attempts.dentist:id,name',
            'attempts.session',
            'legs' => fn ($legs) => $legs->select(['id', 'interaction_id', 'role', 'channel', 'caller_masked', 'inbound_masked', 'destination_masked']),
        ];
    }

    /**
     * Names for the people who recorded answers, notes and consent so the
     * detail page can attribute every historical row.
     *
     * @return array<int, string>
     */
    private function authors(?CallerCase $case): array
    {
        if ($case === null) {
            return [];
        }

        $ids = $case->screenings->flatMap(fn ($screening) => $screening->answers->pluck('recorded_by'))
            ->merge($case->screenings->pluck('recorded_by'))
            ->merge($case->consents->pluck('recorded_by'))
            ->merge($case->notes->pluck('recorded_by'))
            ->filter()->unique()->values();

        return User::query()->whereIn('id', $ids)->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Access and change audit for this call and its case. Viewing the detail
     * page is logged before this runs, so the reader sees their own access.
     *
     * @return array<int, array<string, string|null>>
     */
    private function auditTrail(Interaction $interaction, ?CallerCase $case): array
    {
        return Activity::query()
            ->where(function (Builder $query) use ($interaction, $case): void {
                $query->where(fn (Builder $inner) => $inner->where('subject_type', Interaction::class)->where('subject_id', $interaction->id));

                if ($case !== null) {
                    $query->orWhere(fn (Builder $inner) => $inner->where('subject_type', CallerCase::class)->where('subject_id', $case->id));
                }
            })
            ->with('causer:id,name')
            ->latest('id')->limit(25)->get()
            ->map(fn (Activity $activity): array => [
                'description' => (string) $activity->description,
                'log_name' => (string) $activity->log_name,
                'causer' => $activity->causer?->name,
                'at' => $activity->created_at?->toIso8601String(),
            ])->all();
    }

    /**
     * Neutralise spreadsheet formula prefixes without altering masked numbers.
     */
    private function csvCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $text = (string) $value;

        return preg_match('/^[=@\t\r]/', $text) === 1 ? "'".$text : $text;
    }
}
