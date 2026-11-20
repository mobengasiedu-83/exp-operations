<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Throwable;

class AmiEventNormalizer
{
    public const EVENTS = ['Newchannel', 'NewCallerid', 'Newstate', 'Newexten', 'DTMFEnd', 'UserEvent', 'DialBegin', 'DialEnd', 'BridgeEnter', 'BridgeLeave', 'BridgeDestroy', 'Hold', 'Unhold', 'AttendedTransfer', 'BlindTransfer', 'Hangup', 'QueueCallerJoin', 'AgentConnect'];

    /** @param array<string, mixed> $frame
     * @return array<string, mixed>|null
     */
    public function normalize(array $frame, CarbonInterface $receivedAt): ?array
    {
        $event = collect(self::EVENTS)->first(fn (string $name): bool => strtolower($name) === strtolower((string) ($frame['Event'] ?? '')));
        if ($event === null) {
            return null;
        }
        $frame['Event'] = $event;
        if ($event === 'UserEvent' && ! $this->isIvrUserEvent($frame)) {
            return null;
        }
        $at = CarbonImmutable::instance($receivedAt)->utc();
        $quality = 'received';
        $invalidClock = false;
        if (isset($frame['Timestamp'])) {
            try {
                if (! is_numeric($frame['Timestamp']) || (float) $frame['Timestamp'] <= 0) {
                    throw new \InvalidArgumentException;
                }
                $candidate = CarbonImmutable::createFromTimestampUTC((float) $frame['Timestamp']);
                if ($candidate->gt($at->addMinutes(5))) {
                    throw new \InvalidArgumentException;
                }
                $at = $candidate;
                $quality = 'pbx';
            } catch (Throwable) {
                $invalidClock = true;
            }
        }
        $participants = [];
        foreach (['', 'Dest', 'Transferer', 'OrigTransferer', 'SecondTransferer', 'Transferee', 'TransferTarget', 'LocalOne', 'LocalTwo'] as $prefix) {
            $id = $this->identifier($frame[$prefix.'Uniqueid'] ?? null);
            if ($id === null) {
                continue;
            }
            $channel = is_string($frame[$prefix.'Channel'] ?? null) ? mb_substr($frame[$prefix.'Channel'], 0, 255) : null;
            $role = $this->isAgent($channel) ? 'agent' : 'unknown';
            $context = $frame[$prefix.'Context'] ?? '';
            $extension = $frame[$prefix.'Exten'] ?? '';
            $entry = null;
            if ($prefix === '' && $role !== 'agent') {
                if ($this->isIvrContext($context)) {
                    $role = 'caller';
                    $entry = 'ivr';
                } elseif ($this->isInboundDid($extension)
                    || in_array($context, config('services.exp_pbx.inbound_contexts', []), true)) {
                    $role = 'caller';
                    $entry = 'did';
                }
            }
            if ((($prefix === 'Dest' && $frame['Event'] === 'DialBegin') || ($prefix === 'TransferTarget' && $frame['Event'] === 'AttendedTransfer')) && $role !== 'agent') {
                $dial = $frame['DialString'] ?? $extension;
                if ($this->isMobile($extension) || $this->isMobile($dial)) {
                    $role = 'mobile';
                }
            }
            $number = $role === 'mobile' ? (GhanaPhoneNumber::normalize(is_string($extension) ? $extension : null)
                ?? GhanaPhoneNumber::fromChannel($prefix === 'Dest' && is_string($frame['DialString'] ?? null) ? $frame['DialString'] : $channel)) : null;
            $caller = in_array($role, ['caller', 'unknown'], true)
                ? $this->callerEvidence($frame[$prefix.'CallerIDNum'] ?? $frame[$prefix.'ConnectedLineNum'] ?? null)
                : ['masked' => null, 'hash' => null];
            $participants[] = [
                'uniqueid' => $id,
                'linkedid' => $this->identifier($frame[$prefix.'Linkedid'] ?? null),
                'channel' => GhanaPhoneNumber::redactChannel($channel),
                'role' => $role, 'entry_kind' => $entry,
                'inbound_masked' => $role === 'caller' ? GhanaPhoneNumber::maskReference($extension) : null,
                'caller_masked' => $caller['masked'], 'caller_hash' => $caller['hash'],
                'number_hash' => GhanaPhoneNumber::fingerprint($number), 'number_masked' => GhanaPhoneNumber::mask($number),
            ];
        }
        if ($frame['Event'] === 'DialBegin' && count($participants) >= 2 && $participants[0]['role'] === 'unknown' && $participants[1]['role'] === 'agent') {
            $participants[0]['role'] = 'caller';
            $participants[0]['entry_kind'] = 'agent_route';
            $caller = $this->callerEvidence($frame['CallerIDNum'] ?? $frame['ConnectedLineNum'] ?? null);
            $participants[0]['caller_masked'] = $caller['masked'];
            $participants[0]['caller_hash'] = $caller['hash'];
        }
        $state = $frame['ChannelStateDesc'] ?? match ((string) ($frame['ChannelState'] ?? '')) {
            '4' => 'Ring', '5' => 'Ringing', '6' => 'Up', default => '',
        };
        $data = [
            'participants' => $participants,
            'primary_id' => $this->identifier($frame['Uniqueid'] ?? $frame['TransfereeUniqueid'] ?? null),
            'destination_id' => $this->identifier($frame['DestUniqueid'] ?? null),
            'bridge_id' => $this->identifier($frame['BridgeUniqueid'] ?? null),
            'state' => in_array($state, ['Ring', 'Ringing', 'Up'], true) ? $state : null,
            'dial_status' => in_array($frame['DialStatus'] ?? null, ['ANSWER', 'BUSY', 'NOANSWER', 'CANCEL', 'CONGESTION', 'CHANUNAVAIL', 'DONTCALL', 'TORTURE', 'INVALIDARGS'], true) ? $frame['DialStatus'] : null,
            'transfer_type' => in_array($frame['DestType'] ?? null, ['Bridge', 'App', 'Link', 'Threeway', 'Fail'], true) ? $frame['DestType'] : null,
            'transfer_result' => in_array($frame['Result'] ?? null, ['Success', 'Fail', 'Invalid', 'Not Permitted'], true) ? $frame['Result'] : null,
            'invalid_timestamp' => $invalidClock,
            'ivr' => $this->ivrStep($frame, $event),
        ];

        return ['event_name' => $frame['Event'], 'occurred_at' => $at->format('Y-m-d H:i:s.u'), 'timing_quality' => $quality, 'data' => $data];
    }

    /**
     * One key press on the inbound menu, captured as evidence.
     *
     * VitalPBX reports the press as a DTMFEnd frame on the caller channel
     * inside the menu context (IVR-2, app-announcement). A Newexten frame with
     * a digit is also accepted. A menu position without a digit is not a
     * selection, so it stays out of the journey rather than being guessed.
     *
     * @param  array<string, mixed>  $frame
     * @return array{menu: string|null, option: string, application: string|null, app_data: string|null}|null
     */
    private function ivrStep(array $frame, string $event): ?array
    {
        if (! in_array($event, ['Newexten', 'UserEvent', 'DTMFEnd'], true)) {
            return null;
        }

        $context = is_string($frame['Context'] ?? null) ? mb_substr(trim($frame['Context']), 0, 100) : null;
        $inIvrContext = $this->isIvrContext($context);
        $menu = $event === 'UserEvent' ? ($context ?? (string) config('exp_ivr.user_event')) : $context;

        if ($event === 'DTMFEnd') {
            $direction = $frame['Direction'] ?? 'Received';
            $channel = is_string($frame['Channel'] ?? null) ? $frame['Channel'] : null;

            if (! $inIvrContext || $this->isAgent($channel)
                || (is_string($direction) && strcasecmp($direction, 'Received') !== 0)) {
                return null;
            }
        }

        if ($event === 'Newexten' && ! $inIvrContext) {
            return null;
        }

        $option = $this->ivrOption($frame);

        if ($option === null) {
            return null;
        }

        return [
            'menu' => $menu === '' ? null : $menu,
            'option' => $option,
            'application' => $event === 'DTMFEnd'
                ? 'DTMF'
                : (is_string($frame['Application'] ?? null) ? mb_substr(trim($frame['Application']), 0, 100) : null),
            'app_data' => is_string($frame['AppData'] ?? null) ? mb_substr(trim($frame['AppData']), 0, 255) : null,
        ];
    }

    /**
     * A dialplan context that collects a caller key press. Patterns may use
     * wildcards so a stock VitalPBX install works without naming every IVR.
     */
    private function isIvrContext(?string $context): bool
    {
        if ($context === null || $context === '') {
            return false;
        }

        foreach (config('services.exp_pbx.ivr_contexts', []) as $pattern) {
            if (is_string($pattern) && $pattern !== '' && Str::is(strtolower($pattern), strtolower($context))) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $frame */
    private function ivrOption(array $frame): ?string
    {
        foreach (['Digit', 'Option', 'Selection', 'Exten'] as $key) {
            $value = $frame[$key] ?? null;

            if (is_string($value) && preg_match('/^[0-9*#]$/D', trim($value)) === 1) {
                return trim($value);
            }
        }

        return null;
    }

    /** @param array<string, mixed> $frame */
    private function isIvrUserEvent(array $frame): bool
    {
        $expected = config('exp_ivr.user_event');

        return is_string($expected) && $expected !== ''
            && (is_string($frame['UserEvent'] ?? null) && $frame['UserEvent'] === $expected);
    }

    private function identifier(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9_.:@\/-]{1,255}$/D', $value) ? $value : null;
    }

    private function isInboundDid(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        $number = $this->ghanaNumber($value);
        if ($number === null) {
            return false;
        }
        foreach (config('services.exp_pbx.inbound_dids', []) as $did) {
            if ($number === $this->ghanaNumber($did)) {
                return true;
            }
        }

        return false;
    }

    private function ghanaNumber(string $value): ?string
    {
        if (preg_match('/^0[0-9]{9}$/D', $value)) {
            return '233'.substr($value, 1);
        }
        if (preg_match('/^(?:\+|00)?(233[0-9]{9})$/D', $value, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * A caller ID is only ever kept as a masked reference plus a keyed
     * fingerprint. The raw number is never written to call events or legs.
     *
     * @return array{masked: string|null, hash: string|null}
     */
    private function callerEvidence(mixed $value): array
    {
        $number = is_string($value) ? $value : null;

        return [
            'masked' => GhanaPhoneNumber::mask($number) ?? GhanaPhoneNumber::maskReference($number),
            'hash' => GhanaPhoneNumber::fingerprint($number),
        ];
    }

    private function isAgent(?string $channel): bool
    {
        $extension = preg_quote((string) config('services.exp_pbx.screening_extension', '204'), '/');

        return $channel !== null && preg_match('/^(?:PJSIP|SIP|Local)\/'.$extension.'(?:[-@;]|$)/', $channel) === 1;
    }

    private function isMobile(mixed $value): bool
    {
        return is_string($value) && preg_match('/(?:^|\/)(?:0|\+?233)[235]\d{8}(?:@[^\s]+)?$/D', $value) === 1;
    }
}
