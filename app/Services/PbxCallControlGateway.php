<?php

namespace App\Services;

use App\Models\Interaction;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;

/** Adapter contract for a separately provisioned PBX control service. */
class PbxCallControlGateway
{
    public function configured(): bool
    {
        $url = config('services.exp_call_control.url');

        return config('services.exp_call_control.enabled') && config('services.exp_call_control.lab_approved')
            && is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false
            && parse_url($url, PHP_URL_SCHEME) === 'https' && parse_url($url, PHP_URL_USER) === null
            && filled(config('services.exp_call_control.token')) && filled(config('services.exp_call_control.identity'));
    }

    /** @return array<string, mixed> */
    public function snapshot(Interaction $interaction): array
    {
        $data = $this->client()->get('/v1/calls/'.rawurlencode($interaction->correlation_key))->throw()->json();
        $validator = Validator::make(is_array($data) ? $data : [], [
            'interaction_key' => ['required', 'string'], 'version' => ['required', 'string', 'max:128'],
            'state' => ['required', 'string', Rule::in(array_keys(CallControl::TRANSITIONS))],
            'observed_at' => ['required', 'date'], 'control_identity' => ['required', 'string'],
            'agent_extension' => ['required', 'string'], 'caller_uniqueid' => ['required', 'string'],
            'agent_uniqueid' => ['required', 'string'], 'dentist_uniqueid' => ['nullable', 'string'],
            'caller_present' => ['required', 'boolean'], 'agent_present' => ['required', 'boolean'],
            'agent_media_ready' => ['required', 'boolean'], 'topology_verified' => ['required', 'boolean'],
            'last_command_key' => ['nullable', 'uuid'],
            'attempt_outcome' => ['nullable', Rule::in(['busy', 'no_answer', 'voicemail', 'failed', 'cancelled'])],
        ]);
        if ($validator->fails()) {
            throw new RuntimeException('Invalid call-control snapshot.');
        }

        return $validator->validated();
    }

    /** @param array<string, mixed> $payload */
    public function dispatch(string $key, array $payload): void
    {
        $response = $this->client()->withHeaders(['Idempotency-Key' => $key])->post('/v1/commands', ['request_key' => $key, ...$payload])->throw();
        if ($response->json('request_key') !== $key) {
            throw new RuntimeException('Invalid command acknowledgement.');
        }
    }

    public function result(string $key): string
    {
        $response = $this->client()->get('/v1/commands/'.rawurlencode($key))->throw();
        $status = $response->json('status');
        if ($response->json('request_key') !== $key || ! in_array($status, ['pending', 'applied', 'rejected'], true)) {
            throw new RuntimeException('Invalid command result.');
        }

        return $status;
    }

    private function client(): PendingRequest
    {
        if (! $this->configured()) {
            throw new RuntimeException('Call control is not configured and approved.');
        }

        return Http::baseUrl(rtrim(config('services.exp_call_control.url'), '/'))->withToken(config('services.exp_call_control.token'))
            ->acceptJson()->asJson()->connectTimeout(2)->timeout(5)->withOptions(['allow_redirects' => false]);
    }
}
