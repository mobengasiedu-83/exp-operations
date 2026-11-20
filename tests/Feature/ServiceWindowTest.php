<?php

use App\Models\Dentist;
use App\Models\DentistRoster;
use App\Models\User;
use App\Services\ServiceCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function serviceAt(string $moment): CarbonImmutable
{
    return CarbonImmutable::parse($moment, 'Africa/Accra');
}

/** @param list<string> $permissions */
function rosterUser(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

test('the line runs monday to friday between 10:00 and 14:00 ghana time', function () {
    $calendar = app(ServiceCalendar::class);

    expect($calendar->isOpenAt(serviceAt('2026-03-09 10:30')))->toBeTrue()
        ->and($calendar->isOpenAt(serviceAt('2026-03-09 13:59')))->toBeTrue()
        ->and($calendar->state(serviceAt('2026-03-09 09:30'))['state'])->toBe('before_hours')
        ->and($calendar->state(serviceAt('2026-03-09 14:00'))['state'])->toBe('after_hours')
        ->and($calendar->state(serviceAt('2026-03-07 11:00'))['state'])->toBe('weekend');
});

test('ghana public holidays close the line and a weekend holiday is observed on monday', function () {
    $calendar = app(ServiceCalendar::class);

    expect($calendar->holidayName(serviceAt('2026-03-06')))->toBe('Independence Day')
        ->and($calendar->state(serviceAt('2026-03-06 11:00'))['state'])->toBe('holiday')
        ->and($calendar->holidayName(serviceAt('2026-04-03')))->toBe('Good Friday')
        ->and($calendar->holidayName(serviceAt('2026-12-28')))->toBe('Boxing Day (observed)')
        ->and($calendar->isOpenAt(serviceAt('2026-12-28 11:00')))->toBeFalse();
});

test('the next opening skips the weekend and public holidays', function () {
    $calendar = app(ServiceCalendar::class);

    $next = $calendar->nextOpening(serviceAt('2026-03-05 15:00'));

    expect($next)->not->toBeNull()
        ->and($next?->toDateString())->toBe('2026-03-09')
        ->and($next?->format('H:i'))->toBe('10:00');
});

test('standard rosters follow the service window and skip public holidays', function () {
    $dentist = Dentist::factory()->create();

    $this->actingAs(rosterUser(['view dentists', 'edit dentists']))
        ->post(route('dentists.rosters.generate'), [
            'from' => '2026-03-02',
            'weeks' => 1,
            'dentist_id' => $dentist->id,
        ])
        ->assertRedirect();

    $rosters = DentistRoster::query()->where('dentist_id', $dentist->id)->orderBy('starts_at')->get();

    expect($rosters)->toHaveCount(4)
        ->and($rosters->first()->starts_at->timezone('Africa/Accra')->format('H:i'))->toBe('10:00')
        ->and($rosters->first()->ends_at->timezone('Africa/Accra')->format('H:i'))->toBe('14:00')
        ->and($rosters->pluck('starts_at')->map(fn ($at) => $at->timezone('Africa/Accra')->toDateString())->all())
        ->toBe(['2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05']);
});

test('generating the standard roster twice does not duplicate shifts', function () {
    $dentist = Dentist::factory()->create();
    $user = rosterUser(['view dentists', 'edit dentists']);

    foreach ([1, 2] as $attempt) {
        $this->actingAs($user)->post(route('dentists.rosters.generate'), [
            'from' => '2026-04-06',
            'weeks' => 1,
            'dentist_id' => $dentist->id,
        ])->assertRedirect();
    }

    // Easter Monday (6 April 2026) is a holiday, so the week has four shifts.
    expect(DentistRoster::query()->where('dentist_id', $dentist->id)->count())->toBe(4);
});

test('only roster managers can generate the standard roster', function () {
    $dentist = Dentist::factory()->create();

    $this->actingAs(rosterUser(['view dentists']))
        ->post(route('dentists.rosters.generate'), ['from' => '2026-03-02', 'weeks' => 1, 'dentist_id' => $dentist->id])
        ->assertForbidden();

    expect(DentistRoster::count())->toBe(0);
});

test('declared holiday observances control the roster rather than commemorative dates', function () {
    $calendar = app(ServiceCalendar::class);

    foreach (['2026-01-09', '2026-03-23', '2026-07-03', '2026-09-21'] as $date) {
        expect($calendar->isOpenAt(serviceAt($date.' 11:00')))->toBeFalse();
    }
    foreach (['2026-01-07', '2026-07-01', '2026-08-04'] as $date) {
        expect($calendar->isOpenAt(serviceAt($date.' 11:00')))->toBeTrue();
    }
});
