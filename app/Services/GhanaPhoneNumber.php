<?php

namespace App\Services;

/**
 * Normalises Ghana mobile numbers to E.164 so dialled channels and stored
 * directory numbers can be matched without ambiguity.
 */
class GhanaPhoneNumber
{
    public const COUNTRY_CODE = '233';

    /** National significant number length, e.g. 24 123 4567. */
    public const NATIONAL_LENGTH = 9;

    /** Ghana mobile numbers start with 02x or 05x nationally. */
    public const MOBILE_PATTERN = '/^[25][0-9]{8}$/';

    public static function fingerprint(?string $value): ?string
    {
        $number = self::normalize($value);

        return $number === null ? null : hash_hmac('sha256', $number, (string) config('app.key'));
    }

    /**
     * Normalise a supplied number to +233XXXXXXXXX or null when unusable.
     */
    public static function normalize(?string $value): ?string
    {
        $digits = self::digits($value);

        if ($digits === null) {
            return null;
        }

        $national = match (true) {
            strlen($digits) === 12 && str_starts_with($digits, self::COUNTRY_CODE) => substr($digits, 3),
            strlen($digits) === 10 && str_starts_with($digits, '0') => substr($digits, 1),
            strlen($digits) === self::NATIONAL_LENGTH => $digits,
            default => null,
        };

        if ($national === null || preg_match(self::MOBILE_PATTERN, $national) !== 1) {
            return null;
        }

        return '+'.self::COUNTRY_CODE.$national;
    }

    /**
     * Format a normalised number for display, e.g. 024 123 4567.
     */
    public static function formatLocal(?string $value): ?string
    {
        $normalized = self::normalize($value);

        if ($normalized === null) {
            return null;
        }

        $national = substr($normalized, 4);

        return '0'.substr($national, 0, 2).' '.substr($national, 2, 3).' '.substr($national, 5);
    }

    /**
     * Mask a number for redacted views while keeping it recognisable, e.g. +233241*****67.
     */
    public static function mask(?string $value): ?string
    {
        $normalized = self::normalize($value);

        return $normalized === null
            ? null
            : substr($normalized, 0, 7).str_repeat('*', 5).substr($normalized, -2);
    }

    /**
     * Mask any digit run for derived references such as inbound lines, caller
     * IDs or channels that are not guaranteed to be Ghana mobile numbers, e.g.
     * 233*****34. The raw digits are discarded.
     */
    public static function maskReference(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);

        if (! is_string($digits) || strlen($digits) < 6) {
            return null;
        }

        return substr($digits, 0, 3).str_repeat('*', max(strlen($digits) - 5, 1)).substr($digits, -2);
    }

    /**
     * Extract and normalise a dialled number from an Asterisk channel name or
     * dial string.
     *
     * A PJSIP dial string names the endpoint after the number
     * (`PJSIP/0241234567@196.201.48.139`), while the resulting channel is named
     * after the endpoint alone (`PJSIP/196.201.48.139-00000826`). The number is
     * therefore only readable from the dial string, and the endpoint must be
     * dropped before normalising or every transferred call looks unparseable.
     */
    public static function fromChannel(?string $channel): ?string
    {
        if ($channel === null || trim($channel) === '') {
            return null;
        }

        $position = strrpos($channel, '/');
        $segment = $position === false ? $channel : substr($channel, $position + 1);

        // Asterisk appends a unique suffix such as "-00000001" to the channel.
        $segment = preg_replace('/-[0-9a-f]{1,12}$/i', '', $segment) ?? $segment;

        // A dial string carries the endpoint/context after the dialled number.
        $endpoint = strpos($segment, '@');
        if ($endpoint !== false) {
            $segment = substr($segment, 0, $endpoint);
        }

        $number = self::normalize($segment);

        if ($number !== null) {
            return $number;
        }

        // Fall back to any Ghana mobile token in the dial string so a channel
        // decorated by the dialplan still resolves to its destination.
        return preg_match('/(?:\+?233|0)[235][0-9]{8}/', $channel, $matches) === 1
            ? self::normalize($matches[0])
            : null;
    }

    /** Redact dialable numbers embedded in a channel, retaining short agent extensions. */
    public static function redactChannel(?string $channel): ?string
    {
        if ($channel === null) {
            return null;
        }

        $channel = preg_replace('/(?:\+?233|0)[235]\d{8}/', '[mobile]', $channel) ?? $channel;

        return preg_replace('/(?<=\/)\+?\d{7,15}(?=[@;\-\/]|$)/', '[number]', $channel) ?? $channel;
    }

    private static function digits(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $trimmed);

        if (! is_string($digits) || $digits === '') {
            return null;
        }

        // A plus-prefixed foreign number must never be treated as a Ghana destination.
        if (str_starts_with($trimmed, '+') && ! str_starts_with($digits, self::COUNTRY_CODE)) {
            return null;
        }

        return $digits;
    }
}
