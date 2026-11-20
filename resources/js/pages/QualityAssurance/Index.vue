<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import AdminTable from '@/components/AdminTable.vue';
import { index } from '@/routes/quality-assurance';
import { store, update, finalise, coaching } from '@/routes/quality-assurance/reviews';
import { store as storeCalibration, agree } from '@/routes/quality-assurance/calibrations';

defineOptions({ layout: AppLayout });

type Paginator<T> = { data: T[]; prev_page_url: string | null; next_page_url: string | null };
type Criterion = { criterion: string; label: string; weight: number; critical: boolean };
type ScoreRow = { criterion: string; rating: number; weight: number; points: number; critical: boolean; note: string | null };
type ReviewRow = {
    id: number; reference: string; agent: string | null; agent_id: number; reviewer: string | null;
    case_id: number | null; reviewed_at: string | null; status: string; total: number | null;
    outcome: string | null; critical_fail: boolean; critical_note: string | null; notes: string | null;
    coaching_required: boolean; coaching_due_at: string | null; coaching_completed_at: string | null;
    finalised_at: string | null; revision: number; scores: ScoreRow[];
};
type CalibrationRow = {
    id: number; review_id: number; review: string | null; primary_total: number | null; reviewer: string | null;
    secondary_total: number; variance: number; agreed_total: number | null; status: string;
    agreed_by: string | null; agreed_at: string | null; note: string | null;
};
type ScorecardRow = { criterion: string; label: string; weight: number; critical: boolean; average: number | null; count: number };

const props = defineProps<{
    reviews: Paginator<ReviewRow>;
    calibrations: Paginator<CalibrationRow>;
    scorecard: ScorecardRow[];
    criteria: Criterion[];
    ratings: number[];
    outcomes: string[];
    statuses: string[];
    agents: { id: number; name: string }[];
    cases: { id: number; priority: string | null }[];
    filters: { tab: string; q: string; outcome: string | null };
    canManage: boolean;
    canApprove: boolean;
    passMark: number;
    tolerance: number;
    asOf: string;
}>();

const tabs: { key: string; label: string }[] = [
    { key: 'reviews', label: 'Reviews' },
    { key: 'scorecards', label: 'Scorecards' },
    { key: 'calibration', label: 'Calibration' },
    { key: 'critical_fails', label: 'Critical fails' },
];
const search = ref(props.filters.q);
const outcome = ref(props.filters.outcome ?? '');
const showForm = ref(false);
const editing = ref<ReviewRow | null>(null);
const activeReview = ref<ReviewRow | null>(null);
const reviewAction = ref<'finalise' | 'coaching' | 'calibration' | null>(null);
const activeCalibration = ref<CalibrationRow | null>(null);
const today = new Date().toISOString().slice(0, 10);

function blankScores(): Record<string, { rating: number; note: string }> {
    const scores: Record<string, { rating: number; note: string }> = {};
    for (const criterion of props.criteria) {
        scores[criterion.criterion] = { rating: 5, note: '' };
    }
    return scores;
}

const reviewForm = useForm({
    reference: '',
    agent_id: '',
    case_id: '',
    reviewed_at: today,
    critical_note: '',
    notes: '',
    revision: 1,
    scores: blankScores(),
});
const finaliseForm = useForm({ revision: 1, coaching_due_at: '' });
const coachingForm = useForm({ coaching_note: '' });
const calibrationForm = useForm({ secondary_total: 100, note: '' });
const agreementForm = useForm({ agreed_total: 100, note: '' });

function applyFilters() {
    router.get(
        index.url(),
        { tab: props.filters.tab, q: search.value || undefined, outcome: outcome.value || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function startCreate() {
    editing.value = null;
    reviewForm.reset();
    reviewForm.clearErrors();
    reviewForm.scores = blankScores();
    reviewForm.reviewed_at = today;
    showForm.value = true;
}

function startEdit(row: Record<string, unknown>) {
    const review = row as unknown as ReviewRow;
    editing.value = review;
    reviewForm.clearErrors();
    reviewForm.reference = review.reference;
    reviewForm.agent_id = String(review.agent_id);
    reviewForm.case_id = review.case_id === null ? '' : String(review.case_id);
    reviewForm.reviewed_at = review.reviewed_at ?? today;
    reviewForm.critical_note = review.critical_note ?? '';
    reviewForm.notes = review.notes ?? '';
    reviewForm.revision = review.revision;
    reviewForm.scores = blankScores();
    for (const score of review.scores) {
        reviewForm.scores[score.criterion] = { rating: score.rating, note: score.note ?? '' };
    }
    showForm.value = true;
}

function submitReview() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            showForm.value = false;
            editing.value = null;
            reviewForm.reset();
            reviewForm.scores = blankScores();
        },
    };
    if (editing.value !== null) {
        reviewForm.patch(update.url(editing.value.id), options);
        return;
    }
    reviewForm.post(store.url(), options);
}

function startFinalise(row: Record<string, unknown>) {
    const review = row as unknown as ReviewRow;
    finaliseForm.reset();
    finaliseForm.clearErrors();
    activeReview.value = review;
    reviewAction.value = 'finalise';
    finaliseForm.revision = review.revision;
}

function submitFinalise() {
    if (activeReview.value === null) return;
    finaliseForm.post(finalise.url(activeReview.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            activeReview.value = null;
            reviewAction.value = null;
            finaliseForm.reset();
        },
    });
}

function startCoaching(row: Record<string, unknown>) {
    const review = row as unknown as ReviewRow;
    coachingForm.reset();
    coachingForm.clearErrors();
    activeReview.value = review;
    reviewAction.value = 'coaching';
}

function submitCoaching() {
    if (activeReview.value === null) return;
    coachingForm.post(coaching.url(activeReview.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            activeReview.value = null;
            reviewAction.value = null;
            coachingForm.reset();
        },
    });
}

function startCalibration(row: Record<string, unknown>) {
    const review = row as unknown as ReviewRow;
    calibrationForm.reset();
    calibrationForm.clearErrors();
    activeReview.value = review;
    reviewAction.value = 'calibration';
    calibrationForm.secondary_total = review.total ?? 100;
}

function submitCalibration() {
    if (activeReview.value === null) return;
    calibrationForm.post(storeCalibration.url(activeReview.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            activeReview.value = null;
            reviewAction.value = null;
            calibrationForm.reset();
        },
    });
}

function startAgreement(row: Record<string, unknown>) {
    const calibration = row as unknown as CalibrationRow;
    agreementForm.reset();
    agreementForm.clearErrors();
    activeCalibration.value = calibration;
    agreementForm.agreed_total = calibration.secondary_total;
}

function submitAgreement() {
    if (activeCalibration.value === null) return;
    agreementForm.post(agree.url(activeCalibration.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            activeCalibration.value = null;
            agreementForm.reset();
        },
    });
}

function label(value: string) {
    return value.replaceAll('_', ' ');
}

function scoresOf(row: Record<string, unknown>): ScoreRow[] {
    return (row as unknown as ReviewRow).scores;
}

function criticalNoteOf(row: Record<string, unknown>): string | null {
    return (row as unknown as ReviewRow).critical_note;
}
</script>


<template>
    <div class="space-y-6 p-6">
        <Head title="Quality assurance" />
        <Heading
            title="Quality assurance, scorecards and calibration"
            description="Every reviewed call is scored against the weighted playbook scorecard. A critical safety or privacy failure overrides the total, finalises the review as a critical fail and raises an escalated incident with a corrective action."
        />
        <p role="note" class="rounded-xl border border-amber-500 bg-amber-50 p-4 text-amber-950 dark:bg-amber-950 dark:text-amber-100">
            Weights: opening 10, empathy 15, privacy 10, safety 25, capture 15, routing 15, closure 10. A pass needs
            {{ passMark }} points, and any rating of 1 or 0 on a critical criterion is a critical fail regardless of total.
            Calibration differences above {{ tolerance }} points require a written rationale.
        </p>
        <nav class="flex flex-wrap gap-4 text-sm">
            <Link
                v-for="item in tabs"
                :key="item.key"
                :href="index({ query: { tab: item.key, q: search || undefined, outcome: outcome || undefined } })"
                :class="{ 'font-bold underline': filters.tab === item.key }"
            >
                {{ item.label }}
            </Link>
        </nav>

        <form
            v-if="filters.tab === 'reviews' || filters.tab === 'critical_fails'"
            class="flex flex-wrap items-end gap-4 text-sm"
            @submit.prevent="applyFilters"
        >
            <label class="grid gap-2">
                Search reference or agent
                <input v-model="search" maxlength="100" class="w-64 rounded border bg-background p-2" />
            </label>
            <label class="grid gap-2">
                Outcome
                <select v-model="outcome" class="rounded border bg-background p-2">
                    <option value="">Any outcome</option>
                    <option v-for="item in outcomes" :key="item" :value="item">{{ label(item) }}</option>
                </select>
            </label>
            <button class="rounded border px-3 py-2">Apply</button>
        </form>

        <section
            v-if="canManage && (filters.tab === 'reviews' || filters.tab === 'critical_fails')"
            class="space-y-4 rounded-xl border bg-card p-5"
        >
            <button type="button" class="rounded bg-primary px-4 py-2 text-primary-foreground" @click="startCreate">
                New review
            </button>
            <form v-if="showForm" class="grid gap-4 md:grid-cols-2" @submit.prevent="submitReview">
                <h2 class="text-lg font-semibold md:col-span-2">
                    {{ editing === null ? 'New weighted review' : `Edit ${editing.reference}` }}
                </h2>
                <label class="grid gap-2">Reference<input v-model="reviewForm.reference" required maxlength="80" class="rounded border bg-background p-2" /></label>
                <label class="grid gap-2">
                    Agent
                    <select v-model="reviewForm.agent_id" required class="rounded border bg-background p-2">
                        <option value="" disabled>Select an agent</option>
                        <option v-for="agent in agents" :key="agent.id" :value="String(agent.id)">{{ agent.name }}</option>
                    </select>
                </label>
                <label class="grid gap-2">
                    Case (optional)
                    <select v-model="reviewForm.case_id" class="rounded border bg-background p-2">
                        <option value="">No linked case</option>
                        <option v-for="item in cases" :key="item.id" :value="String(item.id)">
                            #{{ item.id }} · {{ item.priority ?? 'unclassified' }}
                        </option>
                    </select>
                </label>
                <label class="grid gap-2">Reviewed on<input v-model="reviewForm.reviewed_at" type="date" required class="rounded border bg-background p-2" /></label>

                <fieldset class="grid gap-3 rounded-xl border p-4 md:col-span-2">
                    <legend class="px-2 font-semibold">Weighted scorecard</legend>
                    <div v-for="criterion in criteria" :key="criterion.criterion" class="grid gap-2 md:grid-cols-3">
                        <p class="text-sm font-medium">
                            {{ criterion.label }} · {{ criterion.weight }} points
                            <span v-if="criterion.critical" class="text-red-600">critical</span>
                        </p>
                        <label class="text-sm">
                            Rating
                            <select v-model.number="reviewForm.scores[criterion.criterion].rating" class="w-full rounded border bg-background p-2">
                                <option v-for="rating in ratings" :key="rating" :value="rating">{{ rating }} / 5</option>
                            </select>
                        </label>
                        <label class="text-sm">
                            Evidence note
                            <input v-model="reviewForm.scores[criterion.criterion].note" maxlength="1000" class="w-full rounded border bg-background p-2" />
                        </label>
                    </div>
                    <label class="grid gap-2 text-sm">
                        Critical fail rationale (required when a critical criterion is rated 1 or 0)
                        <textarea v-model="reviewForm.critical_note" rows="2" maxlength="2000" class="rounded border bg-background p-2"></textarea>
                    </label>
                    <label class="grid gap-2 text-sm">
                        Reviewer notes
                        <textarea v-model="reviewForm.notes" rows="2" maxlength="2000" class="rounded border bg-background p-2"></textarea>
                    </label>
                </fieldset>
                <p v-for="(error, key) in reviewForm.errors" :key="key" role="alert" class="text-sm text-red-600 md:col-span-2">{{ error }}</p>
                <div class="flex gap-3 md:col-span-2">
                    <button :disabled="reviewForm.processing" class="rounded bg-primary px-4 py-2 text-primary-foreground">
                        {{ editing === null ? 'Save draft review' : 'Save draft changes' }}
                    </button>
                    <button type="button" class="rounded border px-4 py-2" @click="showForm = false">Cancel</button>
                </div>
            </form>
        </section>


        <section v-if="filters.tab === 'reviews' || filters.tab === 'critical_fails'" class="space-y-3">
            <h2 class="text-lg font-semibold">
                {{ filters.tab === 'critical_fails' ? 'Critical fail register' : 'Reviewed calls' }}
            </h2>
            <AdminTable
                :rows="reviews.data"
                :columns="[
                    { key: 'reference', label: 'Reference' },
                    { key: 'agent', label: 'Agent' },
                    { key: 'reviewed_at', label: 'Reviewed on' },
                    { key: 'status', label: 'Status' },
                    { key: 'total', label: 'Total' },
                    { key: 'outcome', label: 'Outcome' },
                    { key: 'coaching_due_at', label: 'Coaching due' },
                ]"
                :previous="reviews.prev_page_url"
                :next="reviews.next_page_url"
            >
                <template #actions="{ row }">
                    <div class="flex flex-col gap-2 text-sm">
                        <div class="flex flex-wrap gap-2">
                            <button v-if="canManage && row.status === 'draft'" class="rounded border px-2 py-1" @click="startEdit(row)">
                                Edit
                            </button>
                            <button v-if="canManage && row.status === 'draft'" class="rounded border px-2 py-1" @click="startFinalise(row)">
                                Finalise
                            </button>
                            <button
                                v-if="canManage && row.status === 'finalised' && row.coaching_required && !row.coaching_completed_at"
                                class="rounded border px-2 py-1"
                                @click="startCoaching(row)"
                            >
                                Record coaching
                            </button>
                            <button v-if="canManage && row.status === 'finalised'" class="rounded border px-2 py-1" @click="startCalibration(row)">
                                Calibrate
                            </button>
                        </div>
                        <details>
                            <summary class="cursor-pointer text-muted-foreground">Score breakdown</summary>
                            <ul class="mt-2 space-y-1">
                                <li v-for="score in scoresOf(row)" :key="score.criterion">
                                    {{ label(score.criterion) }}: {{ score.rating }}/5 → {{ score.points }}/{{ score.weight }}
                                    <span v-if="score.critical" class="text-red-600">critical</span>
                                </li>
                            </ul>
                            <p v-if="criticalNoteOf(row)" class="mt-2 font-medium">Critical fail: {{ criticalNoteOf(row) }}</p>
                        </details>
                    </div>
                </template>
            </AdminTable>
        </section>


        <form
            v-if="reviewAction === 'finalise' && activeReview !== null"
            class="grid gap-3 rounded-xl border bg-card p-5"
            @submit.prevent="submitFinalise"
        >
            <h3 class="font-semibold">Finalise {{ activeReview.reference }} · {{ activeReview.total }} points</h3>
            <p class="text-sm text-muted-foreground">
                Finalising locks the weighted scores. Remediation reviews need a coaching deadline; a critical fail
                automatically raises an escalated safety or privacy incident with a corrective action.
            </p>
            <label class="grid gap-2 text-sm">
                Coaching due
                <input v-model="finaliseForm.coaching_due_at" type="date" class="rounded border bg-background p-2" />
            </label>
            <p v-for="(error, key) in finaliseForm.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
            <div class="flex gap-3">
                <button :disabled="finaliseForm.processing" class="rounded bg-primary px-4 py-2 text-primary-foreground">Finalise review</button>
                <button type="button" class="rounded border px-4 py-2" @click="reviewAction = null">Cancel</button>
            </div>
        </form>

        <form
            v-if="reviewAction === 'coaching' && activeReview !== null"
            class="grid gap-3 rounded-xl border bg-card p-5"
            @submit.prevent="submitCoaching"
        >
            <h3 class="font-semibold">Coaching for {{ activeReview.reference }}</h3>
            <label class="grid gap-2 text-sm">
                Coaching conversation and agreed actions
                <textarea v-model="coachingForm.coaching_note" rows="3" maxlength="2000" required class="rounded border bg-background p-2"></textarea>
            </label>
            <p v-for="(error, key) in coachingForm.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
            <div class="flex gap-3">
                <button :disabled="coachingForm.processing" class="rounded bg-primary px-4 py-2 text-primary-foreground">Record coaching</button>
                <button type="button" class="rounded border px-4 py-2" @click="reviewAction = null">Cancel</button>
            </div>
        </form>

        <form
            v-if="reviewAction === 'calibration' && activeReview !== null"
            class="grid gap-3 rounded-xl border bg-card p-5"
            @submit.prevent="submitCalibration"
        >
            <h3 class="font-semibold">Calibrate {{ activeReview.reference }}</h3>
            <p class="text-sm text-muted-foreground">
                Primary score {{ activeReview.total }}. Enter your independent score; a second reviewer cannot reuse
                the primary reviewer account.
            </p>
            <label class="grid gap-2 text-sm">
                Independent score (0–100)
                <input v-model.number="calibrationForm.secondary_total" type="number" min="0" max="100" required class="rounded border bg-background p-2" />
            </label>
            <label class="grid gap-2 text-sm">
                Calibration note
                <textarea v-model="calibrationForm.note" rows="2" maxlength="2000" class="rounded border bg-background p-2"></textarea>
            </label>
            <p v-for="(error, key) in calibrationForm.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
            <div class="flex gap-3">
                <button :disabled="calibrationForm.processing" class="rounded bg-primary px-4 py-2 text-primary-foreground">Submit calibration</button>
                <button type="button" class="rounded border px-4 py-2" @click="reviewAction = null">Cancel</button>
            </div>
        </form>


        <section v-if="filters.tab === 'calibration'" class="space-y-3">
            <h2 class="text-lg font-semibold">Calibration</h2>
            <p class="text-sm text-muted-foreground">
                A second reviewer scores the same call independently. Variance above {{ tolerance }} points needs a
                written rationale before the QA lead can agree the final score.
            </p>
            <AdminTable
                :rows="calibrations.data"
                :columns="[
                    { key: 'review', label: 'Review' },
                    { key: 'primary_total', label: 'Primary' },
                    { key: 'reviewer', label: 'Second reviewer' },
                    { key: 'secondary_total', label: 'Secondary' },
                    { key: 'variance', label: 'Variance' },
                    { key: 'status', label: 'Status' },
                    { key: 'agreed_total', label: 'Agreed' },
                ]"
                :previous="calibrations.prev_page_url"
                :next="calibrations.next_page_url"
            >
                <template #actions="{ row }">
                    <button
                        v-if="canApprove && row.status === 'open'"
                        class="rounded border px-2 py-1 text-sm"
                        @click="startAgreement(row)"
                    >
                        Agree score
                    </button>
                </template>
            </AdminTable>

            <form v-if="activeCalibration !== null" class="grid gap-3 rounded-xl border bg-card p-5" @submit.prevent="submitAgreement">
                <h3 class="font-semibold">Agree calibration for {{ activeCalibration.review }}</h3>
                <p class="text-sm text-muted-foreground">
                    Primary {{ activeCalibration.primary_total }} · secondary {{ activeCalibration.secondary_total }} ·
                    variance {{ activeCalibration.variance }}.
                </p>
                <label class="grid gap-2 text-sm">
                    Agreed score (0–100)
                    <input v-model.number="agreementForm.agreed_total" type="number" min="0" max="100" required class="rounded border bg-background p-2" />
                </label>
                <label class="grid gap-2 text-sm">
                    Rationale
                    <textarea v-model="agreementForm.note" rows="2" maxlength="2000" class="rounded border bg-background p-2"></textarea>
                </label>
                <p v-for="(error, key) in agreementForm.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
                <div class="flex gap-3">
                    <button :disabled="agreementForm.processing" class="rounded bg-primary px-4 py-2 text-primary-foreground">Agree score</button>
                    <button type="button" class="rounded border px-4 py-2" @click="activeCalibration = null">Cancel</button>
                </div>
            </form>
        </section>

        <section v-if="filters.tab === 'scorecards'" class="space-y-3">
            <h2 class="text-lg font-semibold">Weighted scorecard</h2>
            <AdminTable
                :rows="scorecard"
                :columns="[
                    { key: 'label', label: 'Criterion' },
                    { key: 'weight', label: 'Weight' },
                    { key: 'critical', label: 'Critical override' },
                    { key: 'average', label: 'Average points' },
                    { key: 'count', label: 'Finalised reviews' },
                ]"
            />
            <p class="text-sm text-muted-foreground">
                Averages use the latest 200 finalised reviews. Ratings convert linearly: rating 5 earns the full
                weight, rating 0 earns nothing. Any critical criterion at 1 or 0 overrides the total.
            </p>
        </section>

        <p class="text-sm text-muted-foreground">Snapshot as of {{ asOf }}</p>
    </div>
</template>

