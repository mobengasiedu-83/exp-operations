<?php

use App\Models\CallLeg;
use App\Models\Dentist;
use App\Models\DentistAvailability;
use App\Models\DentistCredential;
use App\Models\DentistNumber;
use App\Models\DentistRoster;
use App\Models\Interaction;
use App\Models\PbxConnection;
use App\Models\User;
use App\Services\DentistDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;

/** @param list<string> $permissions */
function expDentistUser(array $permissions = ['view dentists']): User
{
    foreach (array_unique([...$permissions, 'view dentists']) as $permission) {
        Permission::findOrCreate($permission);
    }

    $user = User::factory()->create();
    $user->givePermissionTo(array_unique([...$permissions, 'view dentists']));

    return $user;
}

function expDentistConnection(): PbxConnection
{
    return PbxConnection::create([
        'name' => config('services.exp_pbx.connection_name'),
        'host' => '127.0.0.1',
        'status' => 'connected',
        'last_heartbeat_at' => now(),
        'last_event_at' => now(),
    ]);
}

/** @param array<string, mixed> $attributes */
function expDentistLeg(PbxConnection $connection, string $channel, array $attributes = []): CallLeg
{
    $interaction = Interaction::create([
        'pbx_connection_id' => $connection->id,
        'correlation_key' => fake()->uuid(),
        'started_at' => now()->subMinutes(10),
    ]);

    return CallLeg::create(array_merge([
        'interaction_id' => $interaction->id,
        'pbx_connection_id' => $connection->id,
        'uniqueid' => fake()->unique()->uuid(),
        'channel' => $channel,
        'role' => 'mobile',
        'dial_started_at' => now()->subMinutes(5),
        'ringing_at' => now()->subMinutes(5),
    ], $attributes));
}

test('dentist directory routes require authentication and the dentist permission', function () {
    $urls = [
        route('dentists.index'),
        route('dentists.numbers'),
        route('dentists.credentials'),
        route('dentists.rosters'),
        route('dentists.availability'),
        route('dentists.performance'),
    ];

    foreach ($urls as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }

    $this->actingAs(User::factory()->create());

    foreach ($urls as $url) {
        $this->get($url)->assertForbidden();
    }

    $this->actingAs(expDentistUser());

    $this->get(route('dentists.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dentists/Index')
            ->where('summary.total', 0)
            ->where('summary.selectable', 0)
            ->where('canManage', false)
            ->has('filters.state')
            ->has('activityWindow.days'));
});

test('only operators with the edit permission can change the directory', function () {
    $dentist = Dentist::factory()->create();
    $this->actingAs(expDentistUser());

    $this->post(route('dentists.store'), ['name' => 'Dr Ama Mensah'])->assertForbidden();
    $this->post(route('dentists.numbers.store', $dentist), ['number' => '0241234567'])->assertForbidden();
    $this->delete(route('dentists.destroy', $dentist))->assertForbidden();

    $this->actingAs(expDentistUser(['edit dentists']));

    $this->post(route('dentists.store'), ['name' => 'Dr Ama Mensah'])->assertRedirect();
    $this->assertDatabaseHas('dentists', ['name' => 'Dr Ama Mensah', 'active' => true]);
});

test('numbers are normalised, de-duplicated and validated', function () {
    $this->actingAs(expDentistUser(['edit dentists']));
    $dentist = Dentist::factory()->create();
    $other = Dentist::factory()->create();

    $this->post(route('dentists.numbers.store', $dentist), [
        'number' => '024 123 4567',
        'label' => 'Primary mobile',
        'is_primary' => true,
    ])->assertRedirect();

    $this->assertDatabaseHas('dentist_numbers', [
        'dentist_id' => $dentist->id,
        'number' => '+233241234567',
        'original' => '024 123 4567',
        'is_primary' => true,
        'status' => 'approved',
    ]);

    $this->post(route('dentists.numbers.store', $other), ['number' => '+233 24 123 4567'])
        ->assertSessionHasErrors('number');

    $this->post(route('dentists.numbers.store', $other), ['number' => '204'])
        ->assertSessionHasErrors('number');

    $this->post(route('dentists.numbers.store', $other), ['number' => '0249999999'])
        ->assertSessionHasNoErrors();

    $primary = DentistNumber::query()->where('dentist_id', $dentist->id)->firstOrFail();

    $this->patch(route('dentists.numbers.update', [$dentist, $primary]), [
        'number' => '024 123 4567',
        'status' => 'retired',
    ])->assertRedirect();

    $this->assertDatabaseHas('dentist_numbers', ['id' => $primary->id, 'status' => 'retired', 'is_primary' => false]);
});

test('coverage separates rosters, overrides and inactive dentists', function () {
    $this->actingAs(expDentistUser());

    $rostered = Dentist::factory()->create(['name' => 'Dr Rostered']);
    DentistNumber::factory()->for($rostered)->primary()->create([
        'number' => '+233241111111',
        'original' => '0241111111',
    ]);
    DentistRoster::factory()->for($rostered)->create([
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHours(2),
    ]);

    $onBreak = Dentist::factory()->create(['name' => 'Dr Break']);
    DentistRoster::factory()->for($onBreak)->create([
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHours(2),
    ]);
    DentistAvailability::factory()->for($onBreak)->create([
        'status' => 'break',
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addMinutes(20),
    ]);

    $onLeave = Dentist::factory()->create(['name' => 'Dr Leave']);
    DentistRoster::factory()->for($onLeave)->create([
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHours(4),
    ]);
    DentistRoster::factory()->for($onLeave)->leave()->create([
        'starts_at' => now()->subHours(2),
        'ends_at' => now()->addHours(4),
    ]);

    $inactive = Dentist::factory()->inactive()->create(['name' => 'Dr Inactive']);

    $this->get(route('dentists.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dentists/Index')
            ->where('summary.rostered', 1)
            ->where('summary.on_break', 1)
            ->where('summary.on_leave', 1)
            ->where('summary.inactive', 1)
            ->where('summary.selectable', 1)
            ->where('dentists', function ($dentists) use ($rostered, $onBreak, $onLeave, $inactive): bool {
                $rows = collect($dentists)->keyBy('id');

                return $rows->get($rostered->id)['coverage_state'] === 'rostered'
                    && $rows->get($rostered->id)['selectable'] === true
                    && $rows->get($onBreak->id)['coverage_state'] === 'on_break'
                    && $rows->get($onBreak->id)['selectable'] === false
                    && $rows->get($onLeave->id)['coverage_state'] === 'on_leave'
                    && $rows->get($inactive->id)['coverage_state'] === 'inactive';
            }));
});

test('unknown mobile destinations are flagged while directory numbers match', function () {
    $connection = expDentistConnection();
    $dentist = Dentist::factory()->create();
    DentistNumber::factory()->for($dentist)->create([
        'number' => '+233241234567',
        'original' => '0241234567',
    ]);

    expDentistLeg($connection, 'PJSIP/trunk/0241234567-0000001', [
        'answered_at' => now()->subMinutes(4),
        'ended_at' => now()->subMinutes(3),
    ]);
    expDentistLeg($connection, 'PJSIP/trunk/0249999999-0000002', [
        'answered_at' => now()->subMinutes(4),
        'ended_at' => now()->subMinutes(3),
    ]);

    $this->actingAs(expDentistUser());

    $this->get(route('dentists.numbers'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dentists/Numbers')
            ->where('unmatched', function ($unmatched): bool {
                return count($unmatched) === 1
                    && $unmatched[0]['reason'] === 'unknown_number'
                    && $unmatched[0]['number'] === '+233249999999'
                    && $unmatched[0]['attempts'] === 1
                    && $unmatched[0]['display'] === '+233249*****99';
            }));

    $this->get(route('dentists.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('summary.unmatched', 1));
});

test('performance rolls up matched answer outcomes only', function () {
    $connection = expDentistConnection();
    $dentist = Dentist::factory()->create(['name' => 'Dr Performance']);
    DentistNumber::factory()->for($dentist)->create([
        'number' => '+233241234567',
        'original' => '0241234567',
    ]);

    expDentistLeg($connection, 'PJSIP/trunk/0241234567-0000001', [
        'dial_started_at' => now()->subMinutes(20),
        'ringing_at' => now()->subMinutes(20),
        'answered_at' => now()->subMinutes(20)->addSeconds(10),
        'ended_at' => now()->subMinutes(15),
    ]);
    expDentistLeg($connection, 'PJSIP/trunk/0241234567-0000002', [
        'dial_started_at' => now()->subMinutes(10),
        'ringing_at' => now()->subMinutes(10),
        'dial_status' => 'BUSY',
        'answered_at' => null,
        'ended_at' => now()->subMinutes(9),
    ]);
    expDentistLeg($connection, 'PJSIP/trunk/0249999999-0000003', [
        'dial_started_at' => now()->subMinutes(4),
        'answered_at' => now()->subMinutes(4)->addSeconds(5),
        'ended_at' => now()->subMinutes(3),
    ]);

    $this->actingAs(expDentistUser());

    $this->get(route('dentists.performance'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dentists/Performance')
            ->where('performance.summary.attempts', 2)
            ->where('performance.summary.answered', 1)
            ->where('performance.summary.failed', 1)
            ->where('performance.summary.unmatched_attempts', 1)
            ->where('performance.summary.answer_rate', 0.5)
            ->where('performance.rows', function ($rows): bool {
                return count($rows) === 1
                    && $rows[0]['attempts'] === 2
                    && $rows[0]['answered'] === 1
                    && $rows[0]['failed'] === 1
                    && $rows[0]['answer_rate'] === 0.5
                    && abs($rows[0]['avg_dial_to_answer_seconds'] - 10.0) < 0.1;
            }));
});

test('credentials report derived expiry states', function () {
    $dentist = Dentist::factory()->create();
    DentistCredential::factory()->for($dentist)->expired()->create([
        'type' => 'license',
        'number' => 'GD-1001',
    ]);
    DentistCredential::factory()->for($dentist)->create([
        'type' => 'registration',
        'number' => 'GD-1002',
        'expires_on' => now()->addYears(2)->toDateString(),
    ]);
    DentistCredential::factory()->for($dentist)->create([
        'type' => 'indemnity',
        'number' => 'GD-1003',
        'expires_on' => now()->addDays(10)->toDateString(),
    ]);

    $this->actingAs(expDentistUser());

    $this->get(route('dentists.credentials', ['state' => 'expired']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dentists/Credentials')
            ->has('credentials.data', 1)
            ->where('credentials.data.0.type', 'license')
            ->where('counts.expired', 1)
            ->where('counts.expiring', 1)
            ->where('counts.total', 3));

    $this->get(route('dentists.credentials', ['state' => 'valid']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('credentials.data', 1)
            ->where('credentials.data.0.type', 'registration'));
});

test('roster windows reject overlapping duty but allow leave', function () {
    $this->actingAs(expDentistUser(['edit dentists']));
    $dentist = Dentist::factory()->create();

    DentistRoster::factory()->for($dentist)->create([
        'starts_at' => now()->addHour(),
        'ends_at' => now()->addHours(5),
    ]);

    $this->post(route('dentists.rosters.store', $dentist), [
        'kind' => 'shift',
        'starts_at' => now()->addHours(2)->format('Y-m-d\TH:i'),
        'ends_at' => now()->addHours(6)->format('Y-m-d\TH:i'),
    ])->assertSessionHasErrors('starts_at');

    $this->post(route('dentists.rosters.store', $dentist), [
        'kind' => 'cover',
        'starts_at' => now()->addHours(6)->format('Y-m-d\TH:i'),
        'ends_at' => now()->addHours(8)->format('Y-m-d\TH:i'),
    ])->assertSessionHasNoErrors();

    $this->post(route('dentists.rosters.store', $dentist), [
        'kind' => 'leave',
        'starts_at' => now()->addHours(2)->format('Y-m-d\TH:i'),
        'ends_at' => now()->addHours(3)->format('Y-m-d\TH:i'),
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseCount('dentist_rosters', 3);
});

test('availability overrides end early and stay scoped to their dentist', function () {
    $this->actingAs(expDentistUser(['edit dentists']));
    $dentist = Dentist::factory()->create();
    $other = Dentist::factory()->create();

    $override = DentistAvailability::factory()->for($dentist)->create([
        'status' => 'break',
        'starts_at' => now()->subMinutes(10),
        'ends_at' => now()->addHour(),
    ]);

    $this->delete(route('dentists.availability.destroy', [$other, $override]))->assertNotFound();

    $this->delete(route('dentists.availability.destroy', [$dentist, $override]))->assertRedirect();

    expect($override->fresh()->ends_at->lessThanOrEqualTo(now()))->toBeTrue();
});

test('the seeded dental assistant can view and select a rostered dentist', function () {
    $this->seed();

    // The demo seed rosters every dentist inside the 10:00–14:00 service
    // window, so the "exactly one selectable dentist" assertion would depend on
    // the wall clock. Clear the demo rosters and keep only the fixture below.
    DentistRoster::query()->delete();

    $user = User::factory()->create();
    $user->assignRole('Dental Assistant');

    $dentist = Dentist::factory()->create(['name' => 'Dr Selectable']);
    DentistNumber::factory()->for($dentist)->primary()->create([
        'number' => '+233241234567',
        'original' => '0241234567',
    ]);
    DentistRoster::factory()->for($dentist)->create([
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHours(2),
    ]);

    $this->actingAs($user);

    $this->get(route('dentists.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dentists/Index')
            ->where('canManage', false)
            ->where('summary.selectable', 1)
            ->where('dentists', fn (Collection $dentists) => ($entry = $dentists->firstWhere('name', 'Dr Selectable')) !== null
                && $entry['selectable'] === true
                && $entry['numbers'][0]['formatted'] === '024 123 4567'));

    $this->post(route('dentists.store'), ['name' => 'Dr Intruder'])->assertForbidden();
});

test('invalid number edits preserve the existing primary number', function () {
    $this->actingAs(expDentistUser(['edit dentists']));
    $dentist = Dentist::factory()->create();
    $primary = DentistNumber::factory()->for($dentist)->primary()->create();
    $secondary = DentistNumber::factory()->for($dentist)->create();

    $this->patch(route('dentists.numbers.update', [$dentist, $secondary]), [
        'number' => '204',
        'status' => 'approved',
        'is_primary' => true,
    ])->assertSessionHasErrors('number');

    expect($primary->fresh()->is_primary)->toBeTrue();
    expect($secondary->fresh()->number)->toBe($secondary->number);
});

test('unapproved numbers cannot become primary', function (string $status) {
    $this->actingAs(expDentistUser(['edit dentists']));
    $dentist = Dentist::factory()->create();
    $primary = DentistNumber::factory()->for($dentist)->primary()->create();

    $this->post(route('dentists.numbers.store', $dentist), [
        'number' => '0241234567',
        'status' => $status,
        'is_primary' => true,
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseHas('dentist_numbers', ['number' => '+233241234567', 'status' => $status, 'is_primary' => false]);
    expect($primary->fresh()->is_primary)->toBeTrue();
})->with(['pending', 'retired']);

test('credential expiry can be recorded without an issue date and verification can be revoked', function () {
    $this->freezeTime();
    $this->actingAs(expDentistUser(['edit dentists']));
    $dentist = Dentist::factory()->create();
    $credential = DentistCredential::factory()->for($dentist)->create(['verified_at' => now()->subDay()]);

    $this->patch(route('dentists.credentials.update', [$dentist, $credential]), [
        'type' => $credential->type,
        'number' => $credential->number,
        'issued_on' => null,
        'expires_on' => '2027-12-31',
        'verified' => false,
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseHas('dentist_credentials', ['id' => $credential->id, 'verified_at' => null, 'expires_on' => '2027-12-31 00:00:00']);

    $this->patch(route('dentists.credentials.update', [$dentist, $credential]), [
        'type' => $credential->type,
        'number' => $credential->number,
        'issued_on' => '2028-01-01',
        'expires_on' => '2027-12-31',
    ])->assertSessionHasErrors('expires_on');
});

test('ending an expired override preserves its historical end time', function () {
    $this->freezeTime();
    $this->actingAs(expDentistUser(['edit dentists']));
    $dentist = Dentist::factory()->create();
    $override = DentistAvailability::factory()->for($dentist)->create([
        'starts_at' => now()->subHours(2),
        'ends_at' => now()->subHour(),
    ]);
    $endedAt = $override->ends_at;

    $this->delete(route('dentists.availability.destroy', [$dentist, $override]))->assertRedirect();

    expect($override->fresh()->ends_at->eq($endedAt))->toBeTrue();
});

test('directory edit payloads retain saved notes', function () {
    $this->actingAs(expDentistUser(['edit dentists']));
    $dentist = Dentist::factory()->create(['notes' => 'Contact through coordinator']);
    DentistNumber::factory()->for($dentist)->create(['notes' => 'Evening contact']);
    DentistCredential::factory()->for($dentist)->create(['notes' => 'Checked with issuer']);

    $this->get(route('dentists.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('dentists.0.notes', 'Contact through coordinator'));
    $this->get(route('dentists.numbers'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('numbers.data.0.notes', 'Evening contact'));
    $this->get(route('dentists.credentials'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('credentials.data.0.notes', 'Checked with issuer'));
});

test('coverage observes exact shift and override boundaries', function () {
    $this->freezeTime();
    $this->actingAs(expDentistUser());
    $dentist = Dentist::factory()->create();
    DentistNumber::factory()->for($dentist)->create();
    DentistRoster::factory()->for($dentist)->create([
        'kind' => 'cover', 'starts_at' => now(), 'ends_at' => now()->addHour(),
    ]);
    DentistAvailability::factory()->for($dentist)->create([
        'status' => 'unavailable', 'starts_at' => now(), 'ends_at' => now()->addMinutes(10),
    ]);

    $this->get(route('dentists.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('dentists.0.coverage_state', 'unavailable')->where('summary.selectable', 0));
    $this->travel(10)->minutes();
    $this->get(route('dentists.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('dentists.0.coverage_state', 'rostered')->where('summary.selectable', 1));
    $this->travel(50)->minutes();
    $this->get(route('dentists.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('dentists.0.coverage_state', 'not_rostered')->where('summary.selectable', 0));
});

test('activity windows exclude future legs and match hexadecimal channels', function () {
    $this->freezeTime();
    $connection = expDentistConnection();
    $dentist = Dentist::factory()->create();
    DentistNumber::factory()->for($dentist)->create(['number' => '+233241234567']);
    expDentistLeg($connection, 'PJSIP/trunk/0241234567-000000af');
    expDentistLeg($connection, 'PJSIP/trunk/0249999999-000000ab', ['dial_started_at' => now()->addHour()]);

    $this->actingAs(expDentistUser());

    $this->get(route('dentists.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('summary.unmatched', 0)->where('dentists.0.call_activity.attempts_24h', 1));
    $this->get(route('dentists.performance'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('performance.summary.attempts', 1)->where('performance.summary.unmatched_attempts', 0));
});

test('transfer selection exposes approved numbers and manual busy overrides', function () {
    $this->freezeTime();
    $dentist = Dentist::factory()->create(['name' => 'Dr Transfer']);
    DentistNumber::factory()->for($dentist)->primary()->create(['number' => '+233241234567']);
    DentistNumber::factory()->for($dentist)->pending()->create(['number' => '+233241234568']);
    $inactive = Dentist::factory()->inactive()->create(['name' => 'Dr Inactive']);
    DentistNumber::factory()->for($inactive)->primary()->create();

    $this->actingAs(expDentistUser());

    $this->getJson(route('dentists.selectable'))->assertOk()
        ->assertJsonPath('dentists.0.name', 'Dr Transfer')
        ->assertJsonPath('dentists.0.availability_status', 'available')
        ->assertJsonPath('dentists.0.availability_until', null)
        ->assertJsonCount(1, 'dentists.0.numbers')
        ->assertJsonPath('dentists.0.numbers.0.masked', '+233241****67')
        ->assertJsonCount(1, 'dentists');

    DentistAvailability::factory()->for($dentist)->create(['status' => 'busy', 'reason' => 'On another call']);

    $this->getJson(route('dentists.selectable'))->assertOk()
        ->assertJsonPath('dentists.0.availability_status', 'busy')
        ->assertJsonPath('dentists.0.availability_until', now()->addMinutes(20)->toIso8601String());

    $caseViewer = User::factory()->create();
    Permission::findOrCreate('view cases');
    $caseViewer->givePermissionTo('view cases');
    $this->actingAs($caseViewer);
    $this->getJson(route('dentists.selectable'))->assertForbidden();
});

test('historical coverage uses the same date for credential summary and counts', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00'));
    $dentist = Dentist::factory()->create();
    DentistCredential::factory()->for($dentist)->create(['expires_on' => '2026-11-01']);

    $coverage = app(DentistDirectory::class)->coverage(CarbonImmutable::parse('2026-09-01 12:00:00'));

    expect($coverage['dentists'][0]['credential_state'])->toBe('valid')
        ->and($coverage['dentists'][0]['credentials_expired'])->toBe(0)
        ->and($coverage['dentists'][0]['credentials_expiring'])->toBe(0);
});
