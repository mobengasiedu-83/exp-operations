<?php

namespace App\Http\Controllers;

use App\Models\ApprovalWorkflow;
use App\Models\KnowledgeArticle;
use App\Models\ScriptVersion;
use App\Models\Translation;
use App\Models\User;
use App\Services\KnowledgeBank;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class KnowledgeBankController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('view knowledge bank');
        $user = $this->user($request);
        $canManage = $user->can('manage knowledge bank');
        $canClinical = $user->can('approve clinical knowledge');
        $canBrand = $user->can('approve brand knowledge');
        $seesDrafts = $canManage || $canClinical || $canBrand;
        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(['faqs', 'scripts', 'red_flags', 'referral_guidance', 'translations', 'approvals'])],
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', Rule::in(KnowledgeArticle::CATEGORIES)],
            'status' => ['nullable', Rule::in(ScriptVersion::STATUSES)],
            'language' => ['nullable', Rule::in(array_keys(KnowledgeBank::LANGUAGES))],
            'article_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $tab = $filters['tab'] ?? 'faqs';
        $term = trim((string) ($filters['q'] ?? ''));
        $category = $filters['category'] ?? match ($tab) {
            'faqs' => 'faq',
            'red_flags' => 'red_flag',
            'referral_guidance' => 'referral_guidance',
            default => null,
        };
        $articles = KnowledgeArticle::query()
            ->when(! $seesDrafts, fn (Builder $query) => $query->published())
            ->when($category, fn (Builder $query, string $value) => $query->where('category', $value))
            ->when($term !== '', fn (Builder $query) => $query->search($term))
            ->with(['currentVersion:id,version,language,status,effective_from', 'creator:id,name'])
            ->withCount('versions')
            ->orderBy('category')
            ->orderBy('title')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (KnowledgeArticle $article): array => [
                'id' => $article->id,
                'reference' => $article->reference,
                'category' => $article->category,
                'title' => $article->title,
                'summary' => $article->summary,
                'status' => $article->status,
                'current_version' => $article->currentVersion === null
                    ? null
                    : 'v'.$article->currentVersion->version.' · '.$article->currentVersion->language,
                'effective_from' => $article->currentVersion?->effective_from?->toDateString(),
                'review_due_at' => $article->review_due_at?->toIso8601String(),
                'versions' => (int) $article->getAttribute('versions_count'),
                'owner' => $article->creator?->name,
                'revision' => $article->currentVersion?->revision,
            ]);
        $versions = ScriptVersion::query()
            ->with('article:id,reference,category,title,status')
            ->when(! $seesDrafts, fn (Builder $query) => $query->where('status', 'published')
                ->whereHas('article', fn (Builder $article) => $article->where('status', 'published')))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['language'] ?? null, fn (Builder $query, string $language) => $query->where('language', $language))
            ->when($filters['article_id'] ?? null, fn (Builder $query, int $articleId) => $query->where('knowledge_article_id', $articleId))
            ->when($term !== '', fn (Builder $query) => $query->where(function (Builder $inner) use ($term): void {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $inner->where('title', 'like', $like)->orWhere('body', 'like', $like)
                    ->orWhereHas('article', fn (Builder $article) => $article->where('reference', 'like', $like)->orWhere('title', 'like', $like));
            }))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (ScriptVersion $version): array => [
                'id' => $version->id,
                'article_id' => $version->knowledge_article_id,
                'reference' => $version->article?->reference,
                'article_status' => $version->article?->status,
                'category' => $version->article?->category,
                'title' => $version->title,
                'body' => $version->body,
                'version' => 'v'.$version->version.' · '.$version->language,
                'status' => $version->status,
                'change_summary' => $version->change_summary,
                'effective_from' => $version->effective_from?->toDateString(),
                'effective_to' => $version->effective_to?->toDateString(),
                'revision' => $version->revision,
                'clinical' => $version->approval('clinical')->decision ?? 'not_requested',
                'brand' => $version->approval('brand')->decision ?? 'not_requested',
            ]);
        $approvals = $seesDrafts ? ApprovalWorkflow::query()
            ->with(['article:id,reference,title', 'version:id,version,language,status,title'])
            ->when($term !== '', fn (Builder $query) => $query->where(function (Builder $inner) use ($term): void {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $inner->whereHas('article', fn (Builder $article) => $article->where('reference', 'like', $like)->orWhere('title', 'like', $like))
                    ->orWhere('approver_name', 'like', $like);
            }))
            ->orderBy('decided_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (ApprovalWorkflow $approval): array => [
                'id' => $approval->id,
                'reference' => $approval->article?->reference,
                'article_title' => $approval->article?->title,
                'version' => $approval->version === null ? null : 'v'.$approval->version->version.' · '.$approval->version->language,
                'version_status' => $approval->version?->status,
                'area' => $approval->area,
                'decision' => $approval->decision,
                'approver_name' => $approval->approver_name,
                'decision_reference' => $approval->reference,
                'decided_at' => $approval->decided_at?->toIso8601String(),
            ]) : null;
        $translations = Translation::query()
            ->with(['article:id,reference,title,status', 'version:id,version,language,status'])
            ->when(! $seesDrafts, fn (Builder $query) => $query->where('status', 'approved')
                ->whereHas('version', fn (Builder $version) => $version->where('status', 'published')
                    ->whereHas('article', fn (Builder $article) => $article->where('status', 'published'))))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['language'] ?? null, fn (Builder $query, string $language) => $query->where('language', $language))
            ->when($filters['article_id'] ?? null, fn (Builder $query, int $articleId) => $query->where('knowledge_article_id', $articleId))
            ->when($term !== '', fn (Builder $query) => $query->where(function (Builder $inner) use ($term): void {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $inner->where('title', 'like', $like)->orWhere('body', 'like', $like)
                    ->orWhereHas('article', fn (Builder $article) => $article->where('reference', 'like', $like));
            }))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Translation $translation): array => [
                'id' => $translation->id,
                'reference' => $translation->article?->reference,
                'article_title' => $translation->article?->title,
                'version' => $translation->version === null ? null : 'v'.$translation->version->version.' · '.$translation->version->language,
                'language' => $translation->language,
                'title' => $translation->title,
                'body' => $translation->body,
                'status' => $translation->status,
                'effective_from' => $translation->effective_from?->toDateString(),
                'approved_at' => $translation->approved_at?->toIso8601String(),
                'revision' => $translation->revision,
            ]);

        return Inertia::render('KnowledgeBank/Index', [
            'articles' => $articles,
            'versions' => $versions,
            'approvals' => $approvals,
            'translations' => $translations,
            'filters' => [
                'tab' => $tab,
                'q' => $term,
                'category' => $filters['category'] ?? null,
                'status' => $filters['status'] ?? null,
                'language' => $filters['language'] ?? null,
                'article_id' => $filters['article_id'] ?? null,
            ],
            'categories' => KnowledgeArticle::CATEGORIES,
            'articleStatuses' => KnowledgeArticle::STATUSES,
            'versionStatuses' => ScriptVersion::STATUSES,
            'languages' => KnowledgeBank::LANGUAGES,
            'approvalAreas' => KnowledgeBank::AREAS,
            'articleOptions' => $canManage ? KnowledgeArticle::query()->where('status', '!=', 'retired')
                ->orderBy('reference')->limit(200)->get(['id', 'reference', 'title', 'status'])
                ->map(fn (KnowledgeArticle $article): array => [
                    'id' => $article->id, 'reference' => $article->reference, 'title' => $article->title, 'status' => $article->status,
                ])->all() : [],
            'versionOptions' => $canManage ? ScriptVersion::query()->whereIn('status', ['approved', 'published'])
                ->with('article:id,reference')->orderByDesc('id')->limit(200)->get()
                ->map(fn (ScriptVersion $version): array => [
                    'id' => $version->id,
                    'label' => $version->article->reference.' · v'.$version->version.' · '.$version->language.' · '.$version->status,
                ])->all() : [],
            'canManage' => $canManage,
            'canClinical' => $canClinical,
            'canBrand' => $canBrand,
            'seesDrafts' => $seesDrafts,
            'asOf' => now()->toIso8601String(),
        ]);
    }

    public function offline(Request $request, KnowledgeBank $bank): JsonResponse
    {
        $this->authorize('view knowledge bank');

        return response()->json($bank->offlineGuidance())
            ->header('Cache-Control', 'private, max-age=600');
    }

    public function storeArticle(Request $request, KnowledgeBank $bank): RedirectResponse
    {
        $this->authorize('view knowledge bank');
        $this->authorize('manage knowledge bank');
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:100'],
            'category' => ['required', Rule::in(KnowledgeArticle::CATEGORIES)],
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'body' => ['required', 'string', 'max:20000'],
            'change_summary' => ['nullable', 'string', 'max:1000'],
            'effective_from' => ['nullable', 'date'],
            'review_due_at' => ['nullable', 'date'],
        ]);
        $bank->createArticle($this->user($request), $data);

        return back()->with('success', 'Draft article created. Clinical and brand approvals are required before it can be published.');
    }

    public function storeVersion(Request $request, KnowledgeBank $bank): RedirectResponse
    {
        $this->authorize('view knowledge bank');
        $this->authorize('manage knowledge bank');
        $data = $request->validate([
            'article_id' => ['required', 'integer', 'exists:knowledge_articles,id'],
            'language' => ['required', Rule::in(array_keys(KnowledgeBank::LANGUAGES))],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
            'change_summary' => ['nullable', 'string', 'max:1000'],
            'effective_from' => ['nullable', 'date'],
        ]);
        $bank->createVersion(
            $this->user($request),
            KnowledgeArticle::query()->findOrFail((int) $data['article_id']),
            $data,
        );

        return back()->with('success', 'New draft version created. Previous published wording remains active until this version is approved and published.');
    }

    public function updateVersion(Request $request, ScriptVersion $version, KnowledgeBank $bank): RedirectResponse
    {
        $this->authorize('view knowledge bank');
        $this->authorize('manage knowledge bank');
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
            'change_summary' => ['nullable', 'string', 'max:1000'],
            'effective_from' => ['nullable', 'date'],
        ]);
        $bank->updateVersion($this->user($request), $version, $data);

        return back()->with('success', 'Draft wording updated.');
    }

    public function review(Request $request, ScriptVersion $version, KnowledgeBank $bank): RedirectResponse
    {
        $this->authorize('view knowledge bank');
        $this->authorize('manage knowledge bank');
        $bank->submitForReview($this->user($request), $version);

        return back()->with('success', 'Submitted for clinical and brand approval.');
    }

    public function decide(Request $request, ScriptVersion $version, KnowledgeBank $bank): RedirectResponse
    {
        $this->authorize('view knowledge bank');
        $data = $request->validate([
            'area' => ['required', Rule::in(KnowledgeBank::AREAS)],
            'decision' => ['required', Rule::in(['approved', 'changes_requested'])],
            'approver_name' => ['required', 'string', 'max:255'],
            'reference' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'attested' => ['required', 'accepted'],
        ]);
        $this->authorize($data['area'] === 'clinical' ? 'approve clinical knowledge' : 'approve brand knowledge');
        $bank->decide($this->user($request), $version, $data);

        return back()->with('success', 'Approval decision recorded with the named approver and formal reference.');
    }

    public function publish(Request $request, ScriptVersion $version, KnowledgeBank $bank): RedirectResponse
    {
        $this->authorize('view knowledge bank');
        $this->authorize('manage knowledge bank');
        $data = $request->validate([
            'effective_from' => ['required', 'date'],
        ]);
        $bank->publish($this->user($request), $version, $data);

        return back()->with('success', 'Approved wording published. Earlier published wording for this article is retired.');
    }

    public function retireVersion(Request $request, ScriptVersion $version, KnowledgeBank $bank): RedirectResponse
    {
        $this->authorize('view knowledge bank');
        $this->authorize('manage knowledge bank');
        $bank->retireVersion($this->user($request), $version);

        return back()->with('success', 'Version retired. Historical wording remains available for audit.');
    }

    public function retireArticle(Request $request, KnowledgeArticle $article, KnowledgeBank $bank): RedirectResponse
    {
        $this->authorize('view knowledge bank');
        $this->authorize('manage knowledge bank');
        $bank->retireArticle($this->user($request), $article);

        return back()->with('success', 'Article retired. Its published wording is no longer offered as current guidance.');
    }

    public function storeTranslation(Request $request, KnowledgeBank $bank): RedirectResponse
    {
        $this->authorize('view knowledge bank');
        $this->authorize('manage knowledge bank');
        $data = $request->validate([
            'version_id' => ['required', 'integer', 'exists:script_versions,id'],
            'language' => ['required', Rule::in(array_keys(KnowledgeBank::LANGUAGES))],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
            'effective_from' => ['nullable', 'date'],
        ]);
        $bank->addTranslation(
            $this->user($request),
            ScriptVersion::query()->findOrFail((int) $data['version_id']),
            $data,
        );

        return back()->with('success', 'Draft language variant created. Brand approval is required before it appears as current guidance.');
    }

    public function updateTranslation(Request $request, Translation $translation, KnowledgeBank $bank): RedirectResponse
    {
        $this->authorize('view knowledge bank');
        $this->authorize('manage knowledge bank');
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
            'effective_from' => ['nullable', 'date'],
        ]);
        $bank->updateTranslation($this->user($request), $translation, $data);

        return back()->with('success', 'Draft language variant updated.');
    }

    public function approveTranslation(Request $request, Translation $translation, KnowledgeBank $bank): RedirectResponse
    {
        $this->authorize('view knowledge bank');
        $this->authorize('approve brand knowledge');
        $data = $request->validate([
            'approver_name' => ['required', 'string', 'max:255'],
            'reference' => ['required', 'string', 'max:255'],
            'effective_from' => ['required', 'date'],
            'attested' => ['required', 'accepted'],
        ]);
        $bank->approveTranslation($this->user($request), $translation, $data);

        return back()->with('success', 'Language variant approved for the named effective date.');
    }

    public function retireTranslation(Request $request, Translation $translation, KnowledgeBank $bank): RedirectResponse
    {
        $this->authorize('view knowledge bank');
        $this->authorize('manage knowledge bank');
        $bank->retireTranslation($this->user($request), $translation);

        return back()->with('success', 'Language variant retired.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
