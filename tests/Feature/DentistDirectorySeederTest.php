<?php

use App\Models\Dentist;
use App\Models\DentistNumber;
use Database\Seeders\DentistDirectorySeeder;

test('the directory seeder records every practice dentist with an approved primary mobile', function () {
    $this->seed(DentistDirectorySeeder::class);

    expect(Dentist::query()->count())->toBe(7)
        ->and(Dentist::query()->where('active', true)->count())->toBe(7)
        ->and(DentistNumber::query()->count())->toBe(7)
        ->and(DentistNumber::query()->where('status', 'approved')->where('is_primary', true)->count())->toBe(7)
        ->and(DentistNumber::query()->orderBy('number')->pluck('number')->all())->toBe([
            '+233257956504',
            '+233531015150',
            '+233531015455',
            '+233556760650',
            '+233556760651',
            '+233556760659',
            '+233593989921',
        ]);

    $number = Dentist::query()->where('name', 'Dr Paapa Puplampu')->sole()->numbers()->sole();

    expect($number->number)->toBe('+233556760659')
        ->and($number->original)->toBe('0556760659')
        ->and($number->label)->toBe('Mobile')
        ->and($number->status)->toBe('approved')
        ->and($number->is_primary)->toBeTrue()
        ->and($number->verified_at)->not->toBeNull();
});

test('re-running the directory seeder keeps one record per dentist and number', function () {
    $this->seed(DentistDirectorySeeder::class);
    $this->seed(DentistDirectorySeeder::class);

    expect(Dentist::query()->count())->toBe(7)
        ->and(DentistNumber::query()->count())->toBe(7);
});
