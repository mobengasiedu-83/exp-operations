<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import AdminTable from '@/components/AdminTable.vue';
import { index, offline } from '@/routes/knowledge-bank';
import { store as storeArticle, retire as retireArticle } from '@/routes/knowledge-bank/articles';
import {
    store as storeVersion,
    update as updateVersion,
    review,
    decide,
    publish,
    retire as retireVersion,
} from '@/routes/knowledge-bank/versions';
import {
    store as storeTranslation,
    update as updateTranslation,
    approve as approveTranslation,
    retire as retireTranslation,
} from '@/routes/knowledge-bank/translations';

defineOptions({ layout: AppLayout });

type Paginator<T> = { data: T[]; prev_page_url: string | null; next_page_url: string | null };
type ArticleRow = {
    id: number; reference: string; category: string; title: string; summary: string | null;
    status: string; current_version: string | null; effective_from: string | null;
    review_due_at: string | null; versions: number; owner: string | null;
};
type VersionRow = {
    id: number; article_id: number; reference: string | null; article_status: string | null;
    category: string | null; title: string; body: string; version: string; status: string;
    change_summary: string | null; effective_from: string | null; effective_to: string | null;
    revision: number; clinical: string; brand: string;
};
type ApprovalRow = {
    id: number; reference: string | null; article_title: string | null; version: string | null;
    version_status: string | null; area: string; decision: string; approver_name: string | null;
    decision_reference: string | null; decided_at: string | null;
};
type TranslationRow = {
    id: number; reference: string | null; article_title: string | null; version: string | null;
    language: string; title: string; body: string; status: string; effective_from: string | null;
    approved_at: string | null; revision: number;
};
type Option = { id: number; label: string };

const props = defineProps<{
    articles: Paginator<ArticleRow>;
    versions: Paginator<VersionRow>;
    approvals: Paginator<ApprovalRow> | null;
    translations: Paginator<TranslationRow>;
    filters: { tab: string; q: string; category?: string | null; status?: string | null; language?: string | null; article_id?: number | null };
    categories: string[];
    articleStatuses: string[];
    versionStatuses: string[];
    languages: Record<string, string>;
    approvalAreas: string[];
    articleOptions: { id: number; reference: string; title: string; status: string }[];
    versionOptions: Option[];
    canManage: boolean;
    canClinical: boolean;
    canBrand: boolean;
    seesDrafts: boolean;
    asOf: string;
}>();

const tab = ref(props.filters.tab);
const search = ref(props.filters.q);
const status = ref(props.filters.status ?? '');
const language = ref(props.filters.language ?? '');
const activeVersion = ref<VersionRow | null>(null);
const activeAction = ref<'edit' | 'decision' | 'publish' | null>(null);
const activeTranslation = ref<TranslationRow | null>(null);
const translationAction = ref<'edit' | 'approval' | null>(null);
const showArticleForm = ref(false);
const showVersionForm = ref(false);
const showTranslationForm = ref(false);
const today = new Date().toISOString().slice(0, 10);

const articleForm = useForm({
    reference: '', category: 'faq', title: '', summary: '', body: '', change_summary: '',
    effective_from: '', review_due_at: '',
});
const versionForm = useForm({
    article_id: '', language: 'en', title: '', body: '', change_summary: '', effective_from: '',
});
const editing = useForm({ revision: 1, title: '', body: '', change_summary: '', effective_from: '' });
const decision = useForm({
    area: 'clinical', decision: 'approved', approver_name: '', reference: '', note: '', attested: false,
});
const publishing = useForm({ effective_from: today });
const translationForm = useForm({
    version_id: '', language: 'tw', title: '', body: '', effective_from: '',
});
const translationEditing = useForm({ revision: 1, title: '', body: '', effective_from: '' });
const translationApproval = useForm({ approver_name: '', reference: '', effective_from: today, attested: false });

const tabs = ['faqs', 'scripts', 'red_flags', 'referral_guidance', 'translations', 'approvals'];
const visibleTabs = computed(() => (props.seesDrafts ? tabs : tabs.filter((item) => item !== 'approvals')));
const tabLabels: Record<string, string> = {
    faqs: 'FAQs', scripts: 'Scripts', red_flags: 'Red flags',
    referral_guidance: 'Referral guidance', translations: 'Translations', approvals: 'Approvals',
};
function applyFilters() {
    router.get(
        index.url(),
        {
            tab: tab.value,
            q: search.value || undefined,
            status: status.value || undefined,
            language: language.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function createArticle() {
    articleForm.post(storeArticle.url(), {
        preserveScroll: true,
        onSuccess: () => {
            showArticleForm.value = false;
            articleForm.reset();
        },
    });
}

function createVersion() {
    versionForm.post(storeVersion.url(), {
        preserveScroll: true,
        onSuccess: () => {
            showVersionForm.value = false;
            versionForm.reset();
        },
    });
}

function startEdit(version: VersionRow) {
    editing.reset();
    editing.clearErrors();
    activeVersion.value = version;
    activeAction.value = 'edit';
    editing.revision = version.revision;
    editing.title = version.title;
    editing.body = version.body;
    editing.change_summary = version.change_summary ?? '';
    editing.effective_from = version.effective_from ?? '';
}

function saveEdit() {
    if (activeVersion.value === null) return;
    editing.patch(updateVersion.url(activeVersion.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            activeVersion.value = null;
            activeAction.value = null;
            editing.reset();
        },
    });
}

function submitReview(version: VersionRow) {
    router.post(review.url(version.id), {}, { preserveScroll: true });
}

function startDecision(version: VersionRow, area: string) {
    decision.reset();
    decision.clearErrors();
    activeVersion.value = version;
    activeAction.value = 'decision';
    decision.area = area;
}

function saveDecision() {
    if (activeVersion.value === null) return;
    decision.post(decide.url(activeVersion.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            activeVersion.value = null;
            activeAction.value = null;
            decision.reset();
        },
    });
}

function startPublish(version: VersionRow) {
    publishing.reset();
    publishing.clearErrors();
    activeVersion.value = version;
    activeAction.value = 'publish';
    publishing.effective_from = today;
}

function publishVersion() {
    if (activeVersion.value === null) return;
    publishing.post(publish.url(activeVersion.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            activeVersion.value = null;
            activeAction.value = null;
            publishing.reset();
        },
    });
}

function retirePublished(version: VersionRow) {
    if (
        !window.confirm(
            `Retire ${version.reference ?? 'this article'} ${version.version}? Approved wording stops being current guidance.`,
        )
    ) return;
    router.post(retireVersion.url(version.id), {}, { preserveScroll: true });
}

function retireArticleRow(row: Record<string, unknown>) {
    if (!window.confirm(`Retire ${String(row.reference)}? Its published wording and language variants stop being current guidance.`)) return;
    router.post(retireArticle.url(Number(row.id)), {}, { preserveScroll: true });
}

function createTranslation() {
    translationForm.post(storeTranslation.url(), {
        preserveScroll: true,
        onSuccess: () => {
            showTranslationForm.value = false;
            translationForm.reset();
        },
    });
}

function startTranslationEdit(row: Record<string, unknown>) {
    translationEditing.reset();
    translationEditing.clearErrors();
    activeTranslation.value = row as unknown as TranslationRow;
    translationAction.value = 'edit';
    translationEditing.revision = Number(row.revision);
    translationEditing.title = String(row.title);
    translationEditing.body = String(row.body);
}

function saveTranslationEdit() {
    if (activeTranslation.value === null) return;
    translationEditing.patch(updateTranslation.url(activeTranslation.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            activeTranslation.value = null;
            translationAction.value = null;
            translationEditing.reset();
        },
    });
}

function startTranslationApproval(row: Record<string, unknown>) {
    translationApproval.reset();
    translationApproval.clearErrors();
    activeTranslation.value = row as unknown as TranslationRow;
    translationAction.value = 'approval';
    translationApproval.effective_from = today;
}

function saveTranslationApproval() {
    if (activeTranslation.value === null) return;
    translationApproval.post(approveTranslation.url(activeTranslation.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            activeTranslation.value = null;
            translationAction.value = null;
            translationApproval.reset();
        },
    });
}

function retireTranslationRow(row: Record<string, unknown>) {
    if (!window.confirm('Retire this language variant? Approved wording for that language stops being current guidance.')) return;
    router.post(retireTranslation.url(Number(row.id)), {}, { preserveScroll: true });
}

function label(value: string) {
    return value.replaceAll('_', ' ');
}
</script>

<template>
    <div class="space-y-6 p-6">
        <Head title="Knowledge bank" />
        <Heading
            title="Knowledge bank and approvals"
            description="One versioned record holds each approved FAQ, script, red-flag prompt, referral instruction and outage notice. Draft, review, approval, publication and retirement are recorded separately, with named clinical and brand owners, effective dates and language variants."
        />
        <p role="note" class="rounded-xl border border-amber-500 bg-amber-50 p-4 text-amber-950 dark:bg-amber-950 dark:text-amber-100">
            Assistants only see the current published wording for a published article. Drafts, review versions and
            unapproved translations stay visible to managers and approvers. Creating or importing content does not
            approve it: a named clinical owner and brand owner must each record a formal reference before publication.
        </p>
        <div class="flex flex-wrap items-center gap-4 text-sm">
            <a class="rounded border px-3 py-2" :href="offline.url()" target="_blank" rel="noopener">
                Download approved offline guidance (JSON)
            </a>
            <span class="text-muted-foreground">Snapshot as of {{ asOf }}</span>
        </div>

        <nav class="flex flex-wrap gap-4 text-sm">
            <Link
                v-for="item in visibleTabs"
                :key="item"
                :href="index({ query: { tab: item, q: search || undefined, status: status || undefined, language: language || undefined } })"
                :class="{ 'font-bold underline': filters.tab === item }"
            >
                {{ tabLabels[item] }}
            </Link>
        </nav>

        <form class="flex flex-wrap items-end gap-4 text-sm" @submit.prevent="applyFilters">
            <label class="grid gap-2">
                Search approved wording
                <input v-model="search" maxlength="100" class="w-64 rounded border bg-background p-2" />
            </label>
            <label class="grid gap-2">
                Version status
                <select v-model="status" class="rounded border bg-background p-2">
                    <option value="">Any status</option>
                    <option v-for="item in versionStatuses" :key="item" :value="item">{{ label(item) }}</option>
                </select>
            </label>
            <label class="grid gap-2">
                Language
                <select v-model="language" class="rounded border bg-background p-2">
                    <option value="">Any language</option>
                    <option v-for="(name, code) in languages" :key="code" :value="code">{{ name }}</option>
                </select>
            </label>
            <button class="rounded border px-3 py-2">Apply</button>
        </form>

        <section v-if="canManage" class="space-y-4 rounded-xl border bg-card p-5">
            <div class="flex flex-wrap gap-3">
                <button type="button" class="rounded bg-primary px-4 py-2 text-primary-foreground" @click="showArticleForm = !showArticleForm">New article</button>
                <button type="button" class="rounded border px-4 py-2" @click="showVersionForm = !showVersionForm">New version</button>
                <button type="button" class="rounded border px-4 py-2" @click="showTranslationForm = !showTranslationForm">New translation</button>
            </div>
            <form v-if="showArticleForm" class="grid gap-4 md:grid-cols-2" @submit.prevent="createArticle">
                <h2 class="text-lg font-semibold md:col-span-2">New draft article</h2>
                <label class="grid gap-2">Reference<input v-model="articleForm.reference" required maxlength="100" class="rounded border bg-background p-2" /></label>
                <label class="grid gap-2">
                    Category
                    <select v-model="articleForm.category" class="rounded border bg-background p-2">
                        <option v-for="item in categories" :key="item" :value="item">{{ label(item) }}</option>
                    </select>
                </label>
                <label class="grid gap-2 md:col-span-2">Title<input v-model="articleForm.title" required maxlength="255" class="rounded border bg-background p-2" /></label>
                <label class="grid gap-2 md:col-span-2">Summary<textarea v-model="articleForm.summary" rows="2" maxlength="2000" class="rounded border bg-background p-2"></textarea></label>
                <label class="grid gap-2 md:col-span-2">Wording<textarea v-model="articleForm.body" required rows="5" maxlength="20000" class="rounded border bg-background p-2"></textarea></label>
                <label class="grid gap-2">Change summary<input v-model="articleForm.change_summary" maxlength="1000" class="rounded border bg-background p-2" /></label>
                <label class="grid gap-2">Proposed effective date<input v-model="articleForm.effective_from" type="date" class="rounded border bg-background p-2" /></label>
                <label class="grid gap-2">Review due<input v-model="articleForm.review_due_at" type="date" class="rounded border bg-background p-2" /></label>
                <p v-for="(error, key) in articleForm.errors" :key="key" role="alert" class="text-sm text-red-600 md:col-span-2">{{ error }}</p>
                <div class="flex gap-3 md:col-span-2">
                    <button :disabled="articleForm.processing" class="rounded bg-primary px-4 py-2 text-primary-foreground">Save draft</button>
                    <button type="button" class="rounded border px-4 py-2" @click="showArticleForm = false">Cancel</button>
                </div>
            </form>
            <form v-if="showVersionForm" class="grid gap-4 md:grid-cols-2" @submit.prevent="createVersion">
                <h2 class="text-lg font-semibold md:col-span-2">New draft version</h2>
                <label class="grid gap-2 md:col-span-2">
                    Article
                    <select v-model="versionForm.article_id" required class="rounded border bg-background p-2">
                        <option value="" disabled>Select an article</option>
                        <option v-for="option in articleOptions" :key="option.id" :value="String(option.id)">
                            {{ option.reference }} · {{ option.title }} ({{ label(option.status) }})
                        </option>
                    </select>
                </label>
                <label class="grid gap-2">
                    Language
                    <select v-model="versionForm.language" class="rounded border bg-background p-2">
                        <option v-for="(name, code) in languages" :key="code" :value="code">{{ name }}</option>
                    </select>
                </label>
                <label class="grid gap-2">Proposed effective date<input v-model="versionForm.effective_from" type="date" class="rounded border bg-background p-2" /></label>
                <label class="grid gap-2 md:col-span-2">Title<input v-model="versionForm.title" required maxlength="255" class="rounded border bg-background p-2" /></label>
                <label class="grid gap-2 md:col-span-2">Wording<textarea v-model="versionForm.body" required rows="5" maxlength="20000" class="rounded border bg-background p-2"></textarea></label>
                <label class="grid gap-2 md:col-span-2">Change summary<input v-model="versionForm.change_summary" maxlength="1000" class="rounded border bg-background p-2" /></label>
                <p v-for="(error, key) in versionForm.errors" :key="key" role="alert" class="text-sm text-red-600 md:col-span-2">{{ error }}</p>
                <div class="flex gap-3 md:col-span-2">
                    <button :disabled="versionForm.processing" class="rounded bg-primary px-4 py-2 text-primary-foreground">Save draft version</button>
                    <button type="button" class="rounded border px-4 py-2" @click="showVersionForm = false">Cancel</button>
                </div>
            </form>
        </section>

        <section v-if="['faqs', 'red_flags', 'referral_guidance'].includes(filters.tab)" class="space-y-3">
            <h2 class="text-lg font-semibold">{{ tabLabels[filters.tab] }}</h2>
            <AdminTable
                :rows="articles.data"
                :columns="[
                    { key: 'reference', label: 'Reference' },
                    { key: 'category', label: 'Category' },
                    { key: 'title', label: 'Title' },
                    { key: 'status', label: 'Article status' },
                    { key: 'current_version', label: 'Current version' },
                    { key: 'effective_from', label: 'Effective from' },
                    { key: 'versions', label: 'Versions' },
                    { key: 'owner', label: 'Created by' },
                ]"
                :previous="articles.prev_page_url"
                :next="articles.next_page_url"
            >
                <template #actions="{ row }">
                    <div class="flex flex-wrap gap-2 text-sm">
                        <Link :href="index({ query: { tab: 'scripts', article_id: Number(row.id) } })" class="underline">
                            Versions
                        </Link>
                        <button
                            v-if="canManage && row.status !== 'retired'"
                            type="button"
                            class="text-red-600 underline"
                            @click="retireArticleRow(row)"
                        >
                            Retire
                        </button>
                    </div>
                </template>
            </AdminTable>
        </section>

        <form v-if="canManage && showTranslationForm" class="grid gap-4 rounded-xl border bg-card p-5 md:grid-cols-2" @submit.prevent="createTranslation">
            <h2 class="text-lg font-semibold md:col-span-2">New draft language variant</h2>
            <label class="grid gap-2 md:col-span-2">
                Approved or published source version
                <select v-model="translationForm.version_id" required class="rounded border bg-background p-2">
                    <option value="" disabled>Select a source version</option>
                    <option v-for="option in versionOptions" :key="option.id" :value="String(option.id)">
                        {{ option.label }}
                    </option>
                </select>
            </label>
            <label class="grid gap-2">
                Language
                <select v-model="translationForm.language" class="rounded border bg-background p-2">
                    <option v-for="(name, code) in languages" :key="code" :value="code">{{ name }}</option>
                </select>
            </label>
            <label class="grid gap-2">Proposed effective date<input v-model="translationForm.effective_from" type="date" class="rounded border bg-background p-2" /></label>
            <label class="grid gap-2 md:col-span-2">Translated title<input v-model="translationForm.title" required maxlength="255" class="rounded border bg-background p-2" /></label>
            <label class="grid gap-2 md:col-span-2">Translated wording<textarea v-model="translationForm.body" required rows="4" maxlength="20000" class="rounded border bg-background p-2"></textarea></label>
            <p v-for="(error, key) in translationForm.errors" :key="key" role="alert" class="text-sm text-red-600 md:col-span-2">{{ error }}</p>
            <div class="flex gap-3 md:col-span-2">
                <button :disabled="translationForm.processing" class="rounded bg-primary px-4 py-2 text-primary-foreground">Save draft translation</button>
                <button type="button" class="rounded border px-4 py-2" @click="showTranslationForm = false">Cancel</button>
            </div>
        </form>

        <section v-if="filters.tab === 'scripts'" class="space-y-4">
            <h2 class="text-lg font-semibold">Scripts and version history</h2>
            <p v-if="versions.data.length === 0" class="rounded border p-4 text-sm text-muted-foreground">
                No versions match these filters.
            </p>
            <article v-for="version in versions.data" :key="version.id" class="space-y-3 rounded-xl border bg-card p-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 class="text-lg font-semibold">{{ version.reference }} · {{ version.title }}</h3>
                    <span class="rounded border px-3 py-1 text-sm">{{ version.version }} · {{ label(version.status) }}</span>
                </div>
                <dl class="grid gap-3 text-sm md:grid-cols-3">
                    <div><dt class="font-semibold">Article status</dt><dd>{{ label(version.article_status ?? 'unknown') }}</dd></div>
                    <div><dt class="font-semibold">Clinical approval</dt><dd>{{ label(version.clinical) }}</dd></div>
                    <div><dt class="font-semibold">Brand approval</dt><dd>{{ label(version.brand) }}</dd></div>
                    <div><dt class="font-semibold">Effective from</dt><dd>{{ version.effective_from ?? 'Not published' }}</dd></div>
                    <div><dt class="font-semibold">Effective to</dt><dd>{{ version.effective_to ?? 'Open' }}</dd></div>
                    <div><dt class="font-semibold">Revision</dt><dd>{{ version.revision }}</dd></div>
                </dl>
                <p v-if="version.change_summary" class="text-sm text-muted-foreground">Change: {{ version.change_summary }}</p>
                <details>
                    <summary class="cursor-pointer font-medium">Review wording</summary>
                    <p class="whitespace-pre-wrap pt-3 text-sm">{{ version.body }}</p>
                </details>
                <div class="flex flex-wrap gap-3 text-sm">
                    <button v-if="canManage && version.status === 'draft'" type="button" class="rounded border px-3 py-2" @click="startEdit(version)">Edit draft</button>
                    <button v-if="canManage && version.status === 'draft'" type="button" class="rounded border px-3 py-2" @click="submitReview(version)">Submit for review</button>
                    <button v-if="canClinical && version.status === 'in_review' && version.clinical === 'pending'" type="button" class="rounded border px-3 py-2" @click="startDecision(version, 'clinical')">Record clinical decision</button>
                    <button v-if="canBrand && version.status === 'in_review' && version.brand === 'pending'" type="button" class="rounded border px-3 py-2" @click="startDecision(version, 'brand')">Record brand decision</button>
                    <button v-if="canManage && version.status === 'approved'" type="button" class="rounded border px-3 py-2" @click="startPublish(version)">Publish</button>
                    <button v-if="canManage && version.status === 'published'" type="button" class="rounded border px-3 py-2 text-red-600" @click="retirePublished(version)">Retire version</button>
                </div>
                <form v-if="activeVersion?.id === version.id && activeAction === 'edit'" class="grid gap-3 rounded border p-4" @submit.prevent="saveEdit">
                    <h4 class="font-semibold">Edit draft wording</h4>
                    <label class="grid gap-2">Title<input v-model="editing.title" required maxlength="255" class="rounded border bg-background p-2" /></label>
                    <label class="grid gap-2">Wording<textarea v-model="editing.body" required rows="5" maxlength="20000" class="rounded border bg-background p-2"></textarea></label>
                    <label class="grid gap-2">Change summary<input v-model="editing.change_summary" maxlength="1000" class="rounded border bg-background p-2" /></label>
                    <label class="grid gap-2">Proposed effective date<input v-model="editing.effective_from" type="date" class="rounded border bg-background p-2" /></label>
                    <p v-for="(error, key) in editing.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
                    <div class="flex gap-3">
                        <button :disabled="editing.processing" class="rounded bg-primary px-4 py-2 text-primary-foreground">Save draft</button>
                        <button type="button" class="rounded border px-4 py-2" @click="activeAction = null">Cancel</button>
                    </div>
                </form>
                <form v-if="activeVersion?.id === version.id && activeAction === 'decision'" class="grid gap-3 rounded border p-4" @submit.prevent="saveDecision">
                    <h4 class="font-semibold">{{ label(decision.area) }} decision for {{ version.version }}</h4>
                    <label class="grid gap-2">
                        Decision
                        <select v-model="decision.decision" class="rounded border bg-background p-2">
                            <option value="approved">Approve</option>
                            <option value="changes_requested">Request changes</option>
                        </select>
                    </label>
                    <label class="grid gap-2">Named approver<input v-model="decision.approver_name" required maxlength="255" class="rounded border bg-background p-2" /></label>
                    <label class="grid gap-2">Formal approval reference<input v-model="decision.reference" required maxlength="255" class="rounded border bg-background p-2" /></label>
                    <label class="grid gap-2">Decision note<textarea v-model="decision.note" rows="2" maxlength="2000" class="rounded border bg-background p-2"></textarea></label>
                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="decision.attested" type="checkbox" required class="mt-1" />
                        I am authorised to record this {{ label(decision.area) }} decision and verified the wording, scope and local instructions.
                    </label>
                    <p v-for="(error, key) in decision.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
                    <div class="flex gap-3">
                        <button :disabled="decision.processing" class="rounded bg-primary px-4 py-2 text-primary-foreground">Record decision</button>
                        <button type="button" class="rounded border px-4 py-2" @click="activeAction = null">Cancel</button>
                    </div>
                </form>
                <form v-if="activeVersion?.id === version.id && activeAction === 'publish'" class="grid gap-3 rounded border p-4" @submit.prevent="publishVersion">
                    <h4 class="font-semibold">Publish {{ version.version }}</h4>
                    <p class="text-sm text-muted-foreground">Publication requires both approvals. Earlier published wording for this article is retired automatically.</p>
                    <label class="grid gap-2">Effective from<input v-model="publishing.effective_from" type="date" required class="rounded border bg-background p-2" /></label>
                    <p v-for="(error, key) in publishing.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
                    <div class="flex gap-3">
                        <button :disabled="publishing.processing" class="rounded bg-primary px-4 py-2 text-primary-foreground">Publish version</button>
                        <button type="button" class="rounded border px-4 py-2" @click="activeAction = null">Cancel</button>
                    </div>
                </form>
            </article>
            <nav class="flex gap-4 text-sm">
                <Link v-if="versions.prev_page_url" :href="versions.prev_page_url">Previous versions</Link>
                <Link v-if="versions.next_page_url" :href="versions.next_page_url">More versions</Link>
            </nav>
        </section>

        <section v-if="filters.tab === 'translations'" class="space-y-3">
            <h2 class="text-lg font-semibold">Language variants</h2>
            <AdminTable
                :rows="translations.data"
                :columns="[
                    { key: 'reference', label: 'Reference' },
                    { key: 'article_title', label: 'Article' },
                    { key: 'version', label: 'Source version' },
                    { key: 'language', label: 'Language' },
                    { key: 'title', label: 'Translated title' },
                    { key: 'status', label: 'Status' },
                    { key: 'effective_from', label: 'Effective from' },
                    { key: 'approved_at', label: 'Approved at' },
                ]"
                :previous="translations.prev_page_url"
                :next="translations.next_page_url"
            >
                <template #actions="{ row }">
                    <div class="flex flex-wrap gap-2 text-sm">
                        <button v-if="canManage && row.status === 'draft'" type="button" class="underline" @click="startTranslationEdit(row)">Edit</button>
                        <button v-if="canBrand && row.status === 'draft'" type="button" class="underline" @click="startTranslationApproval(row)">Approve</button>
                        <button v-if="canManage && row.status !== 'retired'" type="button" class="text-red-600 underline" @click="retireTranslationRow(row)">Retire</button>
                    </div>
                </template>
            </AdminTable>
            <form v-if="translationAction === 'edit' && activeTranslation !== null" class="grid gap-3 rounded-xl border bg-card p-5" @submit.prevent="saveTranslationEdit">
                <h3 class="font-semibold">Edit draft {{ activeTranslation.language }} variant</h3>
                <label class="grid gap-2">Translated title<input v-model="translationEditing.title" required maxlength="255" class="rounded border bg-background p-2" /></label>
                <label class="grid gap-2">Translated wording<textarea v-model="translationEditing.body" required rows="4" maxlength="20000" class="rounded border bg-background p-2"></textarea></label>
                <label class="grid gap-2">Proposed effective date<input v-model="translationEditing.effective_from" type="date" class="rounded border bg-background p-2" /></label>
                <p v-for="(error, key) in translationEditing.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
                <div class="flex gap-3">
                    <button :disabled="translationEditing.processing" class="rounded bg-primary px-4 py-2 text-primary-foreground">Save draft</button>
                    <button type="button" class="rounded border px-4 py-2" @click="translationAction = null">Cancel</button>
                </div>
            </form>
            <form v-if="translationAction === 'approval' && activeTranslation !== null" class="grid gap-3 rounded-xl border bg-card p-5" @submit.prevent="saveTranslationApproval">
                <h3 class="font-semibold">Approve {{ activeTranslation.language }} variant</h3>
                <label class="grid gap-2">Named brand approver<input v-model="translationApproval.approver_name" required maxlength="255" class="rounded border bg-background p-2" /></label>
                <label class="grid gap-2">Formal approval reference<input v-model="translationApproval.reference" required maxlength="255" class="rounded border bg-background p-2" /></label>
                <label class="grid gap-2">Effective from<input v-model="translationApproval.effective_from" type="date" required class="rounded border bg-background p-2" /></label>
                <label class="flex items-start gap-2 text-sm">
                    <input v-model="translationApproval.attested" type="checkbox" required class="mt-1" />
                    I am authorised to approve this translated wording and confirmed it matches the approved source meaning.
                </label>
                <p v-for="(error, key) in translationApproval.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
                <div class="flex gap-3">
                    <button :disabled="translationApproval.processing" class="rounded bg-primary px-4 py-2 text-primary-foreground">Approve variant</button>
                    <button type="button" class="rounded border px-4 py-2" @click="translationAction = null">Cancel</button>
                </div>
            </form>
        </section>

        <section v-if="filters.tab === 'approvals' && approvals" class="space-y-3">
            <h2 class="text-lg font-semibold">Approval workflow</h2>
            <p class="text-sm text-muted-foreground">Every version records a clinical and a brand decision, with the named approver, formal reference and time. Changes requested return the version to draft.</p>
            <AdminTable
                :rows="approvals.data"
                :columns="[
                    { key: 'reference', label: 'Reference' },
                    { key: 'article_title', label: 'Article' },
                    { key: 'version', label: 'Version' },
                    { key: 'area', label: 'Area' },
                    { key: 'decision', label: 'Decision' },
                    { key: 'approver_name', label: 'Named approver' },
                    { key: 'decision_reference', label: 'Reference' },
                    { key: 'decided_at', label: 'Decided at' },
                ]"
                :previous="approvals.prev_page_url"
                :next="approvals.next_page_url"
            />
        </section>
    </div>
</template>

