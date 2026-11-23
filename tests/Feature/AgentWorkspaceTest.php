<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\CallerCase;
use App\Models\CallLeg;
use App\Models\Certification;
use App\Models\Dentist;
use App\Models\DentistNumber;
use App\Models\Interaction;
use App\Models\IvrStep;
use App\Models\PbxConnection;
use App\Models\ScreeningAnswer;
use App\Models\ScreeningDraft;
use App\Models\ScreeningProtocol;
use App\Models\ScreeningQuestion;
use App\Models\User;
use Database\Seeders\ScreeningProtocolSeeder;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** @param list<string> $permissions */
function workspaceUser(array $permissions = ['view cases', 'edit cases']): User
{
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission);
    }
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function workspaceProtocol(bool $active = true): ScreeningProtocol
{
    $factory = ScreeningProtocol::factory();
    $protocol = ($active ? $factory->active() : $factory->draft())->create();
    foreach (['P1', 'P2'] as $position => $priority) {
        $protocol->questions()->create(['prompt' => 'Simulation '.$priority.' question', 'positive_priority' => $priority, 'position' => $position]);
    }

    return $protocol->load('questions');
}

function workspaceCase(User $user): CallerCase
{
    $case = CallerCase::factory()->create(['assigned_to' => $user->id]);
    $case->interaction->update(['is_exp' => true]);
    workspaceOptionThree($case->interaction);

    return $case;
}

function workspaceOptionThree(Interaction $interaction): void
{
    IvrStep::factory()->create(['interaction_id' => $interaction->id, 'option' => '3']);
}

/** @param array<string, mixed> $data */
function workspaceChange(CallerCase $case, string $operation, array $data): TestResponse
{
    return test()->patch(route('workspace.update', $case), ['operation' => $operation, 'revision' => $case->fresh()->revision, ...$data]);
}

/** @return array<string, mixed> */
function workspaceProfile(): array
{
    return [
        'preferred_name' => 'Caller preferred name', 'callback_number' => '0241234567', 'age_band' => 'adult',
        'guardian_confirmed' => false, 'contact_kind' => 'genuine', 'concern_type' => 'clinical',
        'concern' => 'Caller-reported concern', 'onset' => 'Two days', 'impact' => 'Caller reports interrupted sleep',
    ];
}

/** @return array<string, mixed> */
function workspaceAnswers(ScreeningProtocol $protocol, string $priority = 'P3', string $emergency = 'no', string $urgent = 'no'): array
{
    return ['protocol_id' => $protocol->id, 'priority' => $priority, 'answers' => [
        ['question_id' => $protocol->questions[0]->id, 'answer' => $emergency],
        ['question_id' => $protocol->questions[1]->id, 'answer' => $urgent],
    ]];
}

test('workspace history encryption follows the request scheme without allowing response caching', function (string $scheme, bool $encrypted) {
    $this->actingAs(workspaceUser());

    $response = $this->get($scheme.'://localhost/workspace', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
    ]);

    $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    if ($encrypted) {
        $response->assertJsonPath('encryptHistory', true);
    } else {
        $response->assertJsonMissingPath('encryptHistory');
    }
})->with([['http', false], ['https', true]]);

test('workspace routes require authentication and case permissions', function () {
    $case = CallerCase::factory()->create();
    $this->get(route('workspace.index'))->assertRedirect(route('login'));
    $this->get(route('workspace.show', $case))->assertRedirect(route('login'));
    $this->actingAs(workspaceUser([]));
    $this->get(route('workspace.index'))->assertForbidden();
    $this->get(route('workspace.show', $case))->assertForbidden();
    $this->post(route('workspace.store'), [])->assertForbidden();
    $this->patch(route('workspace.update', $case), [])->assertForbidden();
});

test('cases are isolated to owners and explicit clinical reviewers', function () {
    $owner = workspaceUser();
    $case = workspaceCase($owner);
    $this->actingAs(workspaceUser());
    $this->get(route('workspace.show', $case))->assertNotFound();
    workspaceChange($case, 'note', ['body' => 'Unauthorised'])->assertNotFound();
    $this->get(route('cases.index'))->assertInertia(fn (AssertableInertia $page) => $page->has('cases.data', 0));

    $this->actingAs(workspaceUser(['view cases', 'view all cases']));
    $this->get(route('workspace.show', $case))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    workspaceChange($case, 'note', ['body' => 'Read-only reviewer'])->assertForbidden();
    $this->assertDatabaseCount('case_notes', 0);
});

test('opening a case requires verified EXP evidence and an explicit operator confirmation', function () {
    $this->freezeTime();
    $user = workspaceUser();
    $this->actingAs($user);
    $interaction = Interaction::factory()->create(['is_exp' => true]);
    workspaceOptionThree($interaction);
    $this->post(route('workspace.store'), ['interaction_id' => $interaction->id, 'association_confirmed' => true])->assertForbidden();
    Permission::findOrCreate('authorise live calls');
    $user->givePermissionTo('authorise live calls');
    $this->post(route('workspace.store'), ['interaction_id' => $interaction->id])->assertSessionHasErrors('association_confirmed');
    $interaction->update(['is_exp' => false]);
    $this->post(route('workspace.store'), ['interaction_id' => $interaction->id, 'association_confirmed' => true])->assertUnprocessable();
    $interaction->update(['is_exp' => true]);

    $this->post(route('workspace.store'), ['interaction_id' => $interaction->id, 'association_confirmed' => true])->assertRedirect();
    $case = CallerCase::query()->sole();
    expect($case->interaction_id)->toBe($interaction->id)->and($case->assigned_to)->toBe($user->id)->and($case->association_verified_by)->toBe($user->id);
    $this->post(route('workspace.store'), ['interaction_id' => $interaction->id, 'association_confirmed' => true])->assertRedirect(route('workspace.show', $case));
    $this->assertDatabaseCount('cases', 1);
    $this->actingAs(workspaceUser());
    $this->post(route('workspace.store'), ['interaction_id' => $interaction->id, 'association_confirmed' => true])->assertForbidden();
    $other = workspaceUser();
    $other->givePermissionTo('authorise live calls');
    $this->actingAs($other);
    $this->post(route('workspace.store'), ['interaction_id' => $interaction->id, 'association_confirmed' => true])->assertNotFound();
});

test('a current live-call certification opens a case without the supervisor override', function () {
    $user = workspaceUser();
    $this->actingAs($user);
    $interaction = Interaction::factory()->create(['is_exp' => true]);
    workspaceOptionThree($interaction);
    $this->post(route('workspace.store'), ['interaction_id' => $interaction->id, 'association_confirmed' => true])->assertForbidden();

    Certification::create([
        'user_id' => $user->id,
        'status' => 'active',
        'issued_at' => now()->subMonth()->toDateString(),
        'expires_at' => now()->addMonths(11)->toDateString(),
        'authorised_by' => $user->id,
        'reference' => 'CERT-WORKSPACE-0001',
        'curriculum_snapshot' => [['day' => 1, 'title' => 'Curriculum day one']],
        'revision' => 1,
    ]);

    CallLeg::create(['interaction_id' => $interaction->id, 'pbx_connection_id' => $interaction->pbx_connection_id, 'uniqueid' => 'agent-certified', 'role' => 'agent', 'channel' => 'PJSIP/204-00000002', 'observed_creation' => true]);

    $this->post(route('workspace.store'), ['interaction_id' => $interaction->id, 'association_confirmed' => true])->assertRedirect();
    expect(CallerCase::query()->sole()->assigned_to)->toBe($user->id);
});

test('agents with the create cases permission open a case and record details without a certification', function () {
    $user = workspaceUser(['view cases', 'create cases', 'edit cases']);
    $this->actingAs($user);
    $interaction = Interaction::factory()->create(['is_exp' => true]);
    workspaceOptionThree($interaction);
    CallLeg::create(['interaction_id' => $interaction->id, 'pbx_connection_id' => $interaction->pbx_connection_id, 'uniqueid' => 'agent-open', 'role' => 'agent', 'channel' => 'PJSIP/204-00000003', 'observed_creation' => true]);

    $this->get(route('workspace.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canOpenCase', true));

    $this->post(route('workspace.store'), ['interaction_id' => $interaction->id, 'association_confirmed' => true])->assertRedirect();
    $case = CallerCase::query()->sole();
    expect($case->assigned_to)->toBe($user->id);

    workspaceChange($case, 'profile', workspaceProfile())->assertSessionHasNoErrors();
    workspaceChange($case, 'note', ['body' => 'Agent recorded detail'])->assertSessionHasNoErrors();

    expect($case->fresh()->callback_number)->toBe('+233241234567')
        ->and($case->notes()->count())->toBe(1);
});

test('the seeded agent role opens a case from a recorded call', function () {
    $this->seed();

    $agent = User::factory()->create();
    $agent->assignRole('Agent');
    $this->actingAs($agent);

    expect($agent->can('view cases'))->toBeTrue()
        ->and($agent->can('create cases'))->toBeTrue()
        ->and($agent->can('edit cases'))->toBeTrue();

    $interaction = Interaction::factory()->create(['is_exp' => true]);
    workspaceOptionThree($interaction);
    CallLeg::create(['interaction_id' => $interaction->id, 'pbx_connection_id' => $interaction->pbx_connection_id, 'uniqueid' => 'agent-role-open', 'role' => 'agent', 'channel' => 'PJSIP/204-00000004', 'observed_creation' => true]);

    $this->get(route('workspace.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Workspace/Index')
            ->where('canEdit', true)
            ->where('canOpenCase', true));

    $this->post(route('workspace.store'), ['interaction_id' => $interaction->id, 'association_confirmed' => true])->assertRedirect();

    $case = CallerCase::query()->sole();
    expect($case->interaction_id)->toBe($interaction->id)
        ->and($case->assigned_to)->toBe($agent->id)
        ->and($case->association_verified_by)->toBe($agent->id);
    $this->get(route('workspace.show', $case))->assertOk();
});

test('personal details and notes save without consent and are encrypted at rest', function () {
    $user = workspaceUser();
    $this->actingAs($user);
    $case = workspaceCase($user);
    $protocol = workspaceProtocol();
    workspaceChange($case, 'profile', workspaceProfile())->assertSessionHasNoErrors();
    workspaceChange($case, 'consent', ['protocol_id' => $protocol->id, 'status' => 'granted', 'callback_allowed' => true])->assertSessionHasNoErrors();
    workspaceChange($case, 'profile', workspaceProfile())->assertSessionHasNoErrors();
    expect($case->fresh()->callback_number)->toBe('+233241234567')
        ->and($case->fresh()->getRawOriginal('callback_number'))->not->toContain('241234567')
        ->and($case->fresh()->getRawOriginal('concern'))->not->toContain('Caller-reported concern');
    workspaceChange($case, 'note', ['body' => 'Caller-reported factual note'])->assertSessionHasNoErrors();
    $saved = $case->notes()->sole();
    expect($saved->body)->toBe('Caller-reported factual note')->and($saved->getRawOriginal('body'))->not->toContain('Caller-reported factual note');
});

test('historical consent does not gate current case details or notes', function () {
    $user = workspaceUser();
    $this->actingAs($user);
    $case = workspaceCase($user);
    $protocol = workspaceProtocol();
    workspaceChange($case, 'consent', ['protocol_id' => $protocol->id, 'status' => 'declined', 'callback_allowed' => true])->assertSessionHasErrors('callback_allowed');
    workspaceChange($case, 'consent', ['protocol_id' => $protocol->id, 'status' => 'granted', 'callback_allowed' => false])->assertSessionHasNoErrors();
    workspaceChange($case, 'profile', workspaceProfile())->assertSessionHasNoErrors();
    workspaceChange($case, 'consent', ['protocol_id' => $protocol->id, 'status' => 'withdrawn', 'callback_allowed' => false])->assertSessionHasNoErrors();
    workspaceChange($case, 'note', ['body' => 'Updated reported fact'])->assertSessionHasNoErrors();
    workspaceChange($case, 'profile', workspaceProfile())->assertSessionHasNoErrors();
    $this->assertDatabaseCount('consent_records', 2);
    $this->assertDatabaseCount('case_notes', 1);
});

test('child details require an approved guardian flow and confirmed relationship', function () {
    $user = workspaceUser();
    $this->actingAs($user);
    $case = workspaceCase($user);
    $protocol = workspaceProtocol();
    workspaceChange($case, 'consent', ['protocol_id' => $protocol->id, 'status' => 'granted', 'callback_allowed' => true]);
    $profile = [...workspaceProfile(), 'age_band' => 'child'];
    workspaceChange($case, 'profile', $profile)->assertSessionHasErrors('age_band');
    $protocol->update(['allow_guardian' => true]);
    workspaceChange($case, 'profile', $profile)->assertSessionHasErrors('guardian_name');
    workspaceChange($case, 'profile', [...$profile, 'guardian_name' => 'Guardian', 'guardian_relationship' => 'Parent', 'guardian_confirmed' => true])->assertSessionHasNoErrors();
    expect($case->fresh()->guardian_confirmed)->toBeTrue()->and($case->fresh()->guardian_name)->toBe('Guardian');
});

test('screening routes emergency urgent routine FAQ and unclear simulations safely', function (string $requested, string $emergency, string $urgent, string $expected) {
    $this->freezeTime();
    $user = workspaceUser();
    $this->actingAs($user);
    $case = workspaceCase($user);
    $case->update(['concern_type' => 'service_information', 'contact_kind' => 'nuisance']);
    $protocol = workspaceProtocol();

    workspaceChange($case, 'screening', workspaceAnswers($protocol, $requested, $emergency, $urgent))->assertSessionHasNoErrors();

    expect($case->fresh()->priority)->toBe($expected)
        ->and($case->fresh()->status)->toBe($expected === 'Review' ? 'clinical_review' : 'open');
    $this->assertDatabaseHas('screenings', ['case_id' => $case->id, 'priority' => $expected]);
    $this->assertDatabaseCount('screening_answers', 2);
})->with([
    'emergency overrides nuisance FAQ request' => ['S', 'yes', 'no', 'P1'],
    'urgent overrides routine request' => ['P3', 'no', 'yes', 'P2'],
    'routine' => ['P3', 'no', 'no', 'P3'],
    'service FAQ' => ['S', 'no', 'no', 'S'],
    'uncertain answer' => ['P3', 'unknown', 'no', 'Review'],
]);

test('emergency escalation can interrupt screening and never requires dentist availability or personal details', function () {
    $user = workspaceUser();
    $this->actingAs($user);
    $case = workspaceCase($user);
    $protocol = workspaceProtocol();
    $data = workspaceAnswers($protocol, 'P3', 'yes');
    array_pop($data['answers']);
    workspaceChange($case, 'screening', $data)->assertSessionHasNoErrors();
    workspaceChange($case, 'close', ['disposition' => 'routine_handover', 'next_step' => 'Routine'])->assertSessionHasErrors('disposition');
    workspaceChange($case, 'close', ['disposition' => 'emergency_escalation', 'next_step' => 'Simulation action'])->assertSessionHasErrors('emergency_action_confirmed');
    workspaceChange($case, 'close', ['disposition' => 'emergency_escalation', 'next_step' => 'Simulation action and notification recorded', 'emergency_action_confirmed' => true, 'supervisor_notified' => true])->assertSessionHasNoErrors();
    expect($case->fresh()->status)->toBe('closed')->and($case->fresh()->closed_by)->toBe($user->id);
    $audit = Activity::query()->where('subject_type', CallerCase::class)
        ->where('subject_id', $case->id)->where('properties->operation', 'close')->sole();
    expect($audit->properties->get('emergency_action_confirmed'))->toBeTrue()
        ->and($audit->properties->get('supervisor_notified'))->toBeTrue();
    workspaceChange($case, 'screening', workspaceAnswers($protocol))->assertSessionHasErrors('case');
});

test('ordinary callers cannot downgrade an earlier emergency and reviewer reassessment preserves history', function () {
    $user = workspaceUser();
    $this->actingAs($user);
    $case = workspaceCase($user);
    $protocol = workspaceProtocol();
    workspaceChange($case, 'screening', workspaceAnswers($protocol, 'P1', 'yes'));
    workspaceChange($case, 'screening', workspaceAnswers($protocol))->assertSessionHasErrors('priority');
    $this->assertDatabaseCount('screenings', 1);
    $this->actingAs(workspaceUser(['view cases', 'edit cases', 'view all cases', 'review cases']));
    workspaceChange($case, 'screening', workspaceAnswers($protocol))->assertSessionHasErrors('priority');
    workspaceChange($case, 'screening', [...workspaceAnswers($protocol), 'review_reason' => 'Simulation: clinician confirmed corrected reported information'])->assertSessionHasNoErrors();
    $this->assertDatabaseCount('screenings', 2);
    expect($case->fresh()->priority)->toBe('P3');
    $this->assertDatabaseCount('case_notes', 1);
});

test('draft and retired protocols and foreign questions cannot be used for screening', function () {
    $user = workspaceUser();
    $this->actingAs($user);
    $case = workspaceCase($user);
    $draft = workspaceProtocol(false);
    workspaceChange($case, 'screening', workspaceAnswers($draft))->assertSessionHasErrors('protocol_id');
    workspaceChange($case, 'consent', ['protocol_id' => $draft->id, 'status' => 'granted', 'callback_allowed' => false])->assertSessionHasErrors('protocol_id');
    $approved = workspaceProtocol();
    $data = workspaceAnswers($approved);
    $data['answers'][0]['question_id'] = $draft->questions[0]->id;
    workspaceChange($case, 'screening', $data)->assertSessionHasErrors('answers');
    $approved->update(['status' => 'retired']);
    workspaceChange($case, 'screening', workspaceAnswers($approved))->assertSessionHasErrors('protocol_id');
    $this->assertDatabaseCount('screenings', 0);
});

test('incomplete screens and clinical FAQ resolutions are rejected', function () {
    $user = workspaceUser();
    $this->actingAs($user);
    $case = workspaceCase($user);
    $protocol = workspaceProtocol();
    $data = workspaceAnswers($protocol);
    array_pop($data['answers']);
    workspaceChange($case, 'screening', $data)->assertSessionHasErrors('answers');
    workspaceChange($case, 'screening', workspaceAnswers($protocol, 'S'))->assertSessionHasErrors('priority');
    $this->assertDatabaseCount('screenings', 0);
});

test('routine closure requires safety concern and an appropriate disposition without consent', function () {
    $user = workspaceUser();
    $this->actingAs($user);
    $case = workspaceCase($user);
    $protocol = workspaceProtocol();
    $closure = ['disposition' => 'routine_handover', 'next_step' => 'Simulation clinician handover recorded'];
    workspaceChange($case, 'close', $closure)->assertSessionHasErrors('screening');
    workspaceChange($case, 'screening', workspaceAnswers($protocol));
    workspaceChange($case, 'close', $closure)->assertSessionHasErrors('concern');
    workspaceChange($case, 'profile', workspaceProfile())->assertSessionHasNoErrors();
    workspaceChange($case, 'close', [...$closure, 'disposition' => 'arbitrary'])->assertSessionHasErrors('disposition');
    workspaceChange($case, 'close', $closure)->assertSessionHasNoErrors();
    expect($case->fresh()->status)->toBe('closed')->and($case->fresh()->closed_at)->not->toBeNull();
});

test('unknown answers remain in clinical review instead of closing as contact ended', function () {
    $user = workspaceUser();
    $this->actingAs($user);
    $case = workspaceCase($user);
    $protocol = workspaceProtocol();
    workspaceChange($case, 'screening', workspaceAnswers($protocol, 'P3', 'unknown'));
    workspaceChange($case, 'close', ['disposition' => 'contact_ended', 'next_step' => 'Supervisor review requested after a silent call'])->assertSessionHasNoErrors();
    expect($case->fresh()->status)->toBe('clinical_review')->and($case->fresh()->closed_at)->toBeNull();
});

test('stale case revisions do not overwrite newer records', function () {
    $user = workspaceUser();
    $this->actingAs($user);
    $case = workspaceCase($user);
    $protocol = workspaceProtocol();
    workspaceChange($case, 'consent', ['protocol_id' => $protocol->id, 'status' => 'granted', 'callback_allowed' => false]);
    $this->patch(route('workspace.update', $case), ['operation' => 'note', 'revision' => 1, 'body' => 'Stale write'])->assertSessionHasErrors('revision');
    $this->assertDatabaseCount('case_notes', 0);
});

test('only authorised managers activate a questionnaire and activation retires the previous version', function () {
    $current = workspaceProtocol();
    $next = workspaceProtocol(false);
    $this->actingAs(workspaceUser());
    $this->get(route('screening-protocols.index'))->assertForbidden();
    $this->post(route('screening-protocols.activate', $next))->assertForbidden();

    $this->actingAs(workspaceUser(['manage screening protocols']));
    $this->post(route('screening-protocols.activate', $next))->assertSessionHasNoErrors();

    expect($next->fresh()->isActive())->toBeTrue()
        ->and($current->fresh()->isActive())->toBeFalse()
        ->and($current->fresh()->status)->toBe('retired')
        ->and(ScreeningProtocol::query()->active()->count())->toBe(1);
});

test('a retired questionnaire cannot be reactivated and consent needs the active version', function () {
    $retired = workspaceProtocol(false);
    $active = workspaceProtocol();
    $retired->update(['status' => 'retired']);
    $this->actingAs(workspaceUser(['manage screening protocols']));
    $this->post(route('screening-protocols.activate', $retired))->assertStatus(422);

    $user = workspaceUser();
    $this->actingAs($user);
    $case = workspaceCase($user);
    workspaceChange($case, 'consent', ['protocol_id' => $retired->id, 'status' => 'granted', 'callback_allowed' => false])->assertSessionHasErrors('protocol_id');
    workspaceChange($case, 'consent', ['protocol_id' => $active->id, 'status' => 'granted', 'callback_allowed' => false])->assertSessionHasNoErrors();
});

test('questionnaire versions accept the Phase 1 answer types including yes/no without unknown', function () {
    $this->actingAs(workspaceUser(['manage screening protocols']));

    $this->post(route('screening-protocols.store'), [
        'version' => 'simulation-answer-types', 'title' => 'Simulation answer types', 'privacy_notice' => 'Simulation notice', 'allow_guardian' => false,
        'guidance' => array_fill_keys(['P1', 'P2', 'P3', 'S', 'Review'], 'Simulation instruction'), 'faq_answer' => 'Simulation service FAQ',
        'questions' => [
            ['prompt' => 'Simulation yes/no question?', 'positive_priority' => 'P1', 'type' => 'yes_no'],
            ['prompt' => 'Simulation long text question?', 'positive_priority' => 'P2', 'type' => 'paragraph'],
        ],
    ])->assertSessionHasNoErrors();

    expect(ScreeningQuestion::query()->orderBy('position')->pluck('type')->all())->toBe(['yes_no', 'paragraph']);

    $this->get(route('screening-protocols.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('questionTypes.yes_no', 'Yes / No')
        ->where('questionTypes.yes_no_unknown', 'Yes / No / Unknown')
        ->where('questionTypes.paragraph', 'Long text'));
});

test('yes/no questions reject unknown answers while yes/no/unknown accepts them', function () {
    $user = workspaceUser();
    $this->actingAs($user);
    $case = workspaceCase($user);
    $protocol = workspaceProtocol();
    $protocol->questions()->whereKey($protocol->questions[0]->id)->update(['type' => 'yes_no']);
    $protocol->load('questions');

    workspaceChange($case, 'consent', ['protocol_id' => $protocol->id, 'status' => 'granted', 'callback_allowed' => false])->assertSessionHasNoErrors();
    workspaceChange($case, 'screening', workspaceAnswers($protocol, 'P3', 'unknown'))->assertSessionHasErrors('answers');
    workspaceChange($case, 'screening', workspaceAnswers($protocol, 'P3', 'no', 'unknown'))->assertSessionHasNoErrors();
});

test('protocol managers create immutable versions and activation retires the previous one', function () {
    $this->actingAs(workspaceUser(['manage screening protocols']));
    $payload = [
        'version' => 'simulation-v2', 'title' => 'Reviewed simulation', 'privacy_notice' => 'Simulation notice', 'allow_guardian' => false,
        'guidance' => array_fill_keys(['P1', 'P2', 'P3', 'S', 'Review'], 'Simulation instruction'), 'faq_answer' => 'Simulation service FAQ',
        'questions' => [['prompt' => 'Simulation emergency question?', 'positive_priority' => 'P1']],
    ];
    $this->post(route('screening-protocols.store'), $payload)->assertSessionHasNoErrors();
    $protocol = ScreeningProtocol::query()->sole();
    expect($protocol->status)->toBe('active')->and($protocol->is_active)->toBeTrue()->and($protocol->questions()->count())->toBe(1);
    $this->post(route('screening-protocols.store'), $payload)->assertSessionHasErrors('version');
    $this->post(route('screening-protocols.store'), [...$payload, 'version' => 'simulation-v3'])->assertSessionHasNoErrors();
    expect($protocol->fresh()->status)->toBe('retired')->and($protocol->fresh()->is_active)->toBeFalse();
    $this->delete(route('screening-protocols.retire', $protocol->fresh()))->assertRedirect();
    expect($protocol->fresh()->privacy_notice)->toBe('Simulation notice');
    $this->get(route('screening-protocols.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Workspace/Protocols')->has('protocols.data', 2));
});

test('seeded assistants can use their workspace with the supplied active questionnaire', function () {
    $this->seed();
    $user = User::factory()->create();
    $user->assignRole('Dental Assistant');
    $this->actingAs($user);
    $case = workspaceCase($user);
    $this->get(route('workspace.index'))->assertOk();
    $this->get(route('workspace.show', $case))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Workspace/Show')->where('protocol.version', ScreeningProtocolSeeder::VERSION)->where('canEdit', true)->has('screenings', 0));
});

test('successful FAQ closure requires confirmation of approved information', function () {
    $user = workspaceUser();
    $this->actingAs($user);
    $case = workspaceCase($user);
    $protocol = workspaceProtocol();
    workspaceChange($case, 'consent', ['protocol_id' => $protocol->id, 'status' => 'granted', 'callback_allowed' => false]);
    workspaceChange($case, 'profile', [...workspaceProfile(), 'callback_number' => null, 'concern_type' => 'service_information']);
    workspaceChange($case, 'screening', workspaceAnswers($protocol, 'S'));
    $closure = ['disposition' => 'faq_resolved', 'next_step' => 'Approved service information shared'];
    workspaceChange($case, 'close', $closure)->assertSessionHasErrors('faq_confirmed');
    workspaceChange($case, 'close', [...$closure, 'faq_confirmed' => true])->assertSessionHasNoErrors();
    expect($case->fresh()->status)->toBe('closed');
});

test('callback intent cannot close a case without confirmed callback consent and number', function () {
    $user = workspaceUser();
    $this->actingAs($user);
    $case = workspaceCase($user);
    $protocol = workspaceProtocol();
    workspaceChange($case, 'consent', ['protocol_id' => $protocol->id, 'status' => 'granted', 'callback_allowed' => false]);
    workspaceChange($case, 'profile', [...workspaceProfile(), 'callback_number' => null]);
    workspaceChange($case, 'screening', workspaceAnswers($protocol));
    $closure = ['disposition' => 'callback_requested', 'next_step' => 'Callback request requires scheduling'];
    workspaceChange($case, 'close', $closure)->assertSessionHasErrors('callback_number');
    workspaceChange($case, 'consent', ['protocol_id' => $protocol->id, 'status' => 'granted', 'callback_allowed' => true]);
    workspaceChange($case, 'profile', workspaceProfile());
    workspaceChange($case, 'close', $closure)->assertSessionHasNoErrors();
    expect($case->fresh()->disposition)->toBe('callback_requested')->and($case->fresh()->status)->toBe('closed');
});

test('screening filters and history show saved versions without leaking clinical data into the case list', function () {
    $user = workspaceUser();
    $this->actingAs($user);
    $case = workspaceCase($user);
    $case->update(['concern' => 'Sensitive caller-reported concern']);
    $protocol = workspaceProtocol();
    workspaceChange($case, 'screening', workspaceAnswers($protocol, 'P2', 'no', 'yes'));
    $this->get(route('cases.index', ['priority' => 'P2', 'mine' => 1]))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('cases.data', 1)->missing('cases.data.0.concern')->missing('cases.data.0.callback_number'));
    $this->get(route('workspace.show', $case))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('screenings', 1)->has('historyProtocols.0.questions', 2)->where('screenings.0.screening_protocol_id', $protocol->id));
    $this->get(route('cases.index', ['priority' => 'invented']))->assertSessionHasErrors('priority');
});

test('transfer correlation preserves the existing verified case interaction', function () {
    $this->freezeTime();
    $connection = PbxConnection::create(['name' => 'Simulation', 'host' => '127.0.0.1']);
    $frames = warmTransferFrames();
    correlateCallFrames($connection, array_values(array_filter($frames, fn (array $frame): bool => $frame['t'] < 21)));
    $outbound = Interaction::query()->where('correlation_key', 'agent-out')->sole();
    $case = CallerCase::factory()->create(['interaction_id' => $outbound->id]);

    correlateCallFrames($connection, array_values(array_filter($frames, fn (array $frame): bool => $frame['t'] >= 21)));

    expect(Interaction::query()->count())->toBe(1)->and($case->fresh()->interaction_id)->toBe($outbound->id);
    expect(Interaction::query()->sole()->status)->toBe('completed');
});

test('transfer correlation flags competing verified cases instead of merging their records', function () {
    $connection = PbxConnection::create(['name' => 'Simulation', 'host' => '127.0.0.1']);
    $frames = warmTransferFrames();
    correlateCallFrames($connection, array_values(array_filter($frames, fn (array $frame): bool => $frame['t'] < 21)));
    foreach (Interaction::all() as $interaction) {
        CallerCase::factory()->create(['interaction_id' => $interaction->id]);
    }

    correlateCallFrames($connection, [collect($frames)->firstWhere('Event', 'AttendedTransfer')]);

    expect(Interaction::query()->count())->toBe(2)->and(CallerCase::query()->count())->toBe(2);
    $this->assertDatabaseHas('correlation_exceptions', ['code' => 'multiple_verified_cases_require_review']);
});

test('urgent disposition requires a complete screen and preserves priority over a routine request', function () {
    $user = workspaceUser();
    $this->actingAs($user);
    $case = workspaceCase($user);
    $protocol = workspaceProtocol();
    workspaceChange($case, 'consent', ['protocol_id' => $protocol->id, 'status' => 'granted', 'callback_allowed' => true]);
    workspaceChange($case, 'profile', workspaceProfile());
    workspaceChange($case, 'screening', workspaceAnswers($protocol, 'P3', 'unknown', 'yes'));
    workspaceChange($case, 'close', ['disposition' => 'urgent_referral', 'next_step' => 'Simulation urgent route'])->assertSessionHasErrors('screening');
    workspaceChange($case, 'screening', workspaceAnswers($protocol, 'P3', 'no', 'yes'));
    workspaceChange($case, 'close', ['disposition' => 'routine_handover', 'next_step' => 'Routine'])->assertSessionHasErrors('disposition');
    workspaceChange($case, 'close', ['disposition' => 'urgent_referral', 'next_step' => 'Simulation priority clinician action recorded'])->assertSessionHasNoErrors();
    expect($case->fresh()->status)->toBe('closed')->and($case->fresh()->priority)->toBe('P2');
});

test('malformed protocol lists and missing emergency questions cannot be published as drafts', function () {
    $this->actingAs(workspaceUser(['manage screening protocols']));
    $payload = [
        'version' => 'invalid-protocol', 'title' => 'Simulation', 'privacy_notice' => 'Simulation', 'allow_guardian' => false,
        'guidance' => array_fill_keys(['P1', 'P2', 'P3', 'S', 'Review'], 'Simulation'), 'faq_answer' => 'Simulation',
        'questions' => ['invalid-position' => ['prompt' => 'Simulation question', 'positive_priority' => 'P1']],
    ];
    $this->post(route('screening-protocols.store'), $payload)->assertSessionHasErrors('questions');
    $payload['questions'] = [['prompt' => 'Simulation urgent question', 'positive_priority' => 'P2']];
    $this->post(route('screening-protocols.store'), $payload)->assertSessionHasErrors(['questions' => 'Include at least one emergency safety question.']);
    $this->assertDatabaseCount('screening_protocols', 0);
});

test('historical consent versions do not block routine closure', function () {
    $user = workspaceUser();
    $this->actingAs($user);
    $case = workspaceCase($user);
    $protocol = workspaceProtocol();
    workspaceChange($case, 'consent', ['protocol_id' => $protocol->id, 'status' => 'granted', 'callback_allowed' => false]);
    workspaceChange($case, 'profile', [...workspaceProfile(), 'callback_number' => null]);
    workspaceChange($case, 'screening', workspaceAnswers(workspaceProtocol()));
    workspaceChange($case, 'close', ['disposition' => 'routine_handover', 'next_step' => 'Handover recorded'])->assertSessionHasNoErrors();
    expect($case->fresh()->status)->toBe('closed');
});

test('validation errors never flash caller health or contact details into the session', function () {
    $user = workspaceUser();
    $this->actingAs($user);
    $case = workspaceCase($user);
    workspaceChange($case, 'profile', [...workspaceProfile(), 'age_band' => 'invalid'])->assertSessionHasErrors('age_band')
        ->assertSessionMissing('_old_input.preferred_name')->assertSessionMissing('_old_input.callback_number')
        ->assertSessionMissing('_old_input.concern')->assertSessionMissing('_old_input.onset')->assertSessionMissing('_old_input.impact');
    expect($case->fresh()->concern)->toBeNull();
});

test('consent can be withdrawn after closure or notice retirement without changing clinical history', function (bool $closed, bool $retired) {
    $this->freezeTime();
    $user = workspaceUser();
    $this->actingAs($user);
    $case = workspaceCase($user);
    $protocol = workspaceProtocol();
    workspaceChange($case, 'consent', ['protocol_id' => $protocol->id, 'status' => 'granted', 'callback_allowed' => true])->assertSessionHasNoErrors();
    workspaceChange($case, 'profile', workspaceProfile())->assertSessionHasNoErrors();
    workspaceChange($case, 'screening', workspaceAnswers($protocol))->assertSessionHasNoErrors();
    if ($closed) {
        workspaceChange($case, 'close', ['disposition' => 'routine_handover', 'next_step' => 'Simulation handover completed'])->assertSessionHasNoErrors();
    }
    if ($retired) {
        $protocol->update(['status' => 'retired', 'is_active' => false]);
    }
    $before = $case->fresh();
    $this->get(route('workspace.show', $case))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('canWithdrawConsent', true));

    workspaceChange($case, 'consent', ['protocol_id' => $protocol->id, 'status' => 'withdrawn', 'callback_allowed' => false])->assertSessionHasNoErrors();

    expect($case->fresh()->status)->toBe($before->status)
        ->and($case->fresh()->closed_at?->toIso8601String())->toBe($before->closed_at?->toIso8601String())
        ->and($case->fresh()->concern)->toBe($before->concern)
        ->and($case->consents()->latest('id')->first()->status)->toBe('withdrawn');
    $this->assertDatabaseCount('consent_records', 2);
    $this->assertDatabaseCount('screenings', 1);
    $response = workspaceChange($case, 'profile', workspaceProfile());
    if ($closed) {
        $response->assertSessionHasErrors('case');
    } else {
        $response->assertSessionHasNoErrors();
    }
    if ($closed) {
        workspaceChange($case, 'consent', ['protocol_id' => $protocol->id, 'status' => 'granted', 'callback_allowed' => true])->assertSessionHasErrors('case');
    }
})->with([
    'open case and active notice' => [false, false],
    'closed case and active notice' => [true, false],
    'open case and retired notice' => [false, true],
    'closed case and retired notice' => [true, true],
]);

test('conditional questionnaire questions are skipped and stored with explicit statuses', function () {
    $this->freezeTime();
    $user = workspaceUser();
    $this->actingAs($user);
    $case = workspaceCase($user);
    $protocol = workspaceProtocol();
    $trigger = $protocol->questions[1];
    $followUp = $protocol->questions[0];
    $followUp->update(['type' => 'text', 'required' => true, 'conditional_question_id' => $trigger->id, 'conditional_answer' => 'yes']);

    $answered = ['protocol_id' => $protocol->id, 'priority' => 'P3', 'answers' => [
        ['question_id' => $trigger->id, 'answer' => 'no'],
    ]];
    workspaceChange($case, 'screening', $answered)->assertSessionHasNoErrors();

    expect(ScreeningAnswer::where('screening_question_id', $trigger->id)->firstOrFail()->answer)->toBe('no');
    $this->assertDatabaseHas('screening_answers', ['screening_question_id' => $trigger->id, 'answer_status' => 'answered']);
    expect(ScreeningAnswer::where('screening_question_id', $followUp->id)->firstOrFail()->answer)->toBe('');
    $this->assertDatabaseHas('screening_answers', ['screening_question_id' => $followUp->id, 'answer_status' => 'skipped']);
    $this->assertDatabaseMissing('screening_answers', ['screening_question_id' => $followUp->id, 'saved_at' => null]);

    workspaceChange($case, 'screening', ['protocol_id' => $protocol->id, 'priority' => 'P3', 'answers' => [
        ['question_id' => $trigger->id, 'answer' => 'no'],
        ['question_id' => $followUp->id, 'answer' => 'Caller described the swelling'],
    ]])->assertSessionHasErrors('answers');

    workspaceChange($case, 'screening', ['protocol_id' => $protocol->id, 'priority' => 'P3', 'answers' => [
        ['question_id' => $trigger->id, 'answer' => 'yes'],
    ]])->assertSessionHasErrors('answers');

    workspaceChange($case, 'screening', ['protocol_id' => $protocol->id, 'priority' => 'P3', 'answers' => [
        ['question_id' => $trigger->id, 'answer' => 'yes'],
        ['question_id' => $followUp->id, 'answer' => 'Caller described the swelling'],
    ]])->assertSessionHasNoErrors();

    expect(ScreeningAnswer::where('screening_question_id', $followUp->id)->latest('id')->firstOrFail()->answer)->toBe('Caller described the swelling');
    $this->assertDatabaseHas('screening_answers', ['screening_question_id' => $followUp->id, 'answer_status' => 'answered']);
    expect($case->fresh()->screenings()->count())->toBe(2);
});

test('choice questionnaire options escalate through the recorded option text', function () {
    $user = workspaceUser();
    $this->actingAs($user);
    $case = workspaceCase($user);
    $protocol = workspaceProtocol();
    $protocol->questions[0]->update(['type' => 'choice', 'options' => ['Yes - facial swelling', 'No']]);

    workspaceChange($case, 'screening', workspaceAnswers($protocol, 'P3', 'Yes - facial swelling', 'no'))->assertSessionHasNoErrors();

    expect($case->fresh()->priority)->toBe('P1');
});

test('the workspace transfer directory is permission scoped and lists approved numbers only', function () {
    $user = workspaceUser();
    $case = workspaceCase($user);
    $dentist = Dentist::factory()->create(['name' => 'Dr Transfer']);
    DentistNumber::factory()->for($dentist)->primary()->create(['number' => '+233241234567']);
    DentistNumber::factory()->for($dentist)->create(['number' => '+233241234568', 'status' => 'pending']);

    $this->actingAs($user);
    $this->get(route('workspace.show', $case))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('selectableDentists', null));

    $this->actingAs(tap($user)->givePermissionTo(Permission::findOrCreate('view dentists'))->fresh());
    $this->get(route('workspace.show', $case))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('selectableDentists.dentists.0.name', 'Dr Transfer')
            ->where('selectableDentists.dentists.0.availability_status', 'available')
            ->where('selectableDentists.dentists.0.numbers.0.masked', '+233241****67')
            ->missing('selectableDentists.dentists.0.numbers.1'));
});

test('withdrawal must reference the existing granted notice and cannot bypass ownership', function () {
    $user = workspaceUser();
    $this->actingAs($user);
    $case = workspaceCase($user);
    $original = workspaceProtocol();
    $other = workspaceProtocol();
    workspaceChange($case, 'consent', ['protocol_id' => $original->id, 'status' => 'withdrawn', 'callback_allowed' => false])->assertSessionHasErrors('consent');
    workspaceChange($case, 'consent', ['protocol_id' => $original->id, 'status' => 'granted', 'callback_allowed' => true]);
    workspaceChange($case, 'consent', ['protocol_id' => $other->id, 'status' => 'withdrawn', 'callback_allowed' => false])->assertSessionHasErrors('consent');
    workspaceChange($case, 'consent', ['protocol_id' => $original->id, 'status' => 'withdrawn', 'callback_allowed' => true])->assertSessionHasErrors('callback_allowed');
    $this->actingAs(workspaceUser());
    workspaceChange($case, 'consent', ['protocol_id' => $original->id, 'status' => 'withdrawn', 'callback_allowed' => false])->assertNotFound();
    $this->assertDatabaseCount('consent_records', 1);
});
test('questionnaire autosave stores incomplete answers with status author and version then promotes them', function () {
    $this->freezeTime();
    $user = workspaceUser();
    $this->actingAs($user);
    $case = workspaceCase($user);
    $protocol = workspaceProtocol();

    $this->patchJson(route('workspace.draft', $case), [
        'protocol_id' => $protocol->id,
        'priority' => 'P2',
        'answers' => [
            ['question_id' => $protocol->questions[0]->id, 'answer' => 'no'],
            ['question_id' => $protocol->questions[1]->id, 'answer' => ''],
        ],
    ])->assertOk()
        ->assertJsonPath('draft.screening_protocol_id', $protocol->id)
        ->assertJsonPath('draft.priority', 'P2')
        ->assertJsonPath('draft.revision', 1)
        ->assertJsonPath('draft.recorded_by', $user->id)
        ->assertJsonCount(2, 'draft.answers');

    $draft = ScreeningDraft::query()->sole();
    expect($draft->draft_saved_at)->not->toBeNull()
        ->and($draft->answers[0])->toMatchArray([
            'question_id' => $protocol->questions[0]->id,
            'answer' => 'no',
            'answer_status' => 'answered',
            'question_version' => $protocol->version,
            'recorded_by' => $user->id,
        ])
        ->and($draft->answers[1]['answer_status'])->toBe('unanswered');

    $this->patchJson(route('workspace.draft', $case), [
        'protocol_id' => $protocol->id,
        'priority' => 'P3',
        'revision' => 1,
        'answers' => [
            ['question_id' => $protocol->questions[0]->id, 'answer' => 'no'],
            ['question_id' => $protocol->questions[1]->id, 'answer' => 'no'],
        ],
    ])->assertOk()->assertJsonPath('draft.revision', 2);

    $this->get(route('workspace.show', $case))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('draft.revision', 2)
        ->where('draft.priority', 'P3')
        ->has('draft.answers', 2));

    workspaceChange($case, 'screening', workspaceAnswers($protocol, 'P3', 'no', 'no'))->assertSessionHasNoErrors();

    $this->assertDatabaseCount('screening_drafts', 0);
    $screening = $case->fresh()->screenings()->sole();
    expect($screening->answers()->count())->toBe(2)
        ->and($screening->answers()->where('question_version', $protocol->version)->where('recorded_by', $user->id)->count())->toBe(2)
        ->and($screening->answers()->orderBy('id')->first()?->question_prompt)->toBe($protocol->questions[0]->prompt);
});

test('draft autosave rejects stale revisions foreign questions and closed cases', function () {
    $user = workspaceUser();
    $this->actingAs($user);
    $case = workspaceCase($user);
    $protocol = workspaceProtocol();
    $payload = [
        'protocol_id' => $protocol->id,
        'priority' => 'P3',
        'answers' => [['question_id' => $protocol->questions[0]->id, 'answer' => 'no']],
    ];

    $this->patchJson(route('workspace.draft', $case), $payload)->assertOk();
    $this->patchJson(route('workspace.draft', $case), [...$payload, 'revision' => 99])->assertStatus(409);
    $this->patchJson(route('workspace.draft', $case), [
        'protocol_id' => $protocol->id,
        'priority' => 'P3',
        'answers' => [['question_id' => 999999, 'answer' => 'no']],
    ])->assertStatus(422)->assertJsonValidationErrors('answers');

    $case->update(['status' => 'closed']);
    $this->patchJson(route('workspace.draft', $case), $payload)->assertStatus(422)->assertJsonValidationErrors('case');

    $this->actingAs(workspaceUser());
    $this->patchJson(route('workspace.draft', $case), $payload)->assertNotFound();
});

test('autosave pins the questionnaire version without requiring consent', function () {
    $user = workspaceUser();
    $this->actingAs($user);
    $case = workspaceCase($user);
    $protocol = workspaceProtocol();

    $this->patchJson(route('workspace.draft', $case), [
        'protocol_id' => $protocol->id,
        'priority' => 'P3',
        'answers' => [['question_id' => $protocol->questions[0]->id, 'answer' => 'no']],
    ])->assertOk()->assertJsonPath('draft.revision', 1);

    $replacement = workspaceProtocol(false);
    $replacement->activate();
    expect($protocol->fresh()->isActive())->toBeFalse();

    $this->patchJson(route('workspace.draft', $case), [
        'protocol_id' => $protocol->id,
        'priority' => 'P3',
        'revision' => 1,
        'answers' => [
            ['question_id' => $protocol->questions[0]->id, 'answer' => 'no'],
            ['question_id' => $protocol->questions[1]->id, 'answer' => 'yes'],
        ],
    ])->assertOk()->assertJsonPath('draft.revision', 2);

    $this->get(route('workspace.show', $case))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('protocol.id', $protocol->id)
        ->where('draft.revision', 2));

    workspaceChange($case, 'screening', workspaceAnswers($protocol, 'P3', 'no', 'yes'))->assertSessionHasNoErrors();

    $unrelated = workspaceProtocol(false);
    $this->patchJson(route('workspace.draft', $case), [
        'protocol_id' => $unrelated->id,
        'priority' => 'P3',
        'answers' => [['question_id' => $unrelated->questions[0]->id, 'answer' => 'no']],
    ])->assertStatus(422)->assertJsonValidationErrors('protocol_id');

    expect($case->fresh()->screenings()->sole()->screening_protocol_id)->toBe($protocol->id)
        ->and(ScreeningDraft::query()->where('screening_protocol_id', $unrelated->id)->exists())->toBeFalse();
});

test('a withdrawn notice falls back to the active questionnaire so consent can be recorded again', function () {
    $user = workspaceUser();
    $this->actingAs($user);
    $case = workspaceCase($user);
    $original = workspaceProtocol();
    workspaceChange($case, 'consent', ['protocol_id' => $original->id, 'status' => 'granted', 'callback_allowed' => false])->assertSessionHasNoErrors();
    workspaceChange($case, 'consent', ['protocol_id' => $original->id, 'status' => 'withdrawn', 'callback_allowed' => false])->assertSessionHasNoErrors();

    $replacement = workspaceProtocol(false);
    $replacement->activate();

    $this->get(route('workspace.show', $case))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('protocol.id', $replacement->id));

    workspaceChange($case, 'consent', ['protocol_id' => $replacement->id, 'status' => 'granted', 'callback_allowed' => false])->assertSessionHasNoErrors();
    expect($case->consents()->latest('id')->first()->screening_protocol_id)->toBe($replacement->id);
});

test('the workspace shows incoming call evidence and never labels stale telemetry live', function () {
    $this->freezeTime();
    config(['services.exp_pbx.connection_name' => 'Simulation', 'services.exp_pbx.screening_extension' => '204']);
    $connection = PbxConnection::create(['name' => 'Simulation', 'host' => '127.0.0.1', 'status' => 'connected', 'last_heartbeat_at' => now()]);
    $interaction = Interaction::factory()->create(['pbx_connection_id' => $connection->id, 'is_exp' => true, 'started_at' => now()->subSeconds(30)]);
    workspaceOptionThree($interaction);
    CallLeg::create(['interaction_id' => $interaction->id, 'pbx_connection_id' => $connection->id, 'uniqueid' => 'agent-live', 'role' => 'agent', 'channel' => 'PJSIP/204-00000001', 'observed_creation' => true, 'started_at' => now()->subSeconds(30), 'ringing_at' => now()->subSeconds(30)]);
    CallLeg::create(['interaction_id' => $interaction->id, 'pbx_connection_id' => $connection->id, 'uniqueid' => 'caller-live', 'role' => 'caller', 'channel' => 'PJSIP/0244123456-00000002', 'inbound_masked' => '030*****01', 'started_at' => now()->subSeconds(30)]);
    $user = workspaceUser();
    $case = CallerCase::factory()->create(['interaction_id' => $interaction->id, 'assigned_to' => $user->id]);
    $this->actingAs($user);

    $this->get(route('workspace.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('callContext.freshness.state', 'live')
        ->where('callContext.agent_extension', '204')
        ->has('callContext.calls', 1)
        ->where('callContext.calls.0.id', $interaction->id)
        ->where('callContext.calls.0.caller_number', '024*****56')
        ->where('callContext.calls.0.inbound_did', '030*****01')
        ->where('callContext.calls.0.case_id', $case->id));

    $this->get(route('workspace.show', $case))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('callContext.interaction.id', $interaction->id)
        ->where('callContext.interaction.inbound_did', '030*****01')
        ->where('callContext.interaction.agent_channel', 'PJSIP/204-000***01')
        ->where('callContext.freshness.state', 'live'));

    $connection->update(['last_heartbeat_at' => now()->subMinutes(10)]);
    $this->get(route('workspace.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('callContext.freshness.state', 'stale'));
    $this->get(route('workspace.show', $case))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('callContext.freshness.state', 'stale'));
});

test('call evidence never reveals case identifiers the agent cannot open', function () {
    $this->freezeTime();
    config(['services.exp_pbx.connection_name' => 'Simulation']);
    $connection = PbxConnection::create(['name' => 'Simulation', 'host' => '127.0.0.1', 'status' => 'connected', 'last_heartbeat_at' => now()]);
    $interaction = Interaction::factory()->create(['pbx_connection_id' => $connection->id, 'is_exp' => true, 'started_at' => now()->subMinute()]);
    workspaceOptionThree($interaction);
    CallLeg::create(['interaction_id' => $interaction->id, 'pbx_connection_id' => $connection->id, 'uniqueid' => 'agent-scope', 'role' => 'agent', 'channel' => 'PJSIP/204-00000007', 'observed_creation' => true]);
    $owner = workspaceUser();
    $case = CallerCase::factory()->create(['interaction_id' => $interaction->id, 'assigned_to' => $owner->id]);

    $this->actingAs(workspaceUser());
    $this->get(route('workspace.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('callContext.calls', 1)
        ->where('callContext.calls.0.case_id', null)
        ->where('callContext.calls.0.has_case', false)
        ->has('callContext.recent', 1)
        ->where('callContext.recent.0.case_id', null));

    $this->actingAs(workspaceUser(['view cases', 'edit cases', 'view all cases']));
    $this->get(route('workspace.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('callContext.calls.0.case_id', $case->id)
        ->where('callContext.calls.0.has_case', true));
});

test('the case screen refreshes only call evidence and reports fresher staleness', function () {
    $this->freezeTime();
    config(['services.exp_pbx.connection_name' => 'Simulation']);
    $connection = PbxConnection::create(['name' => 'Simulation', 'host' => '127.0.0.1', 'status' => 'connected', 'last_heartbeat_at' => now()]);
    $interaction = Interaction::factory()->create(['pbx_connection_id' => $connection->id, 'is_exp' => true, 'started_at' => now()->subSeconds(20)]);
    workspaceOptionThree($interaction);
    CallLeg::create(['interaction_id' => $interaction->id, 'pbx_connection_id' => $connection->id, 'uniqueid' => 'agent-refresh', 'role' => 'agent', 'channel' => 'PJSIP/204-00000005', 'observed_creation' => true]);
    $user = workspaceUser();
    $case = CallerCase::factory()->create(['interaction_id' => $interaction->id, 'assigned_to' => $user->id]);
    $this->actingAs($user);

    $partial = [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) (new HandleInertiaRequests)->version(request()),
        'X-Inertia-Partial-Component' => 'Workspace/Show',
        'X-Inertia-Partial-Data' => 'callContext',
    ];

    $this->get(route('workspace.show', $case), $partial)->assertOk()
        ->assertJsonPath('component', 'Workspace/Show')
        ->assertJsonPath('props.callContext.interaction.id', $interaction->id)
        ->assertJsonPath('props.callContext.freshness.state', 'live')
        ->assertJsonMissingPath('props.callerCase')
        ->assertJsonMissingPath('props.screenings');

    $connection->update(['last_heartbeat_at' => now()->subMinutes(10)]);
    $this->get(route('workspace.show', $case), $partial)->assertOk()
        ->assertJsonPath('props.callContext.freshness.state', 'stale');
});

test('ambiguous call evidence must be acknowledged before a case is opened', function () {
    $this->freezeTime();
    config(['services.exp_pbx.connection_name' => 'Simulation']);
    $connection = PbxConnection::create(['name' => 'Simulation', 'host' => '127.0.0.1']);
    $first = Interaction::factory()->create(['pbx_connection_id' => $connection->id, 'is_exp' => true, 'started_at' => now()->subMinute()]);
    workspaceOptionThree($first);
    $second = Interaction::factory()->create(['pbx_connection_id' => $connection->id, 'is_exp' => true, 'started_at' => now()]);
    workspaceOptionThree($second);
    foreach ([$first, $second] as $index => $interaction) {
        CallLeg::create(['interaction_id' => $interaction->id, 'pbx_connection_id' => $connection->id, 'uniqueid' => 'agent-'.$index, 'role' => 'agent', 'channel' => 'PJSIP/204-0000000'.$index, 'observed_creation' => true]);
    }
    $user = workspaceUser();
    Permission::findOrCreate('authorise live calls');
    $user->givePermissionTo('authorise live calls');
    $this->actingAs($user);

    $this->post(route('workspace.store'), [
        'interaction_id' => $first->id,
        'association_confirmed' => true,
    ])->assertSessionHasErrors('ambiguity_acknowledged');
    $this->assertDatabaseCount('cases', 0);

    $this->post(route('workspace.store'), [
        'interaction_id' => $first->id,
        'association_confirmed' => true,
        'ambiguity_acknowledged' => true,
    ])->assertRedirect();
    expect(CallerCase::query()->sole()->interaction_id)->toBe($first->id);
    expect(Activity::query()->latest('id')->first()?->properties?->get('association_ambiguity'))
        ->toContain('multiple_active_calls');
});

test('reopening a confirmed case keeps exact answers without duplicating the case', function () {
    $user = workspaceUser();
    $this->actingAs($user);
    $protocol = workspaceProtocol();
    $interaction = Interaction::factory()->create(['is_exp' => true]);
    workspaceOptionThree($interaction);
    CallLeg::create(['interaction_id' => $interaction->id, 'pbx_connection_id' => $interaction->pbx_connection_id, 'uniqueid' => 'agent-reopen', 'role' => 'agent', 'channel' => 'PJSIP/204-00000009', 'observed_creation' => true]);
    $case = CallerCase::factory()->create(['interaction_id' => $interaction->id, 'assigned_to' => $user->id]);
    workspaceChange($case, 'screening', workspaceAnswers($protocol, 'P3', 'no', 'no'))->assertSessionHasNoErrors();

    $this->get(route('workspace.show', $case))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('screenings', 1)
        ->has('screenings.0.answers', 2)
        ->where('screenings.0.answers.0.answer_status', 'answered')
        ->where('screenings.0.answers.0.question_version', $protocol->version)
        ->where('screenings.0.answers.0.question_prompt', $protocol->questions[0]->prompt));

    Permission::findOrCreate('authorise live calls');
    $user->givePermissionTo('authorise live calls');
    $this->post(route('workspace.store'), ['interaction_id' => $interaction->id, 'association_confirmed' => true])->assertRedirect(route('workspace.show', $case));
    $this->post(route('workspace.store'), ['interaction_id' => $interaction->id, 'association_confirmed' => true])->assertRedirect(route('workspace.show', $case));
    $this->assertDatabaseCount('cases', 1);
});

test('super administrator opens cases and records data on another agents case without seeded grants', function () {
    $role = Role::findOrCreate('Super Administrator', 'web');
    $admin = User::factory()->create();
    $admin->assignRole($role);
    $owner = User::factory()->create();
    $case = workspaceCase($owner);
    $protocol = workspaceProtocol();

    $this->actingAs($admin)->get(route('workspace.show', $case))->assertOk();
    workspaceChange($case, 'consent', ['protocol_id' => $protocol->id, 'status' => 'granted', 'callback_allowed' => true])->assertRedirect()->assertSessionHasNoErrors();
    workspaceChange($case, 'profile', workspaceProfile())->assertRedirect()->assertSessionHasNoErrors();

    expect($case->fresh()->preferred_name)->toBe('Caller preferred name')
        ->and($case->consents()->latest('id')->first()->recorded_by)->toBe($admin->id);

    $interaction = Interaction::factory()->create(['is_exp' => true]);
    workspaceOptionThree($interaction);
    CallLeg::create(['interaction_id' => $interaction->id, 'pbx_connection_id' => $interaction->pbx_connection_id, 'uniqueid' => 'superadmin-agent', 'role' => 'agent', 'observed_creation' => true]);
    $this->post(route('workspace.store'), ['interaction_id' => $interaction->id, 'association_confirmed' => true, 'ambiguity_acknowledged' => true])->assertRedirect()->assertSessionHasNoErrors();
    expect(CallerCase::where('interaction_id', $interaction->id)->firstOrFail()->assigned_to)->toBe($admin->id);
});

test('call controls are offered to the assigned agent and super administrator only', function () {
    $owner = workspaceUser(['view cases', 'edit cases', 'control calls']);
    $case = workspaceCase($owner);
    $this->actingAs($owner)->get(route('workspace.show', $case))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canControl', true));

    $observer = workspaceUser(['view cases', 'edit cases', 'view all cases', 'control calls']);
    $this->actingAs($observer)->get(route('workspace.show', $case))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canControl', false));

    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('Super Administrator', 'web'));
    $this->actingAs($admin)->get(route('workspace.show', $case))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canControl', true));

    $case->update(['status' => 'closed']);
    $this->get(route('workspace.show', $case))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canControl', false));
});

test('one call record keeps one editable case through data entry without consent', function () {
    $user = workspaceUser(['view cases', 'edit cases', 'authorise live calls']);
    $interaction = Interaction::factory()->create(['is_exp' => true]);
    workspaceOptionThree($interaction);
    $protocol = workspaceProtocol();
    $this->actingAs($user);

    $payload = ['interaction_id' => $interaction->id, 'association_confirmed' => true, 'ambiguity_acknowledged' => true];
    $this->post(route('workspace.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
    $case = CallerCase::where('interaction_id', $interaction->id)->sole();
    workspaceChange($case, 'profile', workspaceProfile())->assertSessionHasNoErrors();
    workspaceChange($case, 'note', ['body' => 'Reported fact during the call'])->assertSessionHasNoErrors();
    workspaceChange($case, 'screening', workspaceAnswers($protocol))->assertSessionHasNoErrors();
    $this->post(route('workspace.store'), $payload)->assertRedirect(route('workspace.show', $case));

    expect(CallerCase::where('interaction_id', $interaction->id)->count())->toBe(1)
        ->and($case->fresh()->concern)->toBe('Caller-reported concern')
        ->and($case->consents()->count())->toBe(0)
        ->and($case->notes()->count())->toBe(1);

    workspaceChange($case, 'close', ['disposition' => 'callback_requested', 'next_step' => 'Callback requested at the confirmed number'])->assertSessionHasNoErrors();
    expect($case->fresh()->status)->toBe('closed');
});

test('recent workspace records include option three calls even without an agent leg', function () {
    $this->freezeTime();
    config(['services.exp_pbx.connection_name' => 'record-list']);
    $connection = PbxConnection::create(['name' => 'record-list', 'host' => '127.0.0.1']);
    $interaction = Interaction::factory()->create(['pbx_connection_id' => $connection->id, 'is_exp' => true, 'status' => 'completed', 'started_at' => now()->subMinutes(20)]);
    workspaceOptionThree($interaction);

    $this->actingAs(workspaceUser())->get(route('workspace.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('callContext.recent', 1)
            ->where('callContext.recent.0.id', $interaction->id)
            ->where('callContext.recent.0.case_id', null)
            ->where('callContext.recent.0.caller_number', null));
});
