<?php

use App\Models\Incident;
use App\Models\QaCalibration;
use App\Models\QaReview;
use App\Models\User;
use App\Services\QualityAssurance;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;

/** @param list<string> $permissions */
function qaUser(array $permissions = ['view quality assurance']): User
{
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission);
    }
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

/** @param array<string, int> $overrides */
function qaScores(array $overrides = []): array
{
    $scores = [];
    foreach (array_keys(QualityAssurance::CRITERIA) as $criterion) {
        $scores[$criterion] = ['rating' => 5, 'note' => 'Evidence noted from the recorded call.'];
    }
    foreach ($overrides as $criterion => $rating) {
        $scores[$criterion] = ['rating' => $rating, 'note' => 'Evidence noted from the recorded call.'];
    }

    return $scores;
}

/** @param array<string, mixed> $overrides */
function qaPayload(User $agent, array $overrides = []): array
{
    return [
        'reference' => 'qa-call-0001',
        'agent_id' => $agent->id,
        'reviewed_at' => now()->toDateString(),
        'notes' => 'Sampled under the approved QA sampling policy.',
        'scores' => qaScores(),
        ...$overrides,
    ];
}

function qaManager(): User
{
    return qaUser(['view quality assurance', 'manage quality assurance']);
}

function qaFinalised(QaReview $review, User $manager, ?string $coachingDue = null): QaReview
{
    return app(QualityAssurance::class)->finalise($manager, $review, [
        'revision' => $review->revision,
        'coaching_due_at' => $coachingDue,
    ]);
}

test('quality assurance routes require authentication and separated scoring and approval permissions', function () {
    $this->get(route('quality-assurance.index'))->assertRedirect(route('login'));

    $viewer = qaUser();
    $this->actingAs($viewer)->get(route('quality-assurance.index'))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $agent = qaUser();
    $this->actingAs($viewer)->post(route('quality-assurance.reviews.store'), qaPayload($agent))->assertForbidden();

    $manager = qaManager();
    $this->actingAs($manager)->post(route('quality-assurance.reviews.store'), qaPayload($agent))->assertRedirect();
    $review = QaReview::sole();
    qaFinalised($review, $manager);
    $calibration = app(QualityAssurance::class)->addCalibration(qaUser(['view quality assurance', 'manage quality assurance']), $review->refresh(), [
        'secondary_total' => 90,
        'note' => 'Independent score.',
    ]);
    $this->actingAs($manager)
        ->post(route('quality-assurance.calibrations.agree', $calibration), ['agreed_total' => 90])
        ->assertForbidden();

    $approver = qaUser(['view quality assurance', 'approve quality assurance']);
    $this->actingAs($approver)
        ->post(route('quality-assurance.calibrations.agree', $calibration), ['agreed_total' => 90])
        ->assertRedirect();
    expect($calibration->refresh()->status)->toBe('agreed');
});

test('the weighted scorecard converts ratings into a one hundred point total', function () {
    $manager = qaManager();
    $agent = qaUser();

    $this->actingAs($manager)->post(route('quality-assurance.reviews.store'), qaPayload($agent, [
        'reference' => 'QA Call 0001',
        'scores' => qaScores(['empathy' => 3, 'capture' => 4, 'routing' => 2]),
    ]))->assertRedirect();

    $review = QaReview::sole();
    expect($review->reference)->toBe('qa-call-0001')
        ->and($review->status)->toBe('draft')
        ->and($review->total)->toBe(82)
        ->and($review->outcome)->toBe('pass')
        ->and($review->critical_fail)->toBeFalse()
        ->and($review->scores()->count())->toBe(7)
        ->and($review->scores()->where('criterion', 'safety')->sole()->points)->toBe(25)
        ->and($review->scores()->where('criterion', 'routing')->sole()->points)->toBe(6);
});

test('a critical privacy or safety rating overrides the total and requires a rationale', function () {
    $manager = qaManager();
    $agent = qaUser();

    $this->actingAs($manager)->post(route('quality-assurance.reviews.store'), qaPayload($agent, [
        'scores' => qaScores(['safety' => 1]),
    ]))->assertSessionHasErrors('critical_note');

    $this->actingAs($manager)->post(route('quality-assurance.reviews.store'), qaPayload($agent, [
        'scores' => qaScores(['safety' => 1]),
        'critical_note' => 'Red-flag answer was not escalated to the dentist.',
    ]))->assertRedirect();

    $review = QaReview::sole();
    expect($review->critical_fail)->toBeTrue()
        ->and($review->outcome)->toBe('critical_fail')
        ->and($review->total)->toBe(80);
    $stored = DB::table('qa_reviews')->where('id', $review->id)->value('critical_note');
    expect($stored)->toBeString()->not->toContain('Red-flag answer');
});

test('a critical fail finalisation raises an escalated incident with an assigned corrective action', function () {
    $this->freezeTime();
    $manager = qaManager();
    $agent = qaUser();
    app(QualityAssurance::class)->create($manager, qaPayload($agent, [
        'scores' => qaScores(['safety' => 0]),
        'critical_note' => 'Emergency symptoms were treated as routine.',
    ]));

    $review = QaReview::sole();
    $this->actingAs($manager)
        ->post(route('quality-assurance.reviews.finalise', $review), [
            'revision' => $review->revision,
            'coaching_due_at' => now()->addDays(2)->toDateString(),
        ])->assertRedirect();

    $review->refresh();
    expect($review->status)->toBe('finalised')
        ->and($review->coaching_required)->toBeTrue()
        ->and($review->coaching_due_at)->not->toBeNull();

    $incident = Incident::sole();
    expect($incident->category)->toBe('safety')
        ->and($incident->severity)->toBe('critical')
        ->and($incident->status)->toBe('investigating')
        ->and($incident->qa_review_id)->toBe($review->id)
        ->and($incident->assigned_to)->toBe($manager->id)
        ->and($incident->escalation_due_at?->timestamp)->toBe(now()->addHour()->timestamp)
        ->and($incident->actions()->count())->toBe(1)
        ->and($incident->actions()->sole()->owner_id)->toBe($manager->id);
    $this->assertDatabaseHas('activity_log', ['log_name' => 'quality-assurance', 'description' => 'QA review finalised']);
    $this->assertDatabaseHas('activity_log', ['log_name' => 'incidents', 'description' => 'Incident escalated to a named owner']);
});

test('remediation reviews need a coaching deadline and coaching can only be recorded once', function () {
    $manager = qaManager();
    $agent = qaUser();
    app(QualityAssurance::class)->create($manager, qaPayload($agent, [
        'reference' => 'qa-coaching-0001',
        'scores' => qaScores(['opening' => 3, 'empathy' => 3, 'capture' => 3, 'routing' => 3, 'closure' => 3]),
    ]));
    $review = QaReview::sole();

    $this->actingAs($manager)
        ->post(route('quality-assurance.reviews.finalise', $review), ['revision' => $review->revision])
        ->assertSessionHasErrors('coaching_due_at');

    $this->actingAs($manager)
        ->post(route('quality-assurance.reviews.finalise', $review), [
            'revision' => $review->revision,
            'coaching_due_at' => now()->addWeek()->toDateString(),
        ])->assertRedirect();
    expect($review->refresh()->outcome)->toBe('remediate')
        ->and($review->coaching_required)->toBeTrue();

    $this->actingAs($manager)
        ->post(route('quality-assurance.reviews.coaching', $review), ['coaching_note' => 'Agreed to repeat red-flag questions.'])
        ->assertRedirect();
    expect($review->refresh()->coaching_completed_at)->not->toBeNull()
        ->and($review->coached_by)->toBe($manager->id);

    $this->actingAs($manager)
        ->post(route('quality-assurance.reviews.coaching', $review), ['coaching_note' => 'Duplicate'])
        ->assertSessionHasErrors('coaching_completed_at');
});

test('finalised reviews are locked and calibration variances need a rationale', function () {
    $manager = qaManager();
    $agent = qaUser();
    app(QualityAssurance::class)->create($manager, qaPayload($agent));
    $review = QaReview::sole();

    $this->actingAs($manager)->patch(route('quality-assurance.reviews.update', $review), qaPayload($agent, [
        'revision' => 99,
    ]))->assertSessionHasErrors('revision');

    qaFinalised($review, $manager);
    $this->actingAs($manager)->patch(route('quality-assurance.reviews.update', $review), qaPayload($agent, [
        'revision' => $review->refresh()->revision,
    ]))->assertSessionHasErrors('revision');

    $secondReviewer = qaUser(['view quality assurance', 'manage quality assurance']);
    $this->actingAs($secondReviewer)->post(route('quality-assurance.calibrations.store', $review), [
        'secondary_total' => 60,
    ])->assertSessionHasErrors('note');

    $this->actingAs($secondReviewer)->post(route('quality-assurance.calibrations.store', $review), [
        'secondary_total' => 60,
        'note' => 'Sampling suggests the closure step was missed.',
    ])->assertRedirect();

    $calibration = QaCalibration::sole();
    expect($calibration->variance)->toBe(40)
        ->and($calibration->status)->toBe('open');
});

test('read-only viewers see the critical fail register without management controls', function () {
    $manager = qaManager();
    $agent = qaUser();
    app(QualityAssurance::class)->create($manager, qaPayload($agent, [
        'scores' => qaScores(['privacy' => 0]),
        'critical_note' => 'Consent was not confirmed before taking details.',
    ]));
    qaFinalised(QaReview::sole(), $manager, now()->addWeek()->toDateString());

    $this->actingAs(qaUser())
        ->get(route('quality-assurance.index', ['tab' => 'critical_fails']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('QualityAssurance/Index')
            ->has('reviews.data', 1)
            ->where('reviews.data.0.critical_fail', true)
            ->where('canManage', false)
            ->where('canApprove', false));
});
