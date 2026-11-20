<?php

use App\Models\Callback;
use App\Models\CallerCase;
use App\Models\CaseAssignment;
use App\Models\Referral;
use App\Models\ScreeningProtocol;
use App\Models\ShiftHandover;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;

/** @param list<string> $permissions */
function followUpUser(array $permissions = ['view cases', 'edit cases', 'view callbacks', 'edit callbacks', 'view referrals', 'edit referrals']): User
{
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission);
    }
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function followUpCase(User $owner, string $priority = 'P3'): CallerCase
{
    return CallerCase::factory()->create(['assigned_to' => $owner->id, 'priority' => $priority]);
}

function followUpConsent(CallerCase $case, bool $callbackAllowed = true): void
{
    $case->consents()->create([
        'screening_protocol_id' => ScreeningProtocol::factory()->active()->create()->id,
        'recorded_by' => $case->assigned_to,
        'status' => 'granted',
        'callback_allowed' => $callbackAllowed,
        'recorded_at' => now(),
    ]);
}

/** @return array<string, mixed> */
function followUpBooking(CallerCase $case, User $owner, array $overrides = []): array
{
    return [
        'case_id' => $case->id,
        'assigned_to' => $owner->id,
        'priority' => 'routine',
        'reason' => 'Caller asked for a routine follow-up call.',
        'due_at' => now()->addDay()->setTime(9, 0)->toDateTimeString(),
        'window_start' => '08:00',
        'window_end' => '18:00',
        ...$overrides,
    ];
}

function followUpCallback(User $owner, CarbonImmutable $due, array $overrides = []): Callback
{
    $case = followUpCase($owner);
    followUpConsent($case);

    return Callback::factory()->create([
        'case_id' => $case->id,
        'consent_record_id' => $case->consents()->sole()->id,
        'assigned_to' => $owner->id,
        'due_at' => $due,
        ...$overrides,
    ]);
}

test('follow-up routes require authentication and separated callback and referral permissions', function () {
    foreach (['callbacks.index', 'referrals.index', 'cases.handover'] as $route) {
        $this->get(route($route))->assertRedirect(route('login'));
    }

    $unprivileged = followUpUser([]);
    $this->actingAs($unprivileged)->get(route('callbacks.index'))->assertForbidden();
    $this->actingAs($unprivileged)->get(route('referrals.index'))->assertForbidden();
    $this->actingAs($unprivileged)->get(route('cases.handover'))->assertForbidden();

    $case = followUpCase($unprivileged);
    followUpConsent($case);
    $this->actingAs(followUpUser(['view callbacks']))
        ->post(route('callbacks.store'), followUpBooking($case, $unprivileged))
        ->assertForbidden();
    $this->actingAs(followUpUser(['view referrals']))
        ->get(route('referrals.index'))
        ->assertOk();

    $this->actingAs(followUpUser(['view callbacks']))->get(route('callbacks.index'))->assertOk();
});

test('a callback is booked with an owner, due time, approved window and consent evidence', function () {
    $user = followUpUser();
    $owner = followUpUser();
    $case = followUpCase($user);
    followUpConsent($case);

    $this->actingAs($user)->post(route('callbacks.store'), followUpBooking($case, $owner))->assertRedirect();

    $callback = Callback::sole();
    expect($callback->status)->toBe('pending')
        ->and($callback->priority)->toBe('routine')
        ->and($callback->assigned_to)->toBe($owner->id)
        ->and($callback->booked_by)->toBe($user->id)
        ->and($callback->consent_record_id)->toBe($case->consents()->sole()->id)
        ->and($callback->window_start)->toStartWith('08:00')
        ->and($callback->window_end)->toStartWith('18:00')
        ->and($callback->reason)->toBe('Caller asked for a routine follow-up call.');
    $this->assertDatabaseMissing('callbacks', ['reason' => 'Caller asked for a routine follow-up call.']);
    $this->assertDatabaseHas('activity_log', ['description' => 'Callback booked']);
});

test('callbacks require granted consent with explicit callback permission', function () {
    $user = followUpUser();
    $case = followUpCase($user);

    $this->actingAs($user)->post(route('callbacks.store'), followUpBooking($case, $user))
        ->assertSessionHasErrors('consent_record_id');
    expect(Callback::count())->toBe(0);

    followUpConsent($case, callbackAllowed: false);
    $this->actingAs($user)->post(route('callbacks.store'), followUpBooking($case, $user))
        ->assertSessionHasErrors('consent_record_id');
    expect(Callback::count())->toBe(0);
});

test('emergency cases and outstanding urgent referrals never enter the callback queue', function () {
    $user = followUpUser();
    $emergency = followUpCase($user, 'P1');
    followUpConsent($emergency);

    $this->actingAs($user)->post(route('callbacks.store'), followUpBooking($emergency, $user))
        ->assertSessionHasErrors('case');

    $urgent = followUpCase($user, 'P2');
    followUpConsent($urgent);
    Referral::factory()->urgent()->create(['case_id' => $urgent->id, 'status' => 'pending']);

    $this->actingAs($user)->post(route('callbacks.store'), followUpBooking($urgent, $user))
        ->assertSessionHasErrors('case');
    expect(Callback::count())->toBe(0);
});

test('callbacks must fall inside the approved contact window and cannot duplicate an open callback', function () {
    $user = followUpUser();
    $case = followUpCase($user);
    followUpConsent($case);

    $this->actingAs($user)->post(route('callbacks.store'), followUpBooking($case, $user, [
        'due_at' => now()->addDay()->setTime(19, 0)->toDateTimeString(),
        'window_start' => '08:00',
        'window_end' => '18:00',
    ]))->assertSessionHasErrors('due_at');

    $this->actingAs($user)->post(route('callbacks.store'), followUpBooking($case, $user))->assertRedirect();
    $this->actingAs($user)->post(route('callbacks.store'), followUpBooking($case, $user))
        ->assertSessionHasErrors('case');
    expect(Callback::count())->toBe(1);
});

test('queue views separate pending, due and overdue callbacks and escalation is idempotent', function () {
    $user = followUpUser();
    followUpCallback($user, CarbonImmutable::now()->addHours(3));
    followUpCallback($user, CarbonImmutable::now()->subMinutes(10));
    $overdue = followUpCallback($user, CarbonImmutable::now()->subHours(3));

    $this->actingAs($user)->get(route('callbacks.index', ['view' => 'pending']))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Callbacks/Index')
            ->where('counts.pending', 1)->where('counts.due', 2)->where('counts.overdue', 1));
    $this->actingAs($user)->get(route('callbacks.index', ['view' => 'overdue']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('callbacks.data', 1)
            ->where('callbacks.data.0.id', $overdue->id)->where('counts.overdue', 1));

    $this->artisan('exp:escalate-callbacks')->assertSuccessful();
    expect($overdue->fresh()?->escalated_at)->not->toBeNull()
        ->and(Callback::query()->whereNotNull('escalated_at')->count())->toBe(1);

    $escalatedAt = $overdue->fresh()?->escalated_at;
    $this->artisan('exp:escalate-callbacks')->assertSuccessful();
    expect($overdue->fresh()?->escalated_at?->equalTo($escalatedAt))->toBeTrue();
});

test('callback attempts record outcomes and voicemail never stores free text', function () {
    $user = followUpUser();
    $callback = followUpCallback($user, CarbonImmutable::now()->subHours(2));

    $this->actingAs($user)->post(route('callbacks.attempts.store', $callback), [
        'outcome' => 'no_answer', 'note' => 'Rang out twice.',
    ])->assertRedirect();
    expect($callback->attempts()->sole()->note)->toBe('Rang out twice.');

    $this->actingAs($user)->post(route('callbacks.attempts.store', $callback), [
        'outcome' => 'voicemail', 'note' => 'Left clinical detail.',
    ])->assertSessionHasErrors('neutral_voicemail_confirmed');

    $this->actingAs($user)->post(route('callbacks.attempts.store', $callback), [
        'outcome' => 'voicemail', 'note' => 'Left clinical detail.', 'neutral_voicemail_confirmed' => true,
    ])->assertRedirect();
    $voicemail = $callback->attempts()->latest('id')->firstOrFail();
    expect($voicemail->note)->toBeNull()
        ->and($voicemail->neutral_voicemail_confirmed)->toBeTrue();

    $this->actingAs($user)->post(route('callbacks.attempts.store', $callback), ['outcome' => 'answered'])->assertRedirect();
    expect($callback->fresh()?->status)->toBe('in_progress')
        ->and($callback->fresh()?->escalated_at)->toBeNull();
});

test('callback completion requires an attempt, the current revision and an approved outcome', function () {
    $user = followUpUser();
    $callback = followUpCallback($user, CarbonImmutable::now()->subHours(2));

    $this->actingAs($user)->patch(route('callbacks.update', $callback), [
        'revision' => $callback->revision, 'outcome' => 'answered',
    ])->assertSessionHasErrors('outcome');

    $this->actingAs($user)->post(route('callbacks.attempts.store', $callback), ['outcome' => 'answered'])->assertRedirect();

    $this->actingAs($user)->patch(route('callbacks.update', $callback), [
        'revision' => 99, 'outcome' => 'answered', 'outcome_note' => 'Spoke with the caller.',
    ])->assertSessionHasErrors('revision');

    $this->actingAs($user)->patch(route('callbacks.update', $callback), [
        'revision' => $callback->fresh()?->revision, 'outcome' => 'answered', 'outcome_note' => 'Spoke with the caller.',
    ])->assertRedirect();

    $completed = $callback->fresh();
    expect($completed?->status)->toBe('completed')
        ->and($completed?->outcome)->toBe('answered')
        ->and($completed?->completed_by)->toBe($user->id)
        ->and($completed?->completed_at)->not->toBeNull()
        ->and($completed?->revision)->toBe(2);

    $this->actingAs($user)->get(route('callbacks.index', ['view' => 'attempts']))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Callbacks/Index')
            ->has('attempts.data', 1)->where('callbacks', null));
});

test('callbacks are scoped to visible cases and blocked for other operators', function () {
    $owner = followUpUser();
    $callback = followUpCallback($owner, CarbonImmutable::now()->addHours(2));

    $operator = followUpUser();

    $this->actingAs($operator)->get(route('callbacks.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('counts.pending', 0));
    $this->actingAs($operator)->post(route('callbacks.attempts.store', $callback), ['outcome' => 'answered'])
        ->assertNotFound();

    $supervisor = followUpUser(['view cases', 'edit cases', 'view all cases', 'view callbacks', 'edit callbacks']);
    $this->actingAs($supervisor)->get(route('callbacks.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('counts.pending', 1));
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function followUpReferral(CallerCase $case, array $overrides = []): array
{
    return [
        'case_id' => $case->id,
        'route' => 'routine',
        'destination_name' => 'Korle Bu Dental Clinic',
        'destination_contact' => '+233302000000',
        'reason' => 'Routine clinician follow-up agreed with the caller.',
        ...$overrides,
    ];
}

test('referrals keep emergency handling out of the queue and enforce route priority', function () {
    $user = followUpUser();

    $emergency = followUpCase($user, 'P1');
    followUpConsent($emergency);
    $this->actingAs($user)->post(route('referrals.store'), followUpReferral($emergency, ['route' => 'urgent']))
        ->assertSessionHasErrors('route');

    $urgent = followUpCase($user, 'P2');
    followUpConsent($urgent);
    $this->actingAs($user)->post(route('referrals.store'), followUpReferral($urgent))
        ->assertSessionHasErrors('route');
    $this->actingAs($user)->post(route('referrals.store'), followUpReferral($urgent, ['route' => 'urgent']))
        ->assertRedirect();
    expect(Referral::sole()->route)->toBe('urgent');

    $routine = followUpCase($user, 'P3');
    followUpConsent($routine);
    $this->actingAs($user)->post(route('referrals.store'), followUpReferral($routine, ['route' => 'urgent']))
        ->assertSessionHasErrors('route');
    $this->actingAs($user)->post(route('referrals.store'), followUpReferral($routine))->assertRedirect();
    expect(Referral::count())->toBe(2);
});

test('referrals require granted consent and forbid duplicate open referrals on the same route', function () {
    $user = followUpUser();
    $case = followUpCase($user);

    $this->actingAs($user)->post(route('referrals.store'), followUpReferral($case))
        ->assertSessionHasErrors('consent_record_id');

    followUpConsent($case);
    $this->actingAs($user)->post(route('referrals.store'), followUpReferral($case))->assertRedirect();
    $this->actingAs($user)->post(route('referrals.store'), followUpReferral($case))
        ->assertSessionHasErrors('route');
    expect(Referral::count())->toBe(1);
});

test('referral action and outcome record evidence with optimistic concurrency', function () {
    $user = followUpUser();
    $case = followUpCase($user);
    followUpConsent($case);
    $referral = Referral::factory()->create(['case_id' => $case->id, 'referred_by' => $user->id]);

    $this->actingAs($user)->patch(route('referrals.action', $referral), [
        'revision' => $referral->revision, 'action_reference' => 'OPD-2026-118',
    ])->assertRedirect();
    $actioned = $referral->fresh();
    expect($actioned?->status)->toBe('actioned')
        ->and($actioned?->action_reference)->toBe('OPD-2026-118')
        ->and($actioned?->actioned_at)->not->toBeNull()
        ->and($actioned?->revision)->toBe(2);

    $this->actingAs($user)->patch(route('referrals.outcome', $referral), [
        'revision' => 1, 'outcome' => 'appointment_booked', 'outcome_note' => 'Appointment booked for next week.',
    ])->assertSessionHasErrors('revision');

    $this->actingAs($user)->patch(route('referrals.outcome', $referral), [
        'revision' => $actioned?->revision, 'outcome' => 'appointment_booked', 'outcome_note' => 'Appointment booked for next week.',
    ])->assertRedirect();
    $outcome = $referral->fresh();
    expect($outcome?->status)->toBe('outcome_recorded')
        ->and($outcome?->outcome)->toBe('appointment_booked')
        ->and($outcome?->outcome_recorded_at)->not->toBeNull();

    $this->actingAs($user)->get(route('referrals.index', ['view' => 'outcomes']))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Referrals/Index')
            ->has('referrals.data', 1)->where('counts.outcomes', 1));
});

test('the referral directory summarises known destinations for visible cases', function () {
    $user = followUpUser();
    $case = followUpCase($user);
    $other = followUpCase($user);

    Referral::factory()->create(['case_id' => $case->id, 'destination_name' => 'Korle Bu Dental Clinic']);
    Referral::factory()->urgent()->create(['case_id' => $other->id, 'destination_name' => 'Korle Bu Dental Clinic']);
    Referral::factory()->create(['case_id' => $other->id, 'destination_name' => 'Ridge Hospital Dental Unit']);

    $this->actingAs($user)->get(route('referrals.index', ['view' => 'directory']))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Referrals/Index')
            ->has('destinations', 2)
            ->where('destinations.0.destination_name', 'Korle Bu Dental Clinic')
            ->where('destinations.0.referrals', 2)
            ->where('destinations.0.urgent', 1));
});

test('assigning a case records ownership, next action and closes the previous assignment', function () {
    $supervisor = followUpUser(['view cases', 'edit cases', 'view all cases']);
    $case = followUpCase($supervisor);
    $first = followUpUser();
    $second = followUpUser();
    $payload = [
        'assigned_to' => $first->id,
        'next_action' => 'Call the caller with the appointment details.',
        'due_at' => now()->addHours(2)->toDateTimeString(),
    ];

    $this->actingAs($supervisor)->post(route('cases.assignments.store', $case), $payload)->assertRedirect();
    $assignment = CaseAssignment::sole();
    expect($assignment->ended_at)->toBeNull()
        ->and($assignment->assigned_to)->toBe($first->id)
        ->and($case->fresh()?->assigned_to)->toBe($first->id);

    $this->actingAs($supervisor)->get(route('cases.assignments'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Cases/Assignments')
            ->has('cases.data', 1)
            ->where('cases.data.0.next_action', 'Call the caller with the appointment details.')
            ->where('cases.data.0.owner', $first->name));

    $this->actingAs($supervisor)->post(route('cases.assignments.store', $case), [...$payload, 'assigned_to' => $second->id])
        ->assertRedirect();
    expect($assignment->fresh()?->ended_at)->not->toBeNull()
        ->and($assignment->fresh()?->ended_reason)->toBe('reassigned')
        ->and(CaseAssignment::query()->whereNull('ended_at')->sole()->assigned_to)->toBe($second->id)
        ->and($case->fresh()?->assigned_to)->toBe($second->id);
});

test('shift handover is blocked until every unresolved case has a due next action', function () {
    $user = followUpUser();
    $case = followUpCase($user);
    $shift = [
        'shift_started_at' => now()->subHours(8)->toDateTimeString(),
        'shift_ended_at' => now()->subMinute()->toDateTimeString(),
        'notes' => 'Outstanding callbacks and referrals listed for the incoming owner.',
    ];

    $this->actingAs($user)->post(route('cases.handover.store'), $shift)
        ->assertSessionHasErrors('unresolved_cases');
    expect(ShiftHandover::count())->toBe(0);

    $this->actingAs($user)->post(route('cases.assignments.store', $case), [
        'assigned_to' => $user->id,
        'next_action' => 'Call the caller after 09:00.',
        'due_at' => now()->addHours(3)->toDateTimeString(),
    ])->assertRedirect();

    $this->actingAs($user)->post(route('cases.handover.store'), $shift)->assertRedirect();

    $handover = ShiftHandover::sole();
    expect($handover->unresolved_cases)->toHaveCount(1)
        ->and($handover->unresolved_cases[0]['case_id'])->toBe($case->id)
        ->and($handover->unresolved_cases[0]['owner_id'])->toBe($user->id)
        ->and($handover->acknowledged_at)->toBeNull();
    $this->assertDatabaseHas('activity_log', ['description' => 'Shift handover recorded']);
});

test('shift handover records incoming acceptance and stays scoped to the outgoing operator', function () {
    $outgoing = followUpUser();
    $incoming = followUpUser();
    $case = followUpCase($outgoing);

    $this->actingAs($outgoing)->post(route('cases.assignments.store', $case), [
        'assigned_to' => $outgoing->id,
        'next_action' => 'Call the caller after 09:00.',
        'due_at' => now()->addHours(3)->toDateTimeString(),
    ])->assertRedirect();

    $this->actingAs($outgoing)->post(route('cases.handover.store'), [
        'shift_started_at' => now()->subHours(8)->toDateTimeString(),
        'shift_ended_at' => now()->subMinute()->toDateTimeString(),
        'incoming_user_id' => $incoming->id,
        'notes' => 'Incoming owner briefed on the outstanding callback.',
        'acknowledged' => true,
    ])->assertRedirect();

    $handover = ShiftHandover::sole();
    expect($handover->incoming_user_id)->toBe($incoming->id)
        ->and($handover->acknowledged_at)->not->toBeNull();

    $other = followUpUser();
    $this->actingAs($other)->get(route('cases.handover'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Cases/Handover')
            ->has('handovers', 0)->has('unresolved', 0));
    $this->actingAs($outgoing)->get(route('cases.handover'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Cases/Handover')
            ->has('handovers', 1)->has('unresolved', 1)
            ->where('ready', true)
            ->where('unresolved.0.next_action', 'Call the caller after 09:00.')
            ->where('unresolved.0.overdue', false));
});
