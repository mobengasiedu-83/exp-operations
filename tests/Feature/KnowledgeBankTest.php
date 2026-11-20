<?php

use App\Models\ApprovalWorkflow;
use App\Models\KnowledgeArticle;
use App\Models\ScriptVersion;
use App\Models\Translation;
use App\Models\User;
use App\Services\KnowledgeBank;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;

/** @param list<string> $permissions */
function knowledgeUser(array $permissions = ['view knowledge bank']): User
{
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission);
    }
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

/** @return array<string, mixed> */
function knowledgeArticlePayload(array $overrides = []): array
{
    return [
        'reference' => 'emergency-red-flags',
        'category' => 'red_flag',
        'title' => 'Red flag screening prompts',
        'summary' => 'Approved prompts for emergency screening.',
        'body' => 'If the caller reports uncontrolled bleeding, call emergency services immediately.',
        'change_summary' => 'Initial reviewed wording.',
        'effective_from' => now()->toDateString(),
        ...$overrides,
    ];
}

/** @return array<string, mixed> */
function knowledgeDecision(string $area = 'clinical', string $decision = 'approved', array $overrides = []): array
{
    return [
        'area' => $area,
        'decision' => $decision,
        'approver_name' => $area === 'clinical' ? 'Dr Ama Mensah' : 'Brand Lead Nana',
        'reference' => $area === 'clinical' ? 'CLIN-001' : 'BRD-001',
        'note' => 'Reviewed against the approved playbook.',
        'attested' => true,
        ...$overrides,
    ];
}

/** @param array<string, mixed> $overrides */
function knowledgeDraft(User $manager, array $overrides = []): ScriptVersion
{
    $article = app(KnowledgeBank::class)->createArticle($manager, knowledgeArticlePayload($overrides));

    return $article->versions()->sole();
}

/** @param array<string, mixed> $overrides */
function knowledgeApproved(User $manager, array $overrides = []): ScriptVersion
{
    $bank = app(KnowledgeBank::class);
    $version = knowledgeDraft($manager, $overrides);
    $bank->submitForReview($manager, $version);
    $bank->decide($manager, $version->refresh(), knowledgeDecision('clinical'));
    $bank->decide($manager, $version->refresh(), knowledgeDecision('brand'));

    return $version->refresh();
}

/** @param array<string, mixed> $overrides */
function knowledgePublished(User $manager, array $overrides = []): ScriptVersion
{
    return app(KnowledgeBank::class)->publish(
        $manager,
        knowledgeApproved($manager, $overrides),
        ['effective_from' => now()->toDateString()],
    );
}

function knowledgeManager(): User
{
    return knowledgeUser(['view knowledge bank', 'manage knowledge bank', 'approve clinical knowledge', 'approve brand knowledge']);
}

test('knowledge bank routes require authentication and separated read, management and approval permissions', function () {
    $this->get(route('knowledge-bank.index'))->assertRedirect(route('login'));
    $this->get(route('knowledge-bank.offline'))->assertRedirect(route('login'));

    $viewer = knowledgeUser();
    $this->actingAs($viewer)->get(route('knowledge-bank.index'))->assertOk();
    $this->actingAs($viewer)->post(route('knowledge-bank.articles.store'), knowledgeArticlePayload())->assertForbidden();

    $manager = knowledgeUser(['view knowledge bank', 'manage knowledge bank']);
    $this->actingAs($manager)->post(route('knowledge-bank.articles.store'), knowledgeArticlePayload())->assertRedirect();
    $version = ScriptVersion::sole();
    $this->actingAs($manager)->post(route('knowledge-bank.versions.review', $version))->assertRedirect();
    $this->actingAs($manager)
        ->post(route('knowledge-bank.versions.decide', $version), knowledgeDecision('clinical'))
        ->assertForbidden();
    $this->actingAs($viewer)
        ->post(route('knowledge-bank.versions.publish', $version), ['effective_from' => now()->toDateString()])
        ->assertForbidden();

    $clinical = knowledgeUser(['view knowledge bank', 'approve clinical knowledge']);
    $this->actingAs($clinical)
        ->post(route('knowledge-bank.versions.decide', $version), knowledgeDecision('clinical'))
        ->assertRedirect();
    $this->actingAs($clinical)
        ->post(route('knowledge-bank.versions.decide', $version), knowledgeDecision('brand'))
        ->assertForbidden();
});

test('an article is created with a normalised reference and an editable draft version', function () {
    $manager = knowledgeManager();

    $this->actingAs($manager)
        ->post(route('knowledge-bank.articles.store'), knowledgeArticlePayload([
            'reference' => 'Emergency Red Flags',
            'review_due_at' => now()->addMonths(6)->toDateString(),
        ]))
        ->assertRedirect();

    $article = KnowledgeArticle::sole();
    $version = $article->versions()->sole();
    expect($article->reference)->toBe('emergency-red-flags')
        ->and($article->category)->toBe('red_flag')
        ->and($article->status)->toBe('draft')
        ->and($article->current_version_id)->toBeNull()
        ->and($article->created_by)->toBe($manager->id)
        ->and($article->review_due_at)->not->toBeNull()
        ->and($version->version)->toBe(1)
        ->and($version->language)->toBe('en')
        ->and($version->status)->toBe('draft')
        ->and($version->revision)->toBe(1)
        ->and($version->authored_by)->toBe($manager->id);
    $this->assertDatabaseHas('activity_log', [
        'log_name' => 'knowledge-bank',
        'description' => 'Knowledge article draft created',
    ]);
});

test('duplicate references and unsupported categories are rejected', function () {
    $manager = knowledgeManager();
    $this->actingAs($manager)->post(route('knowledge-bank.articles.store'), knowledgeArticlePayload())->assertRedirect();

    $this->actingAs($manager)
        ->post(route('knowledge-bank.articles.store'), knowledgeArticlePayload(['title' => 'Duplicate']))
        ->assertSessionHasErrors('reference');
    $this->actingAs($manager)
        ->post(route('knowledge-bank.articles.store'), knowledgeArticlePayload([
            'reference' => 'second-article',
            'category' => 'rumour',
        ]))
        ->assertSessionHasErrors('category');

    expect(KnowledgeArticle::query()->count())->toBe(1);
});

test('draft versions can only be edited with the current revision', function () {
    $manager = knowledgeManager();
    $version = knowledgeDraft($manager);

    $this->actingAs($manager)->patch(route('knowledge-bank.versions.update', $version), [
        'revision' => 99,
        'title' => $version->title,
        'body' => 'Stale edit',
        'change_summary' => null,
        'effective_from' => null,
    ])->assertSessionHasErrors('revision');

    $this->actingAs($manager)->patch(route('knowledge-bank.versions.update', $version), [
        'revision' => 1,
        'title' => 'Reviewed red flag wording',
        'body' => 'Replacement approved wording.',
        'change_summary' => 'Clarified the emergency instruction.',
        'effective_from' => now()->toDateString(),
    ])->assertRedirect();

    expect($version->refresh()->revision)->toBe(2)
        ->and($version->body)->toBe('Replacement approved wording.')
        ->and($version->title)->toBe('Reviewed red flag wording');
});

test('review requests create pending clinical and brand decisions', function () {
    $manager = knowledgeManager();
    $version = knowledgeDraft($manager);

    $this->actingAs($manager)->post(route('knowledge-bank.versions.review', $version))->assertRedirect();

    expect($version->refresh()->status)->toBe('in_review');
    $approvals = ApprovalWorkflow::query()->get();
    expect($approvals)->toHaveCount(2)
        ->and($approvals->pluck('area')->sort()->values()->all())->toBe(['brand', 'clinical'])
        ->and($approvals->pluck('decision')->unique()->values()->all())->toBe(['pending'])
        ->and($approvals->pluck('requested_by')->unique()->values()->all())->toBe([$manager->id]);

    $this->actingAs($manager)
        ->post(route('knowledge-bank.versions.review', $version))
        ->assertSessionHasErrors('status');
});

test('publishing requires clinical and brand approval and records the effective date', function () {
    $manager = knowledgeManager();
    $version = knowledgeDraft($manager);
    $this->actingAs($manager)->post(route('knowledge-bank.versions.review', $version))->assertRedirect();

    $this->actingAs($manager)
        ->post(route('knowledge-bank.versions.decide', $version), knowledgeDecision('clinical'))
        ->assertRedirect();
    expect($version->refresh()->status)->toBe('in_review');
    $this->actingAs($manager)
        ->post(route('knowledge-bank.versions.publish', $version), ['effective_from' => now()->toDateString()])
        ->assertSessionHasErrors('status');

    $this->actingAs($manager)
        ->post(route('knowledge-bank.versions.decide', $version), knowledgeDecision('brand'))
        ->assertRedirect();
    expect($version->refresh()->status)->toBe('approved');

    $this->actingAs($manager)
        ->post(route('knowledge-bank.versions.publish', $version), ['effective_from' => now()->toDateString()])
        ->assertRedirect();

    $version->refresh();
    expect($version->status)->toBe('published')
        ->and($version->published_at)->not->toBeNull()
        ->and($version->effective_from?->toDateString())->toBe(now()->toDateString())
        ->and($version->published_by)->toBe($manager->id);
    $article = KnowledgeArticle::sole();
    expect($article->status)->toBe('published')
        ->and($article->current_version_id)->toBe($version->id);
    $this->assertDatabaseHas('activity_log', [
        'log_name' => 'knowledge-bank',
        'description' => 'Approved knowledge wording published',
    ]);
});

test('publishing a new version retires the previously published wording', function () {
    $manager = knowledgeManager();
    $bank = app(KnowledgeBank::class);
    $first = knowledgePublished($manager);
    $article = KnowledgeArticle::sole();

    $second = $bank->createVersion($manager, $article, [
        'language' => 'en',
        'title' => 'Revised red flag prompts',
        'body' => 'Revised approved wording.',
        'change_summary' => 'Clarified the escalation sentence.',
        'effective_from' => null,
    ]);
    $bank->submitForReview($manager, $second);
    $bank->decide($manager, $second->refresh(), knowledgeDecision('clinical'));
    $bank->decide($manager, $second->refresh(), knowledgeDecision('brand'));
    $bank->publish($manager, $second->refresh(), ['effective_from' => now()->addDay()->toDateString()]);

    expect($first->refresh()->status)->toBe('retired')
        ->and($first->effective_to?->toDateString())->toBe(now()->toDateString())
        ->and($second->refresh()->status)->toBe('published')
        ->and($article->refresh()->current_version_id)->toBe($second->id);
});

test('a request for changes returns a version to draft and keeps prior approvals', function () {
    $manager = knowledgeManager();
    $bank = app(KnowledgeBank::class);
    $version = knowledgeDraft($manager);
    $bank->submitForReview($manager, $version);
    $bank->decide($manager, $version->refresh(), knowledgeDecision('clinical'));
    $approval = $bank->decide($manager, $version->refresh(), knowledgeDecision('brand', 'changes_requested', [
        'note' => 'Brand tone needs revision before approval.',
    ]));

    expect($version->refresh()->status)->toBe('draft')
        ->and($approval->decision)->toBe('changes_requested')
        ->and($version->approvals()->where('area', 'clinical')->sole()->decision)->toBe('approved');
    $this->assertDatabaseMissing('approval_workflows', ['note' => 'Brand tone needs revision before approval.']);

    $bank->submitForReview($manager, $version->refresh());
    expect($version->refresh()->status)->toBe('in_review')
        ->and($version->approvals()->where('area', 'clinical')->sole()->decision)->toBe('approved')
        ->and($version->approvals()->where('area', 'brand')->sole()->decision)->toBe('pending');

    $bank->decide($manager, $version->refresh(), knowledgeDecision('brand'));
    expect($version->refresh()->status)->toBe('approved');
});

test('assistants only see the current published wording', function () {
    $manager = knowledgeManager();
    $published = knowledgePublished($manager);
    $article = KnowledgeArticle::sole();
    app(KnowledgeBank::class)->createVersion($manager, $article, [
        'language' => 'en',
        'title' => 'Unpublished revision',
        'body' => 'Unpublished wording.',
        'change_summary' => null,
        'effective_from' => null,
    ]);
    app(KnowledgeBank::class)->createArticle($manager, knowledgeArticlePayload([
        'reference' => 'draft-outage-notice',
        'category' => 'outage',
        'title' => 'Draft outage notice',
        'body' => 'Draft outage wording.',
    ]));

    $viewer = knowledgeUser();
    $this->actingAs($viewer)->get(route('knowledge-bank.index', ['tab' => 'scripts']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('KnowledgeBank/Index')
            ->has('versions.data', 1)
            ->where('versions.data.0.id', $published->id)
            ->where('versions.data.0.status', 'published')
            ->where('approvals', null)
            ->where('seesDrafts', false));
    $this->actingAs($viewer)->get(route('knowledge-bank.index', ['tab' => 'red_flags']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('articles.data', 1));
});

test('search only matches wording visible to the operator', function () {
    $manager = knowledgeManager();
    knowledgePublished($manager, ['body' => 'Uncontrolled bleeding requires emergency escalation.']);
    app(KnowledgeBank::class)->createArticle($manager, knowledgeArticlePayload([
        'reference' => 'draft-bleeding-note',
        'category' => 'faq',
        'title' => 'Draft bleeding note',
        'body' => 'Uncontrolled bleeding draft guidance.',
    ]));

    $viewer = knowledgeUser();
    $this->actingAs($viewer)->get(route('knowledge-bank.index', ['tab' => 'scripts', 'q' => 'bleeding']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('versions.data', 1)
            ->where('versions.data.0.status', 'published'));
    $this->actingAs($viewer)->get(route('knowledge-bank.index', ['tab' => 'scripts', 'q' => 'draft guidance']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('versions.data', 0));
});

test('retiring an article withdraws it from current guidance', function () {
    $manager = knowledgeManager();
    $version = knowledgePublished($manager);
    $article = KnowledgeArticle::sole();

    $this->actingAs($manager)->post(route('knowledge-bank.articles.retire', $article))->assertRedirect();

    expect($article->refresh()->status)->toBe('retired')
        ->and($article->current_version_id)->toBeNull()
        ->and($version->refresh()->status)->toBe('retired')
        ->and($version->retired_at)->not->toBeNull();
    $this->actingAs(knowledgeUser())->get(route('knowledge-bank.offline'))
        ->assertOk()
        ->assertJsonCount(0, 'articles');
});

test('translations require an approved source version and a new language', function () {
    $manager = knowledgeManager();
    $draft = knowledgeDraft($manager);

    $this->actingAs($manager)->post(route('knowledge-bank.translations.store'), [
        'version_id' => $draft->id,
        'language' => 'tw',
        'title' => 'Twi title',
        'body' => 'Twi wording',
        'effective_from' => null,
    ])->assertSessionHasErrors('version_id');

    $version = knowledgeApproved($manager, ['reference' => 'second-script']);
    $payload = [
        'version_id' => $version->id,
        'language' => 'en',
        'title' => 'English title',
        'body' => 'English wording',
        'effective_from' => null,
    ];
    $this->actingAs($manager)->post(route('knowledge-bank.translations.store'), $payload)->assertSessionHasErrors('language');
    $this->actingAs($manager)->post(route('knowledge-bank.translations.store'), [
        ...$payload,
        'language' => 'tw',
        'title' => 'Twi approved wording',
    ])->assertRedirect();

    $translation = Translation::sole();
    expect($translation->status)->toBe('draft')
        ->and($translation->knowledge_article_id)->toBe($version->knowledge_article_id)
        ->and($translation->language)->toBe('tw');

    $this->actingAs($manager)->post(route('knowledge-bank.translations.store'), [
        ...$payload,
        'language' => 'tw',
    ])->assertSessionHasErrors('language');
});

test('a second draft for the same language is blocked until it is published or retired', function () {
    $manager = knowledgeManager();
    $version = knowledgeDraft($manager);

    $this->actingAs($manager)->post(route('knowledge-bank.versions.store'), [
        'article_id' => $version->knowledge_article_id,
        'language' => 'en',
        'title' => 'Competing draft',
        'body' => 'Competing wording.',
        'change_summary' => null,
        'effective_from' => null,
    ])->assertSessionHasErrors('language');

    expect($version->knowledge_article_id)->not->toBeNull();
    expect(ScriptVersion::query()->count())->toBe(1);
});

test('language variants require brand approval before assistants see them', function () {
    $manager = knowledgeManager();
    $bank = app(KnowledgeBank::class);
    $version = knowledgePublished($manager);
    $approved = $bank->addTranslation($manager, $version, [
        'language' => 'tw', 'title' => 'Twi emergency wording', 'body' => 'Twi approved body', 'effective_from' => null,
    ]);
    $bank->approveTranslation($manager, $approved, [
        'approver_name' => 'Brand Lead Nana', 'reference' => 'BRD-002', 'effective_from' => now()->toDateString(),
    ]);
    $draft = $bank->addTranslation($manager, $version, [
        'language' => 'gaa', 'title' => 'Ga emergency wording', 'body' => 'Ga draft body', 'effective_from' => null,
    ]);

    $viewer = knowledgeUser();
    $this->actingAs($viewer)->get(route('knowledge-bank.index', ['tab' => 'translations']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('translations.data', 1)
            ->where('translations.data.0.language', 'tw')
            ->where('translations.data.0.status', 'approved'));

    $this->actingAs(knowledgeUser(['view knowledge bank', 'manage knowledge bank']))
        ->post(route('knowledge-bank.translations.approve', $draft), [
            'approver_name' => 'Brand Lead Nana',
            'reference' => 'BRD-003',
            'effective_from' => now()->toDateString(),
            'attested' => true,
        ])->assertForbidden();

    $this->actingAs(knowledgeUser(['view knowledge bank', 'approve brand knowledge']))
        ->post(route('knowledge-bank.translations.approve', $draft), [
            'approver_name' => 'Brand Lead Nana',
            'reference' => 'BRD-003',
            'effective_from' => now()->toDateString(),
            'attested' => true,
        ])->assertRedirect();

    expect($draft->refresh()->status)->toBe('approved')
        ->and($draft->approval_reference)->toBe('BRD-003')
        ->and($draft->approved_at)->not->toBeNull();
});

test('offline guidance contains only approved emergency and outage wording', function () {
    $manager = knowledgeManager();
    $bank = app(KnowledgeBank::class);
    $version = knowledgePublished($manager);
    $translation = $bank->addTranslation($manager, $version, [
        'language' => 'tw', 'title' => 'Twi emergency wording', 'body' => 'Twi emergency body', 'effective_from' => null,
    ]);
    $bank->approveTranslation($manager, $translation, [
        'approver_name' => 'Brand Lead Nana', 'reference' => 'BRD-004', 'effective_from' => now()->toDateString(),
    ]);
    knowledgePublished($manager, [
        'reference' => 'opening-hours-faq',
        'category' => 'faq',
        'title' => 'Opening hours',
        'body' => 'Approved opening hours wording.',
    ]);
    $bank->createArticle($manager, knowledgeArticlePayload([
        'reference' => 'draft-outage',
        'category' => 'outage',
        'title' => 'Draft outage notice',
        'body' => 'Draft outage wording.',
    ]));

    $response = $this->actingAs(knowledgeUser())->get(route('knowledge-bank.offline'));

    $response->assertOk()
        ->assertJsonCount(1, 'articles')
        ->assertJsonPath('articles.0.reference', 'emergency-red-flags')
        ->assertJsonPath('articles.0.category', 'red_flag')
        ->assertJsonCount(1, 'articles.0.translations')
        ->assertJsonPath('articles.0.translations.0.language', 'tw');
    expect($response->headers->get('Cache-Control'))->toContain('max-age=600');
});

test('knowledge bank pages are excluded from browser caches', function () {
    $response = $this->actingAs(knowledgeUser())->get(route('knowledge-bank.index'));

    $response->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('publishing cannot backdate approved wording', function () {
    $manager = knowledgeManager();
    $version = knowledgeApproved($manager);

    $this->actingAs($manager)
        ->post(route('knowledge-bank.versions.publish', $version), ['effective_from' => now()->subDay()->toDateString()])
        ->assertSessionHasErrors('effective_from');

    expect($version->refresh()->status)->toBe('approved')
        ->and(KnowledgeArticle::sole()->status)->toBe('draft');
});
