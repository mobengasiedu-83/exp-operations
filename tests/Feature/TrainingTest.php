<?php

use App\Models\Certification;
use App\Models\TrainingModule;
use App\Models\User;
use App\Services\Training;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;

/** @param list<string> $permissions */
function trainingUser(array $permissions = ['view training']): User
{
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission);
    }
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function trainingManager(): User
{
    return trainingUser(['view training', 'manage training']);
}

function trainingModule(User $manager, int $day, array $overrides = []): TrainingModule
{
    $training = app(Training::class);
    $module = $training->createModule($manager, [
        'day' => $day,
        'title' => 'Day '.$day.' curriculum',
        'objectives' => 'Approved objectives for day '.$day.'.',
        'content' => 'Approved curriculum content for day '.$day.'.',
        'requires_role_play' => true,
        'pass_mark' => 80,
        ...$overrides,
    ]);

    return $training->publishModule($manager, $module);
}

function trainingCompleteCurriculum(User $manager, User $candidate): void
{
    $training = app(Training::class);
    foreach (Training::DAYS as $day) {
        $module = trainingModule($manager, $day);
        foreach (Training::ASSESSMENT_KINDS as $kind) {
            $training->recordAssessment($manager, [
                'training_module_id' => $module->id,
                'user_id' => $candidate->id,
                'kind' => $kind,
                'score' => 90,
                'note' => 'Assessed against the published curriculum.',
            ]);
        }
    }
}

test('training routes require authentication and separate management from live-call authorisation', function () {
    $this->get(route('training.index'))->assertRedirect(route('login'));

    $viewer = trainingUser();
    $this->actingAs($viewer)->get(route('training.index'))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $this->actingAs($viewer)->post(route('training.modules.store'), [
        'day' => 1, 'title' => 'Day one', 'objectives' => 'Objectives', 'content' => 'Content',
        'requires_role_play' => true, 'pass_mark' => 80,
    ])->assertForbidden();

    $manager = trainingManager();
    $this->actingAs($manager)->post(route('training.modules.store'), [
        'day' => 1, 'title' => 'Day one', 'objectives' => 'Objectives', 'content' => 'Content',
        'requires_role_play' => true, 'pass_mark' => 80,
    ])->assertRedirect();
    $module = TrainingModule::sole();
    $this->actingAs($manager)->post(route('training.modules.publish', $module))->assertRedirect();
    expect($module->refresh()->status)->toBe('published');

    $candidate = trainingUser();
    $this->actingAs($manager)->post(route('training.certifications.store'), [
        'user_id' => $candidate->id, 'reference' => 'CERT-001', 'attested' => true,
    ])->assertForbidden();
});

test('the five-day curriculum has one module per day and only publishes complete content', function () {
    $manager = trainingManager();
    trainingModule($manager, 1);

    $this->actingAs($manager)->post(route('training.modules.store'), [
        'day' => 1, 'title' => 'Duplicate day', 'objectives' => 'Objectives', 'content' => 'Content',
        'requires_role_play' => true, 'pass_mark' => 80,
    ])->assertSessionHasErrors('day');

    $draft = app(Training::class)->createModule($manager, [
        'day' => 2, 'title' => 'Day two', 'objectives' => 'Objectives', 'content' => '   ',
        'requires_role_play' => false, 'pass_mark' => 80,
    ]);
    $this->actingAs($manager)->post(route('training.modules.publish', $draft))->assertSessionHasErrors('content');
    expect($draft->refresh()->status)->toBe('draft');
});

test('assessments use the published pass mark and cannot be self-assessed', function () {
    $manager = trainingManager();
    $trainer = trainingUser(['view training', 'manage training']);
    $candidate = trainingUser();
    $module = trainingModule($manager, 1);

    $this->actingAs($trainer)->post(route('training.assessments.store'), [
        'training_module_id' => $module->id, 'user_id' => $trainer->id, 'kind' => 'knowledge', 'score' => 90,
    ])->assertSessionHasErrors('user_id');

    $this->actingAs($trainer)->post(route('training.assessments.store'), [
        'training_module_id' => $module->id, 'user_id' => $candidate->id, 'kind' => 'knowledge', 'score' => 70,
    ])->assertRedirect();

    $assessment = $candidate->trainingAssessments()->sole();
    expect($assessment->pass_mark)->toBe(80)
        ->and($assessment->result)->toBe('fail')
        ->and($assessment->assessed_by)->toBe($trainer->id);

    $this->actingAs($trainer)->post(route('training.assessments.store'), [
        'training_module_id' => $module->id, 'user_id' => $candidate->id, 'kind' => 'knowledge', 'score' => 85,
    ])->assertRedirect();
    expect($candidate->trainingAssessments()->latest('id')->first()?->result)->toBe('pass');
});

test('certification requires all five days and grants live-call authorisation', function () {
    $authoriser = trainingUser(['view training', 'authorise live calls']);
    $manager = trainingManager();
    $candidate = trainingUser();

    $this->actingAs($authoriser)->post(route('training.certifications.store'), [
        'user_id' => $candidate->id, 'reference' => 'CERT-001', 'attested' => true,
    ])->assertSessionHasErrors('user_id');
    expect($candidate->certifications()->count())->toBe(0);

    trainingCompleteCurriculum($manager, $candidate);
    $this->actingAs($authoriser)->post(route('training.certifications.store'), [
        'user_id' => $candidate->id, 'reference' => 'CERT-001', 'attested' => true,
    ])->assertRedirect();

    $certification = Certification::sole();
    expect($certification->status)->toBe('active')
        ->and($certification->authorised_by)->toBe($authoriser->id)
        ->and($certification->expires_at?->toDateString())->toBe(now()->addMonths(Training::CERTIFICATION_MONTHS)->toDateString())
        ->and($certification->curriculum_snapshot)->toHaveCount(5);
    $training = app(Training::class);
    expect($training->isAuthorisedForLiveCalls($candidate))->toBeTrue()
        ->and($training->isAuthorisedForLiveCalls($manager))->toBeFalse()
        ->and($training->curriculumProgress($candidate))->toHaveCount(5)
        ->and(collect($training->curriculumProgress($candidate))->every(fn (array $day): bool => $day['complete']))->toBeTrue();

    $this->actingAs($authoriser)->post(route('training.certifications.store'), [
        'user_id' => $candidate->id, 'reference' => 'CERT-002', 'attested' => true,
    ])->assertSessionHasErrors('user_id');
});

test('revoked or expired certification no longer authorises live calls', function () {
    $authoriser = trainingUser(['view training', 'authorise live calls']);
    $manager = trainingManager();
    $candidate = trainingUser();
    trainingCompleteCurriculum($manager, $candidate);
    $training = app(Training::class);

    $legacy = $training->certify($authoriser, $candidate, [
        'reference' => 'CERT-EXPIRED',
        'issued_at' => now()->subYear()->toDateString(),
        'expires_at' => now()->subDay()->toDateString(),
    ]);
    expect($legacy->hasExpired())->toBeTrue()
        ->and($training->isAuthorisedForLiveCalls($candidate))->toBeFalse();

    $current = $training->certify($authoriser, $candidate, [
        'reference' => 'CERT-CURRENT',
        'issued_at' => now()->toDateString(),
    ]);
    expect($training->isAuthorisedForLiveCalls($candidate))->toBeTrue();

    $this->actingAs(trainingManager())->post(route('training.certifications.revoke', $current), [
        'revocation_reason' => 'Failed a mandatory refresher assessment.',
    ])->assertForbidden();

    $this->actingAs($authoriser)->post(route('training.certifications.revoke', $current), [
        'revocation_reason' => 'Failed a mandatory refresher assessment.',
    ])->assertRedirect();
    expect($current->refresh()->status)->toBe('revoked')
        ->and($training->isAuthorisedForLiveCalls($candidate))->toBeFalse();
});

test('supervisors with live-call permission are authorised without a personal certification', function () {
    $supervisor = trainingUser(['view training', 'authorise live calls']);
    $training = app(Training::class);

    expect($training->isAuthorisedForLiveCalls($supervisor))->toBeTrue()
        ->and($training->activeCertification($supervisor))->toBeNull();

    $this->actingAs($supervisor)
        ->get(route('training.index', ['tab' => 'certification']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Training/Index')
            ->where('authorisedForLiveCalls', true)
            ->where('canAuthorise', true)
            ->has('certifications.data', 0));
});
