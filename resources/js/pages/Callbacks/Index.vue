<script setup lang="ts">
import { reactive, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import AdminTable from '@/components/AdminTable.vue';
import { index, store, update, cancel } from '@/routes/callbacks';
import { store as storeAttempt } from '@/routes/callbacks/attempts';

defineOptions({ layout: AppLayout });

type CallbackRow = Record<string, unknown> & { id: number; case_id: number; priority: string; status: string; owner: string | null; window: string; due_at: string | null; escalated_at: string | null; attempts: number; outcome: string | null; revision: number };
type AttemptRow = Record<string, unknown> & { id: number; callback_id: number; case_id: number | null; outcome: string; note: string | null; neutral_voicemail_confirmed: boolean; attempted_at: string | null; recorded_by: string | null };
type Paginator<T> = { data: T[]; prev_page_url: string | null; next_page_url: string | null };

const props = defineProps<{
    callbacks: Paginator<CallbackRow> | null;
    attempts: Paginator<AttemptRow> | null;
    counts: Record<string, number>;
    filters: { view: string; mine: boolean; case_id?: string };
    cases: { id: number; priority: string | null; status: string }[];
    owners: { id: number; name: string }[];
    attemptOutcomes: string[];
    outcomes: string[];
    voicemailStatement: string;
    slaMinutes: number;
    canEdit: boolean;
    asOf: string;
}>();

const selected = ref<CallbackRow | null>(null);
const filters = reactive({ view: props.filters.view, mine: props.filters.mine, case_id: props.filters.case_id ?? '' });
const booking = useForm({ case_id: '', assigned_to: '', priority: 'routine', reason: '', due_at: '', window_start: '09:00', window_end: '17:00' });
const attemptForm = useForm({ outcome: 'no_answer', note: '', neutral_voicemail_confirmed: false });
const completion = useForm({ revision: 1, outcome: 'answered', outcome_note: '' });
const cancellation = useForm({ revision: 1, outcome_note: '' });

function label(value: string) { return value.replaceAll('_', ' '); }
function search() { router.get(index.url(), { view: filters.view, mine: filters.mine ? 1 : 0, case_id: filters.case_id }); }
function manage(row: CallbackRow) {
    selected.value = row;
    attemptForm.reset();
    completion.reset();
    cancellation.reset();
    completion.revision = row.revision;
    cancellation.revision = row.revision;
}
function book() { booking.post(store.url(), { preserveScroll: true, onSuccess: () => booking.reset() }); }
function saveAttempt() {
    if (selected.value === null) return;
    attemptForm.post(storeAttempt(selected.value.id).url, { preserveScroll: true, onSuccess: () => attemptForm.reset() });
}
function complete() {
    if (selected.value === null) return;
    completion.patch(update(selected.value.id).url, { preserveScroll: true, onSuccess: () => (selected.value = null) });
}
function cancelCallback() {
    if (selected.value === null) return;
    cancellation.post(cancel(selected.value.id).url, { preserveScroll: true, onSuccess: () => (selected.value = null) });
}
</script>

<template>
    <div class="space-y-6 p-6">
        <Head title="Callbacks" />
        <Heading
            title="Callbacks and follow-up"
            description="Book consent-aware callbacks with an owner, due time and approved contact window. Missed SLAs escalate to the supervisor queue; voicemail attempts never store clinical detail."
        />
        <nav class="flex flex-wrap gap-4 text-sm">
            <Link
                v-for="view in ['pending', 'due', 'overdue', 'attempts', 'completed']"
                :key="view"
                :href="index({ query: { view } })"
                :class="{ 'font-bold underline': filters.view === view }"
            >
                {{ label(view) }} ({{ counts[view] ?? 0 }})
            </Link>
        </nav>
        <form class="flex flex-wrap items-end gap-4 text-sm" @submit.prevent="search">
            <label class="flex items-center gap-2 py-2">
                <input v-model="filters.mine" type="checkbox" />Only my callbacks
            </label>
            <label class="grid gap-2">
                Case
                <input v-model="filters.case_id" type="number" min="1" class="w-28 rounded border bg-background p-2" />
            </label>
            <button class="rounded border px-3 py-2">Apply</button>
            <span class="text-muted-foreground">
                As of {{ asOf }} · a callback escalates {{ slaMinutes }} minutes after its due time
            </span>
        </form>

        <section v-if="canEdit" class="space-y-4 rounded-xl border bg-card p-5">
            <h2 class="font-semibold">Book a consented callback</h2>
            <p class="text-sm text-muted-foreground">
                Callbacks require granted consent with callback permission. Emergency (P1) cases and
                outstanding urgent referrals never enter this queue; confirm the approved emergency
                action instead.
            </p>
            <form class="grid gap-4 md:grid-cols-2" @submit.prevent="book">
                <label class="grid gap-2">
                    Case
                    <select v-model="booking.case_id" required class="rounded border bg-background p-2">
                        <option value="" disabled>Select an open case</option>
                        <option v-for="row in cases" :key="row.id" :value="String(row.id)">
                            #{{ row.id }} · {{ row.priority ?? 'no priority' }} · {{ row.status }}
                        </option>
                    </select>
                </label>
                <label class="grid gap-2">
                    Owner
                    <select v-model="booking.assigned_to" required class="rounded border bg-background p-2">
                        <option value="" disabled>Select the callback owner</option>
                        <option v-for="owner in owners" :key="owner.id" :value="String(owner.id)">{{ owner.name }}</option>
                    </select>
                </label>
                <label class="grid gap-2">
                    Priority
                    <select v-model="booking.priority" class="rounded border bg-background p-2">
                        <option v-for="priority in ['routine', 'urgent']" :key="priority" :value="priority">{{ priority }}</option>
                    </select>
                </label>
                <label class="grid gap-2">
                    Due at
                    <input v-model="booking.due_at" type="datetime-local" required class="rounded border bg-background p-2" />
                </label>
                <label class="grid gap-2">
                    Contact window opens
                    <input v-model="booking.window_start" type="time" required class="rounded border bg-background p-2" />
                </label>
                <label class="grid gap-2">
                    Contact window closes
                    <input v-model="booking.window_end" type="time" required class="rounded border bg-background p-2" />
                </label>
                <label class="grid gap-2 md:col-span-2">
                    Reason and agreed follow-up
                    <textarea v-model="booking.reason" required rows="3" class="rounded border bg-background p-2"></textarea>
                </label>
                <p v-for="(error, key) in booking.errors" :key="key" role="alert" class="text-sm text-red-600 md:col-span-2">{{ error }}</p>
                <button :disabled="booking.processing" class="w-fit rounded bg-primary px-4 py-2 text-primary-foreground">
                    Book callback
                </button>
            </form>
        </section>


        <section v-if="selected" class="space-y-5 rounded-xl border bg-card p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="font-semibold">Manage callback #{{ selected.id }} · case #{{ selected.case_id }}</h2>
                <button type="button" class="rounded border px-3 py-1 text-sm" @click="selected = null">Close</button>
            </div>
            <div class="grid gap-6 lg:grid-cols-3">
                <form class="space-y-3" @submit.prevent="saveAttempt">
                    <h3 class="font-medium">Record attempt</h3>
                    <label class="grid gap-2 text-sm">
                        Outcome
                        <select v-model="attemptForm.outcome" class="rounded border bg-background p-2">
                            <option v-for="outcome in attemptOutcomes" :key="outcome" :value="outcome">{{ label(outcome) }}</option>
                        </select>
                    </label>
                    <label v-if="attemptForm.outcome === 'voicemail'" class="flex items-start gap-2 text-sm">
                        <input v-model="attemptForm.neutral_voicemail_confirmed" type="checkbox" />
                        <span>No clinical or personal detail was left on the voicemail.</span>
                    </label>
                    <label v-else class="grid gap-2 text-sm">
                        Note
                        <textarea v-model="attemptForm.note" rows="3" class="rounded border bg-background p-2"></textarea>
                    </label>
                    <p v-if="attemptForm.outcome === 'voicemail'" class="text-xs text-muted-foreground">{{ voicemailStatement }}</p>
                    <p v-for="(error, key) in attemptForm.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
                    <button :disabled="attemptForm.processing" class="rounded border px-3 py-2 text-sm">Save attempt</button>
                </form>
                <form class="space-y-3" @submit.prevent="complete">
                    <h3 class="font-medium">Complete callback</h3>
                    <label class="grid gap-2 text-sm">
                        Outcome
                        <select v-model="completion.outcome" class="rounded border bg-background p-2">
                            <option v-for="outcome in outcomes" :key="outcome" :value="outcome">{{ label(outcome) }}</option>
                        </select>
                    </label>
                    <label class="grid gap-2 text-sm">
                        Outcome note
                        <textarea v-model="completion.outcome_note" rows="3" class="rounded border bg-background p-2"></textarea>
                    </label>
                    <p v-for="(error, key) in completion.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
                    <button :disabled="completion.processing" class="rounded border px-3 py-2 text-sm">Complete callback</button>
                </form>
                <form class="space-y-3" @submit.prevent="cancelCallback">
                    <h3 class="font-medium">Cancel callback</h3>
                    <label class="grid gap-2 text-sm">
                        Cancellation reason
                        <textarea v-model="cancellation.outcome_note" required rows="3" class="rounded border bg-background p-2"></textarea>
                    </label>
                    <p v-for="(error, key) in cancellation.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
                    <button :disabled="cancellation.processing" class="rounded border px-3 py-2 text-sm">Cancel callback</button>
                </form>
            </div>
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-semibold">{{ filters.view === 'attempts' ? 'Attempts' : 'Callbacks' }}</h2>
            <AdminTable
                v-if="callbacks"
                :rows="callbacks.data"
                :columns="[
                    { key: 'id', label: 'Callback' },
                    { key: 'case_id', label: 'Case' },
                    { key: 'priority', label: 'Priority' },
                    { key: 'owner', label: 'Owner' },
                    { key: 'window', label: 'Contact window' },
                    { key: 'due_at', label: 'Due' },
                    { key: 'status', label: 'Status' },
                    { key: 'attempts', label: 'Attempts' },
                    { key: 'escalated_at', label: 'Escalated' },
                    { key: 'outcome', label: 'Outcome' },
                ]"
                :previous="callbacks.prev_page_url"
                :next="callbacks.next_page_url"
            >
                <template #actions="{ row }">
                    <button v-if="canEdit" type="button" class="underline" @click="manage(row as CallbackRow)">Manage</button>
                </template>
            </AdminTable>
            <AdminTable
                v-else-if="attempts"
                :rows="attempts.data"
                :columns="[
                    { key: 'callback_id', label: 'Callback' },
                    { key: 'case_id', label: 'Case' },
                    { key: 'outcome', label: 'Outcome' },
                    { key: 'note', label: 'Note' },
                    { key: 'neutral_voicemail_confirmed', label: 'Neutral voicemail' },
                    { key: 'attempted_at', label: 'Attempted' },
                    { key: 'recorded_by', label: 'Recorded by' },
                    { key: 'callback_status', label: 'Callback status' },
                ]"
                :previous="attempts.prev_page_url"
                :next="attempts.next_page_url"
            />
            <p v-else class="text-sm text-muted-foreground">No callbacks in this view.</p>
        </section>
    </div>
</template>

