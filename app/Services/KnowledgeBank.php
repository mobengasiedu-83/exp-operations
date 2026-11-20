<?php

namespace App\Services;

use App\Models\ApprovalWorkflow;
use App\Models\KnowledgeArticle;
use App\Models\ScriptVersion;
use App\Models\Translation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class KnowledgeBank
{
    /**
     * Supported language variants for approved wording.
     *
     * @var array<string, string>
     */
    public const LANGUAGES = [
        'en' => 'English',
        'tw' => 'Twi',
        'gaa' => 'Ga',
        'ee' => 'Ewe',
        'ha' => 'Hausa',
        'dag' => 'Dagbani',
        'fr' => 'French',
    ];

    /**
     * Named approval areas recorded for every reviewed version.
     *
     * @var list<string>
     */
    public const AREAS = ['clinical', 'brand'];

    /** @param array<string, mixed> $data */
    public function createArticle(User $user, array $data): KnowledgeArticle
    {
        return DB::transaction(function () use ($user, $data): KnowledgeArticle {
            $reference = Str::slug((string) $data['reference']);
            $this->require($reference !== '', 'reference', 'Enter a reference containing letters or numbers.');
            $this->require(
                ! KnowledgeArticle::query()->where('reference', $reference)->exists(),
                'reference',
                'This reference is already used by another knowledge article.',
            );
            $article = KnowledgeArticle::query()->create([
                'reference' => $reference,
                'category' => $data['category'],
                'title' => $data['title'],
                'summary' => $data['summary'] ?? null,
                'status' => 'draft',
                'created_by' => $user->id,
                'review_due_at' => $this->date($data['review_due_at'] ?? null),
            ]);
            $version = $article->versions()->create([
                'version' => 1,
                'language' => 'en',
                'title' => $data['title'],
                'body' => $data['body'],
                'change_summary' => $data['change_summary'] ?? null,
                'status' => 'draft',
                'effective_from' => $this->date($data['effective_from'] ?? null),
                'authored_by' => $user->id,
                'revision' => 1,
            ]);
            activity('knowledge-bank')->causedBy($user)->performedOn($article)->withProperties([
                'operation' => 'article_created', 'version_id' => $version->id, 'category' => $article->category,
            ])->log('Knowledge article draft created');

            return $article->load('versions');
        });
    }

    /** @param array<string, mixed> $data */
    public function createVersion(User $user, KnowledgeArticle $article, array $data): ScriptVersion
    {
        return DB::transaction(function () use ($user, $article, $data): ScriptVersion {
            $article = KnowledgeArticle::query()->lockForUpdate()->findOrFail($article->id);
            $this->require(! $article->isRetired(), 'knowledge_article_id', 'Retired articles cannot receive new versions.');
            $language = (string) ($data['language'] ?? 'en');
            $this->require(array_key_exists($language, self::LANGUAGES), 'language', 'Choose a supported language.');
            $this->require(
                ! $article->versions()->where('language', $language)->whereIn('status', ['draft', 'in_review'])->exists(),
                'language',
                'A draft or review version already exists for this language. Publish or retire it before starting another.',
            );
            $version = $article->versions()->create([
                'version' => ((int) $article->versions()->max('version')) + 1,
                'language' => $language,
                'title' => $data['title'],
                'body' => $data['body'],
                'change_summary' => $data['change_summary'] ?? null,
                'status' => 'draft',
                'effective_from' => $this->date($data['effective_from'] ?? null),
                'authored_by' => $user->id,
                'revision' => 1,
            ]);
            activity('knowledge-bank')->causedBy($user)->performedOn($version)->withProperties([
                'operation' => 'version_drafted', 'version' => $version->version, 'language' => $version->language,
            ])->log('Knowledge version drafted');

            return $version;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateVersion(User $user, ScriptVersion $version, array $data): ScriptVersion
    {
        return DB::transaction(function () use ($user, $version, $data): ScriptVersion {
            $version = ScriptVersion::query()->lockForUpdate()->findOrFail($version->id);
            $this->require($version->isDraft(), 'revision', 'Only draft versions can be edited.');
            $this->require(
                $version->revision === (int) $data['revision'],
                'revision',
                'This version changed since it was loaded. Reload before saving.',
            );
            $version->update([
                'title' => $data['title'],
                'body' => $data['body'],
                'change_summary' => $data['change_summary'] ?? null,
                'effective_from' => $this->date($data['effective_from'] ?? null),
                'revision' => $version->revision + 1,
            ]);
            activity('knowledge-bank')->causedBy($user)->performedOn($version)->withProperties([
                'operation' => 'version_updated', 'revision' => $version->revision,
            ])->log('Knowledge version draft updated');

            return $version->refresh();
        });
    }

    public function submitForReview(User $user, ScriptVersion $version): ScriptVersion
    {
        return DB::transaction(function () use ($user, $version): ScriptVersion {
            $version = ScriptVersion::query()->lockForUpdate()->findOrFail($version->id);
            $article = KnowledgeArticle::query()->findOrFail($version->knowledge_article_id);
            $this->require(! $article->isRetired(), 'article', 'A retired article cannot be submitted for review.');
            $this->require($version->isDraft(), 'status', 'Only draft versions can be submitted for review.');
            $this->require(trim((string) $version->body) !== '', 'body', 'Add the full wording before requesting approval.');
            foreach (self::AREAS as $area) {
                $approval = $version->approvals()->firstOrNew(['area' => $area]);
                if ($approval->decision === 'approved') {
                    continue;
                }
                $approval->fill([
                    'knowledge_article_id' => $version->knowledge_article_id,
                    'decision' => 'pending',
                    'requested_by' => $user->id,
                    'approver_id' => null,
                    'approver_name' => null,
                    'reference' => null,
                    'note' => null,
                    'decided_at' => null,
                ])->save();
            }
            $version->update(['status' => 'in_review', 'revision' => $version->revision + 1]);
            activity('knowledge-bank')->causedBy($user)->performedOn($version)->withProperties([
                'operation' => 'review_requested', 'areas' => self::AREAS,
            ])->log('Knowledge version submitted for approval');

            return $version->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function decide(User $user, ScriptVersion $version, array $data): ApprovalWorkflow
    {
        return DB::transaction(function () use ($user, $version, $data): ApprovalWorkflow {
            $version = ScriptVersion::query()->lockForUpdate()->findOrFail($version->id);
            $article = KnowledgeArticle::query()->findOrFail($version->knowledge_article_id);
            $this->require(! $article->isRetired(), 'article', 'A retired article cannot receive approval decisions.');
            $this->require($version->status === 'in_review', 'status', 'Only versions under review can receive a decision.');
            $area = (string) $data['area'];
            $approval = $version->approvals()->where('area', $area)->lockForUpdate()->first();
            $this->require(
                $approval !== null && $approval->isPending(),
                'area',
                'No pending approval request exists for this area.',
            );
            $decision = (string) $data['decision'];
            $approval->update([
                'decision' => $decision,
                'approver_id' => $user->id,
                'approver_name' => $data['approver_name'],
                'reference' => $data['reference'],
                'note' => $data['note'] ?? null,
                'decided_at' => now(),
            ]);
            if ($decision === 'changes_requested') {
                $version->update(['status' => 'draft', 'revision' => $version->revision + 1]);
            } elseif ($version->approvals()->where('decision', 'approved')->count() === count(self::AREAS)) {
                $version->update(['status' => 'approved', 'revision' => $version->revision + 1]);
            }
            activity('knowledge-bank')->causedBy($user)->performedOn($version)->withProperties([
                'operation' => 'approval_decided', 'area' => $area, 'decision' => $decision,
                'approver_name' => $approval->approver_name, 'reference' => $approval->reference,
            ])->log('Knowledge approval decision recorded');

            return $approval->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function publish(User $user, ScriptVersion $version, array $data): ScriptVersion
    {
        return DB::transaction(function () use ($user, $version, $data): ScriptVersion {
            $version = ScriptVersion::query()->lockForUpdate()->findOrFail($version->id);
            $article = KnowledgeArticle::query()->lockForUpdate()->findOrFail($version->knowledge_article_id);
            $this->require(! $article->isRetired(), 'article', 'A retired article cannot publish new wording.');
            $this->require($version->status === 'approved', 'status', 'Only fully approved versions can be published.');
            $this->require(
                $version->approvals()->where('decision', 'approved')->count() === count(self::AREAS),
                'status',
                'Clinical and brand approvals must both be recorded before publishing.',
            );
            $effective = $this->date($data['effective_from'] ?? null);
            $this->require($effective !== null, 'effective_from', 'Record the date this wording takes effect.');
            $this->require(
                ! $effective->lessThan(now()->startOfDay()),
                'effective_from',
                'Publishing cannot backdate approved wording. Choose today or a future date.',
            );
            $superseded = $article->versions()
                ->where('language', $version->language)
                ->where('status', 'published')
                ->whereKeyNot($version->id)
                ->get();
            foreach ($superseded as $old) {
                $old->update([
                    'status' => 'retired',
                    'effective_to' => $effective->subDay(),
                    'retired_at' => now(),
                    'revision' => $old->revision + 1,
                ]);
            }
            $version->update([
                'status' => 'published',
                'effective_from' => $effective,
                'published_by' => $user->id,
                'published_at' => now(),
                'revision' => $version->revision + 1,
            ]);
            $article->update(['status' => 'published', 'current_version_id' => $version->id]);
            activity('knowledge-bank')->causedBy($user)->performedOn($version)->withProperties([
                'operation' => 'published', 'effective_from' => $effective->toDateString(),
                'superseded' => $superseded->pluck('id')->all(),
            ])->log('Approved knowledge wording published');

            return $version->refresh();
        });
    }

    public function retireVersion(User $user, ScriptVersion $version): ScriptVersion
    {
        return DB::transaction(function () use ($user, $version): ScriptVersion {
            $version = ScriptVersion::query()->lockForUpdate()->findOrFail($version->id);
            $this->require($version->isPublished(), 'status', 'Only published versions can be retired.');
            $article = KnowledgeArticle::query()->lockForUpdate()->findOrFail($version->knowledge_article_id);
            $version->update([
                'status' => 'retired',
                'effective_to' => now()->toDateString(),
                'retired_at' => now(),
                'revision' => $version->revision + 1,
            ]);
            if ((int) $article->current_version_id === $version->id) {
                $article->update(['status' => 'retired', 'current_version_id' => null]);
            }
            activity('knowledge-bank')->causedBy($user)->performedOn($version)->withProperties([
                'operation' => 'version_retired',
            ])->log('Knowledge version retired');

            return $version->refresh();
        });
    }

    public function retireArticle(User $user, KnowledgeArticle $article): KnowledgeArticle
    {
        return DB::transaction(function () use ($user, $article): KnowledgeArticle {
            $article = KnowledgeArticle::query()->lockForUpdate()->findOrFail($article->id);
            foreach ($article->versions()->whereIn('status', ['published', 'approved'])->get() as $version) {
                $version->update([
                    'status' => 'retired',
                    'effective_to' => now()->toDateString(),
                    'retired_at' => now(),
                    'revision' => $version->revision + 1,
                ]);
            }
            $article->update(['status' => 'retired', 'current_version_id' => null]);
            activity('knowledge-bank')->causedBy($user)->performedOn($article)->withProperties([
                'operation' => 'article_retired',
            ])->log('Knowledge article retired');

            return $article->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function addTranslation(User $user, ScriptVersion $version, array $data): Translation
    {
        return DB::transaction(function () use ($user, $version, $data): Translation {
            $version = ScriptVersion::query()->lockForUpdate()->findOrFail($version->id);
            $this->require(
                in_array($version->status, ['approved', 'published'], true),
                'version_id',
                'Translations require an approved or published source version.',
            );
            $language = (string) $data['language'];
            $this->require(array_key_exists($language, self::LANGUAGES), 'language', 'Choose a supported language.');
            $this->require($language !== $version->language, 'language', 'Choose a language other than the source wording language.');
            $this->require(
                ! $version->translations()->where('language', $language)->exists(),
                'language',
                'This language variant already exists for the selected version.',
            );
            $translation = $version->translations()->create([
                'knowledge_article_id' => $version->knowledge_article_id,
                'language' => $language,
                'title' => $data['title'],
                'body' => $data['body'],
                'status' => 'draft',
                'effective_from' => $this->date($data['effective_from'] ?? null),
                'translated_by' => $user->id,
                'revision' => 1,
            ]);
            activity('knowledge-bank')->causedBy($user)->performedOn($translation)->withProperties([
                'operation' => 'translation_drafted', 'language' => $language, 'version_id' => $version->id,
            ])->log('Language variant drafted');

            return $translation;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateTranslation(User $user, Translation $translation, array $data): Translation
    {
        return DB::transaction(function () use ($user, $translation, $data): Translation {
            $translation = Translation::query()->lockForUpdate()->findOrFail($translation->id);
            $this->require($translation->status === 'draft', 'revision', 'Only draft language variants can be edited.');
            $this->require(
                $translation->revision === (int) $data['revision'],
                'revision',
                'This language variant changed since it was loaded. Reload before saving.',
            );
            $translation->update([
                'title' => $data['title'],
                'body' => $data['body'],
                'effective_from' => $this->date($data['effective_from'] ?? null),
                'revision' => $translation->revision + 1,
            ]);
            activity('knowledge-bank')->causedBy($user)->performedOn($translation)->withProperties([
                'operation' => 'translation_updated', 'revision' => $translation->revision,
            ])->log('Language variant updated');

            return $translation->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function approveTranslation(User $user, Translation $translation, array $data): Translation
    {
        return DB::transaction(function () use ($user, $translation, $data): Translation {
            $translation = Translation::query()->lockForUpdate()->findOrFail($translation->id);
            $this->require($translation->status === 'draft', 'status', 'Only draft language variants can be approved.');
            $this->require(trim((string) $translation->body) !== '', 'body', 'Add the translated wording before approval.');
            $effective = $this->date($data['effective_from'] ?? null);
            $this->require($effective !== null, 'effective_from', 'Record the date this language variant takes effect.');
            $translation->update([
                'status' => 'approved',
                'effective_from' => $effective,
                'approved_by' => $user->id,
                'approved_at' => now(),
                'approval_reference' => $data['reference'],
                'revision' => $translation->revision + 1,
            ]);
            activity('knowledge-bank')->causedBy($user)->performedOn($translation)->withProperties([
                'operation' => 'translation_approved', 'approver_name' => $data['approver_name'],
                'reference' => $data['reference'],
            ])->log('Language variant approved');

            return $translation->refresh();
        });
    }

    public function retireTranslation(User $user, Translation $translation): Translation
    {
        return DB::transaction(function () use ($user, $translation): Translation {
            $translation = Translation::query()->lockForUpdate()->findOrFail($translation->id);
            $this->require($translation->status !== 'retired', 'status', 'This language variant is already retired.');
            $translation->update(['status' => 'retired', 'revision' => $translation->revision + 1]);
            activity('knowledge-bank')->causedBy($user)->performedOn($translation)->withProperties([
                'operation' => 'translation_retired',
            ])->log('Language variant retired');

            return $translation->refresh();
        });
    }

    /**
     * Build the approved emergency, red-flag and outage snapshot used offline.
     *
     * @return array{generated_at: string, articles: list<array<string, mixed>>}
     */
    public function offlineGuidance(): array
    {
        $articles = KnowledgeArticle::query()
            ->whereIn('category', KnowledgeArticle::OFFLINE_CATEGORIES)
            ->published()
            ->with(['currentVersion.translations' => fn ($query) => $query->where('status', 'approved')->orderBy('language')])
            ->orderBy('category')
            ->orderBy('title')
            ->get();

        $items = [];
        foreach ($articles as $article) {
            $version = $article->currentVersion;
            if (! $version instanceof ScriptVersion) {
                continue;
            }
            $translations = [];
            foreach ($version->translations as $translation) {
                $translations[] = [
                    'language' => $translation->language,
                    'title' => $translation->title,
                    'body' => $translation->body,
                    'effective_from' => $translation->effective_from?->toDateString(),
                ];
            }
            $items[] = [
                'reference' => $article->reference,
                'category' => $article->category,
                'title' => $article->title,
                'summary' => $article->summary,
                'version' => $version->version,
                'language' => $version->language,
                'effective_from' => $version->effective_from?->toDateString(),
                'body' => $version->body,
                'translations' => $translations,
            ];
        }

        return ['generated_at' => now()->toIso8601String(), 'articles' => $items];
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value)->startOfDay();
    }

    private function require(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
