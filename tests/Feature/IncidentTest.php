<?php

use App\Models\CorrectiveAction;
use App\Models\Incident;
use App\Models\IncidentEvidenceAccess;
use App\Models\User;
use App\Services\IncidentRegister;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;

/** @param list<string> $permissions */
function incidentUser(array $permissions = ['view incidents']): User
{
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission);
    }
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function incidentManager(): User
{
    return incidentUser(['view incidents', 'report incidents', 'manage incidents', 'close incidents']);
}

/** @return array<string, mixed> */
function incidentPayload(array $overrides = []): array
{
    return [
        'reference' => 'INC-0001',
        'category' => 'safety',
        'severity' => 'high',
        'occurred_at' => now()->subHour()->toDateTimeString(),
        'summary' => 'Red-flag caller not escalated promptly',
        'description' => 'Caller reported uncontrolled bleeding and waited for a routine handover.',
        'immediate_action' => 'Supervisor contacted the caller and arranged emergency care.',
        ...$overrides,
    ];
}

test('incident routes require authentication and separate reporting, management and closure', function () {
    $this->get(route('incidents.index'))->assertRedirect(route('login'));

    $viewer = incidentUser();
    $this->actingAs($viewer)->get(route('incidents.index'))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $this->actingAs($viewer)->post(route('incidents.store'), incidentPayload())->assertForbidden();

    $reporter = incidentUser(['view incidents', 'report incidents']);
    $this->actingAs($reporter)->post(route('incidents.store'), incidentPayload())->assertRedirect();
    $incident = Incident::sole();
    $this->actingAs($reporter)->post(route('incidents.escalate', $incident), [
        'revision' => $incident->revision, 'severity' => 'critical', 'assigned_to' => $reporter->id,
    ])->assertForbidden();

    $manager = incidentManager();
    $this->actingAs($manager)->post(route('incidents.escalate', $incident), [
        'revision' => $incident->revision, 'severity' => 'critical', 'assigned_to' => $manager->id,
        'note' => 'Escalated to the clinical supervisor.',
    ])->assertRedirect();
});

test('incident references are unique and evidence is encrypted at rest', function () {
    $reporter = incidentUser(['view incidents', 'report incidents']);

    $this->actingAs($reporter)->post(route('incidents.store'), incidentPayload([
        'reference' => 'Safety Incident 0001',
        'occurred_at' => now()->addHour()->toDateTimeString(),
    ]))->assertSessionHasErrors('occurred_at');

    $this->actingAs($reporter)->post(route('incidents.store'), incidentPayload([
        'reference' => 'Safety Incident 0001',
    ]))->assertRedirect();
    $this->actingAs($reporter)->post(route('incidents.store'), incidentPayload([
        'reference' => 'Safety Incident 0001',
    ]))->assertSessionHasErrors('reference');

    $incident = Incident::sole();
    expect($incident->reference)->toBe('safety-incident-0001')
        ->and($incident->status)->toBe('open')
        ->and($incident->reported_by)->toBe($reporter->id);
    $stored = DB::table('incidents')->where('id', $incident->id)->value('description');
    expect($stored)->toBeString()->not->toContain('uncontrolled bleeding');
});

test('escalation names an owner and sets the severity SLA deadline', function () {
    $this->freezeTime();
    $reporter = incidentUser(['view incidents', 'report incidents']);
    $manager = incidentManager();
    $this->actingAs($reporter)->post(route('incidents.store'), incidentPayload())->assertRedirect();
    $incident = Incident::sole();

    $this->actingAs($manager)->post(route('incidents.escalate', $incident), [
        'revision' => 99, 'severity' => 'critical', 'assigned_to' => $manager->id,
    ])->assertSessionHasErrors('revision');

    $this->actingAs($manager)->post(route('incidents.escalate', $incident), [
        'revision' => $incident->revision, 'severity' => 'critical', 'assigned_to' => $manager->id,
        'note' => 'Clinical review requested.',
    ])->assertRedirect();

    $incident->refresh();
    expect($incident->status)->toBe('investigating')
        ->and($incident->severity)->toBe('critical')
        ->and($incident->assigned_to)->toBe($manager->id)
        ->and($incident->escalation_due_at?->timestamp)->toBe(now()->addHour()->timestamp)
        ->and($incident->escalated_at)->not->toBeNull();
});

test('evidence access is recorded and withheld from read-only viewers', function () {
    $manager = incidentManager();
    app(IncidentRegister::class)->register($manager, incidentPayload());
    $incident = Incident::sole();

    $this->actingAs(incidentUser())
        ->get(route('incidents.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Incidents/Index')
            ->where('incidents.data.0.detail', null)
            ->where('evidence', null));

    $this->actingAs(incidentUser(['view incidents', 'report incidents']))
        ->post(route('incidents.evidence', $incident), ['purpose' => 'Investigation review', 'attested' => true])
        ->assertForbidden();

    $this->actingAs($manager)->post(route('incidents.evidence', $incident), [
        'purpose' => 'Reviewing the call recording for the investigation.',
        'attested' => true,
    ])->assertRedirect();

    $access = IncidentEvidenceAccess::sole();
    expect($access->user_id)->toBe($manager->id)
        ->and($access->incident_id)->toBe($incident->id)
        ->and($access->purpose)->toContain('call recording');
    $this->assertDatabaseHas('activity_log', ['log_name' => 'incidents', 'description' => 'Incident evidence accessed']);
});

test('corrective actions need independent verification before a safety incident can resolve', function () {
    $manager = incidentManager();
    app(IncidentRegister::class)->register($manager, incidentPayload());
    $incident = Incident::sole();

    $this->actingAs($manager)->post(route('incidents.actions.store', $incident), [
        'description' => 'Rebrief the red-flag escalation sequence.',
        'owner_id' => $manager->id,
        'due_at' => now()->addDays(2)->toDateTimeString(),
    ])->assertRedirect();
    $action = CorrectiveAction::sole();
    expect($action->status)->toBe('open')
        ->and($action->owner_id)->toBe($manager->id);

    $this->actingAs($manager)->post(route('incidents.resolve', $incident), [
        'resolution_summary' => 'Rebrief completed and evidenced.',
    ])->assertSessionHasErrors('actions');

    $this->actingAs($manager)->patch(route('incidents.actions.complete', $action), [
        'completion_note' => 'Rebrief delivered to the full shift.',
    ])->assertRedirect();
    expect($action->refresh()->status)->toBe('completed');

    $this->actingAs($manager)->post(route('incidents.actions.verify', $action), [
        'verification_note' => 'Team knowledge check passed.',
    ])->assertSessionHasErrors('verified_by');

    $this->actingAs($manager)->post(route('incidents.resolve', $incident), [
        'resolution_summary' => 'Rebrief completed but not yet verified.',
    ])->assertSessionHasErrors('actions');

    $verifier = incidentUser(['view incidents', 'manage incidents']);
    $this->actingAs($verifier)->post(route('incidents.actions.verify', $action), [
        'verification_note' => 'Independent knowledge check passed with evidence on file.',
    ])->assertRedirect();
    expect($action->refresh()->status)->toBe('verified')
        ->and($action->verified_by)->toBe($verifier->id);

    $this->actingAs($manager)->post(route('incidents.resolve', $incident), [
        'resolution_summary' => 'Rebrief completed and verified with an independent knowledge check.',
    ])->assertRedirect();
    expect($incident->refresh()->status)->toBe('resolved')
        ->and($incident->resolved_at)->not->toBeNull();
});

test('incidents close only after resolution and every closure is audited', function () {
    $manager = incidentManager();
    app(IncidentRegister::class)->register($manager, incidentPayload(['category' => 'complaint', 'severity' => 'low']));
    $incident = Incident::sole();

    $this->actingAs($manager)->post(route('incidents.close', $incident), ['attested' => true])
        ->assertSessionHasErrors('status');

    $this->actingAs($manager)->post(route('incidents.resolve', $incident), [
        'resolution_summary' => 'Caller contacted, apology recorded and service recovery agreed.',
    ])->assertRedirect();
    expect($incident->refresh()->status)->toBe('resolved');

    $this->actingAs(incidentUser(['view incidents', 'report incidents']))
        ->post(route('incidents.close', $incident), ['attested' => true])
        ->assertForbidden();

    $this->actingAs($manager)->post(route('incidents.close', $incident), ['attested' => true])->assertRedirect();
    expect($incident->refresh()->status)->toBe('closed')
        ->and($incident->closed_by)->toBe($manager->id)
        ->and($incident->closed_at)->not->toBeNull();
    $this->assertDatabaseHas('activity_log', ['log_name' => 'incidents', 'description' => 'Incident closed']);
});

test('incident tabs filter by category and expose open counts', function () {
    $manager = incidentManager();
    $register = app(IncidentRegister::class);
    $register->register($manager, incidentPayload(['reference' => 'safety-one', 'category' => 'safety']));
    $register->register($manager, incidentPayload(['reference' => 'privacy-one', 'category' => 'privacy']));
    expect(Incident::query()->where('category', 'privacy')->count())->toBe(1);

    $this->actingAs($manager)
        ->get(route('incidents.index', ['tab' => 'privacy']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Incidents/Index')
            ->where('filters.tab', 'privacy')
            ->where('counts.privacy', 1)
            ->has('incidents.data', 1)
            ->where('incidents.data.0.reference', 'privacy-one')
            ->where('counts.safety', 1)
            ->where('canManage', true)
            ->where('canReport', true));
});
