<?php

use App\Services\GhanaPhoneNumber;

it('normalises Ghana mobile numbers to E.164', function (string $input, string $expected) {
    expect(GhanaPhoneNumber::normalize($input))->toBe($expected);
})->with([
    'local format' => ['0241234567', '+233241234567'],
    'spaced' => ['024 123 4567', '+233241234567'],
    'punctuated' => ['(024) 123-4567', '+233241234567'],
    'country code without plus' => ['233241234567', '+233241234567'],
    'country code with plus' => ['+233 24 123 4567', '+233241234567'],
    'second prefix' => ['0541234567', '+233541234567'],
]);

it('rejects values that are not Ghana mobile numbers', function (?string $input) {
    expect(GhanaPhoneNumber::normalize($input))->toBeNull();
})->with([
    'extension' => ['204'],
    'too short' => ['024123456'],
    'too long' => ['02412345678'],
    'fixed line prefix' => ['0301234567'],
    'foreign plus number' => ['+44241234567'],
    'empty' => [''],
    'null' => [null],
]);

it('formats and masks normalised numbers', function () {
    expect(GhanaPhoneNumber::formatLocal('0241234567'))->toBe('024 123 4567')
        ->and(GhanaPhoneNumber::mask('0241234567'))->toBe('+233241*****67')
        ->and(GhanaPhoneNumber::formatLocal('204'))->toBeNull();
});

it('extracts the dialled number from Asterisk channel names', function () {
    expect(GhanaPhoneNumber::fromChannel('PJSIP/trunk/0241234567-0000001'))->toBe('+233241234567')
        ->and(GhanaPhoneNumber::fromChannel('PJSIP/trunk/+233241234567'))->toBe('+233241234567')
        ->and(GhanaPhoneNumber::fromChannel('PJSIP/204-00000001'))->toBeNull()
        ->and(GhanaPhoneNumber::fromChannel(null))->toBeNull();
});

it('matches mobile channels with hexadecimal Asterisk suffixes', function () {
    expect(GhanaPhoneNumber::fromChannel('PJSIP/trunk/0241234567-000000af'))->toBe('+233241234567');
});

it('reads the dialled number from a PJSIP dial string that names the trunk endpoint', function () {
    expect(GhanaPhoneNumber::fromChannel('PJSIP/0241234567@196.201.48.139'))->toBe('+233241234567')
        ->and(GhanaPhoneNumber::fromChannel('PJSIP/0531015150@196.201.48.139-00000826'))->toBe('+233531015150')
        ->and(GhanaPhoneNumber::fromChannel('PJSIP/233241234567@196.201.48.139'))->toBe('+233241234567')
        ->and(GhanaPhoneNumber::fromChannel('PJSIP/196.201.48.139-00000826'))->toBeNull();
});
