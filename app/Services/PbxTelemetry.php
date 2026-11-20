<?php

namespace App\Services;

use App\Models\AmiEvent;
use App\Models\ConnectionHealth;
use App\Models\CorrelationException;
use App\Models\IntegrationHealth;
use App\Models\PbxConnection;
use App\Models\StructuredListenerLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PbxTelemetry
{
    public function __construct(private AmiEventNormalizer $normalizer, private CallCorrelator $correlator) {}

    /** @param array<string, mixed> $values */
    public function health(PbxConnection $connection, array $values): void
    {
        ConnectionHealth::updateOrCreate(['connection_id' => $connection->id], $values);
    }

    /** @param array<string, string> $frame */
    public function event(PbxConnection $connection, array $frame): void
    {
        if ($this->ignored((string) ($frame['Event'] ?? ''))) {
            return;
        }
        $lag = isset($frame['Timestamp']) && is_numeric($frame['Timestamp'])
            ? min(4294967295, max(0, (int) (microtime(true) - (float) $frame['Timestamp']))) : null;
        $receivedAt = now();
        $redacted = $this->redact($frame);
        $source = AmiEvent::create([
            'pbx_connection_id' => $connection->id,
            'event_name' => mb_substr($frame['Event'], 0, 255),
            'uniqueid' => $frame['Uniqueid'] ?? null,
            'linkedid' => $frame['Linkedid'] ?? null,
            'channel' => $redacted['Channel'] ?? null,
            'payload' => $redacted,
            'received_at' => $receivedAt,
            'correlation_data' => $this->normalizer->normalize($frame, $receivedAt),
        ]);
        try {
            $interaction = $this->correlator->process($source);
            CorrelationException::where('fingerprint', hash('sha256', 'ami-processing:'.$source->id))->delete();
            if ($interaction !== null && ! CorrelationException::where('code', 'event_processing_failed')->exists()) {
                IntegrationHealth::record('call_correlation', 'healthy', null, ['last_ami_event_id' => $source->id]);
            }
        } catch (\Throwable) {
            CorrelationException::firstOrCreate(['fingerprint' => hash('sha256', 'ami-processing:'.$source->id)], [
                'pbx_connection_id' => $connection->id, 'category' => 'ingestion',
                'code' => 'event_processing_failed', 'subject' => 'ami:'.$source->id,
                'details' => ['ami_event_id' => $source->id],
            ]);
            IntegrationHealth::record('call_correlation', 'unhealthy', 'Call correlation failed; replay retained AMI events after resolving the error.');
        }
        $connection->update(['last_event_at' => now()]);
        $this->health($connection, ['last_event_at' => now(), 'lag_seconds' => $lag]);
        if (config('services.exp_pbx.log_each_event', false)) {
            $this->log($connection, 'info', 'AMI event received.', ['event_name' => $frame['Event'], 'lag_seconds' => $lag]);
        }
    }

    /**
     * Should the frame be dropped before storage?
     *
     * The listener must keep up with bursts of dialplan and RTCP noise; those
     * frames carry no journey evidence and would otherwise delay the AMI
     * heartbeat long enough for the PBX to drop the connection.
     */
    private function ignored(string $event): bool
    {
        foreach (config('services.exp_pbx.ignored_events', []) as $pattern) {
            if (is_string($pattern) && $pattern !== '' && Str::is(strtolower($pattern), strtolower($event))) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function redact(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (preg_match('/^exten$/i', $key) && is_string($value) && preg_match('/^[0-9*#]$/D', trim($value)) === 1) {
                // A single dialled digit is a menu selection, not a phone number.
                // Keeping it makes the retained frame replayable after a fix.
                continue;
            }
            if (preg_match('/secret|password|token|authorization|dialstring|callerid|connectedline|variable|account|userfield|appdata|lastdata|src|dst|exten/i', $key)) {
                $payload[$key] = '[REDACTED]';
            } elseif (is_string($value) && preg_match('/channel/i', $key)) {
                $payload[$key] = GhanaPhoneNumber::redactChannel($value);
            } elseif (is_array($value)) {
                $payload[$key] = $this->redact($value);
            }
        }

        return $payload;
    }

    /** @param array<string, mixed> $context */
    public function log(PbxConnection $connection, string $level, string $message, array $context = []): void
    {
        StructuredListenerLog::create([
            'connection_id' => $connection->id, 'level' => $level, 'message' => $message,
            'event_name' => $context['event_name'] ?? null,
            'lag_seconds' => $context['lag_seconds'] ?? null,
            'backoff_seconds' => $context['backoff_seconds'] ?? null,
            'context' => $this->redact($context), 'logged_at' => now(),
        ]);
        $this->bound(StructuredListenerLog::class);
    }

    /**
     * Apply age and row retention. Called on a timer by the listener and by
     * `exp:prune-telemetry`; never on the per-event hot path.
     */
    public function prune(): void
    {
        AmiEvent::where('received_at', '<', now()->subDays(max(1, (int) config('services.exp_pbx.retention_days', 7))))->delete();
        StructuredListenerLog::where('logged_at', '<', now()->subDays(max(1, (int) config('services.exp_pbx.retention_days', 7))))->delete();
        $this->bound(AmiEvent::class);
        $this->bound(StructuredListenerLog::class);
    }

    /** @param class-string<Model> $model */
    private function bound(string $model): void
    {
        $cutoff = $model::orderByDesc('id')->skip(max(1, (int) config('services.exp_pbx.max_records', 100000)))->value('id');
        if ($cutoff !== null) {
            $model::where('id', '<=', $cutoff)->delete();
        }
    }
}
