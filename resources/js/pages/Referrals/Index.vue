<script setup lang="ts">
import { reactive, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import AdminTable from '@/components/AdminTable.vue';
import { index, store, action, outcome, cancel } from '@/routes/referrals';

defineOptions({ layout: AppLayout });

type ReferralRow = Record<string, unknown> & {
    id: number; case_id: number; case_priority: string | null; route: string; status: string;
    destination_name: string; destination_contact: string | null; reason: string; dentist: string | null;
    action_reference: string | null; outcome: string | null; outcome_note: string | null; revision: number;
};
type DestinationRow = Record<string, unknown> & { destination_name: string; referrals: number; urgent: number; last_referred_at: string | null };
type Paginator<T> = { data: T[]; prev_page_url: string | null; next_page_url: string | null };

const props = defineProps<{
    referrals: Paginator<ReferralRow>;
    destinations: DestinationRow[];
    counts: Record<string, number>;
    filters: { view: string; route?: string };
    cases: { id: number; priority: string | null; status: string }[];
    dentists: { id: number; name: string }[];
    routes: string[];
    outcomes: string[];
    canEdit: boolean;
}>();

const selected = ref<ReferralRow | null>(null);
const filters = reactive({ view: props.filters.view, route: props.filters.route ?? '' });
const creation = useForm({ case_id: '', route: 'routine', destination_name: '', destination_contact: '', reason: '', dentist_id: '', follow_up_at: '' });
const actionForm = useForm({ revision: 1, action_reference: '' });
const outcomeForm = useForm({ revision: 1, outcome: 'accepted', outcome_note: '' });
const cancelForm = useForm({ revision: 1, outcome_note: '' });

function label(value: string) { return value.replaceAll('_', ' '); }
function search() { router.get(index.url(), { view: filters.view, route: filters.route }); }
function manage(row: ReferralRow) {
    selected.value = row;
    actionForm.reset();
    outcomeForm.reset();
    cancelForm.reset();
    actionForm.revision = row.revision;
    outcomeForm.revision = row.revision;
    cancelForm.revision = row.revision;
}
function create() { creation.post(store.url(), { preserveScroll: true, onSuccess: () => creation.reset() }); }
function recordAction() {
    if (selected.value === null) return;
    actionForm.patch(action(selected.value.id).url, { preserveScroll: true, onSuccess: () => (selected.value = null) });
}
function recordOutcome() {
    if (selected.value === null) return;
    outcomeForm.patch(outcome(selected.value.id).url, { preserveScroll: true, onSuccess: () => (selected.value = null) });
}
function cancelReferral() {
    if (selected.value === null) return;
    cancelForm.post(cancel(selected.value.id).url, { preserveScroll: true, onSuccess: () => (selected.value = null) });
}
</script>


<template>
    <div class="space-y-6 p-6">
        <Head title="Referrals" />
        <Heading
            title="Referral directory, actions and outcomes"
            description="Routine and urgent referrals only. Emergency (P1) handling never waits in a referral or callback queue; confirm the approved emergency action and supervisor escalation instead."
        />
        <nav class="flex flex-wrap gap-4 text-sm">
            <a href="#" :class="{ 'font-bold underline': filters.view === 'directory' }" @click.prevent="router.get(index.url(), { view: 'directory' })">Directory</a>
            <a href="#" :class="{ 'font-bold underline': filters.view === 'actions' }" @click.prevent="router.get(index.url(), { view: 'actions' })">
                Actions ({{ counts.actions ?? 0 }})
            </a>
            <a href="#" :class="{ 'font-bold underline': filters.view === 'outcomes' }" @click.prevent="router.get(index.url(), { view: 'outcomes' })">
                Outcomes ({{ counts.outcomes ?? 0 }})
            </a>
            <span class="text-muted-foreground">Urgent referrals open: {{ counts.urgent_open ?? 0 }}</span>
        </nav>
        <form class="flex flex-wrap items-end gap-4 text-sm" @submit.prevent="search">
            <label class="grid gap-2">
                Route
                <select v-model="filters.route" class="rounded border bg-background p-2">
                    <option value="">All</option>
                    <option v-for="route in routes" :key="route" :value="route">{{ label(route) }}</option>
                </select>
            </label>
            <button class="rounded border px-3 py-2">Apply</button>
        </form>

        <section v-if="canEdit" class="space-y-4 rounded-xl border bg-card p-5">
            <h2 class="font-semibold">Record a referral</h2>
            <form class="grid gap-4 md:grid-cols-2" @submit.prevent="create">
                <label class="grid gap-2">
                    Case
                    <select v-model="creation.case_id" required class="rounded border bg-background p-2">
                        <option value="" disabled>Select a P2, P3 or resolved case</option>
                        <option v-for="row in cases" :key="row.id" :value="String(row.id)">
                            #{{ row.id }} · {{ row.priority ?? 'no priority' }} · {{ row.status }}
                        </option>
                    </select>
                </label>
                <label class="grid gap-2">
                    Route
                    <select v-model="creation.route" class="rounded border bg-background p-2">
                        <option v-for="route in routes" :key="route" :value="route">{{ label(route) }}</option>
                    </select>
                </label>
                <label class="grid gap-2">
                    Destination
                    <input v-model="creation.destination_name" required maxlength="150" class="rounded border bg-background p-2" />
                </label>
                <label class="grid gap-2">
                    Destination contact
                    <input v-model="creation.destination_contact" maxlength="150" class="rounded border bg-background p-2" />
                </label>
                <label class="grid gap-2">
                    Clinician (optional)
                    <select v-model="creation.dentist_id" class="rounded border bg-background p-2">
                        <option value="">Not assigned</option>
                        <option v-for="dentist in dentists" :key="dentist.id" :value="String(dentist.id)">{{ dentist.name }}</option>
                    </select>
                </label>
                <label class="grid gap-2">
                    Follow up at
                    <input v-model="creation.follow_up_at" type="datetime-local" class="rounded border bg-background p-2" />
                </label>
                <label class="grid gap-2 md:col-span-2">
                    Referral reason
                    <textarea v-model="creation.reason" required rows="3" class="rounded border bg-background p-2"></textarea>
                </label>
                <p v-for="(error, key) in creation.errors" :key="key" role="alert" class="text-sm text-red-600 md:col-span-2">{{ error }}</p>
                <button :disabled="creation.processing" class="w-fit rounded bg-primary px-4 py-2 text-primary-foreground">Record referral</button>
            </form>
        </section>

        <section v-if="filters.view === 'directory'" class="space-y-3">
            <h2 class="text-lg font-semibold">Known destinations</h2>
            <AdminTable
                :rows="destinations"
                :columns="[
                    { key: 'destination_name', label: 'Destination' },
                    { key: 'referrals', label: 'Referrals' },
                    { key: 'urgent', label: 'Urgent' },
                    { key: 'last_referred_at', label: 'Last referred' },
                ]"
            />
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-semibold">{{ filters.view === 'outcomes' ? 'Recorded outcomes' : 'Referrals' }}</h2>
            <AdminTable
                :rows="referrals.data"
                :columns="[
                    { key: 'id', label: 'Referral' },
                    { key: 'case_id', label: 'Case' },
                    { key: 'case_priority', label: 'Priority' },
                    { key: 'route', label: 'Route' },
                    { key: 'status', label: 'Status' },
                    { key: 'destination_name', label: 'Destination' },
                    { key: 'dentist', label: 'Clinician' },
                    { key: 'follow_up_at', label: 'Follow up' },
                    { key: 'action_reference', label: 'Action reference' },
                    { key: 'outcome', label: 'Outcome' },
                ]"
                :previous="referrals.prev_page_url"
                :next="referrals.next_page_url"
            >
                <template #actions="{ row }">
                    <button v-if="canEdit" type="button" class="underline" @click="manage(row as ReferralRow)">Manage</button>
                </template>
            </AdminTable>
        </section>

        <section v-if="selected" class="space-y-5 rounded-xl border bg-card p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="font-semibold">Manage referral #{{ selected.id }} · case #{{ selected.case_id }} · {{ selected.destination_name }}</h2>
                <button type="button" class="rounded border px-3 py-1 text-sm" @click="selected = null">Close</button>
            </div>
            <p class="text-sm text-muted-foreground">
                Route: {{ label(selected.route) }} · status: {{ label(selected.status) }} · reason: {{ selected.reason }}
            </p>
            <div class="grid gap-6 lg:grid-cols-3">
                <form class="space-y-3" @submit.prevent="recordAction">
                    <h3 class="font-medium">Record action</h3>
                    <label class="grid gap-2 text-sm">
                        Action reference
                        <input v-model="actionForm.action_reference" required maxlength="150" class="rounded border bg-background p-2" />
                    </label>
                    <p v-for="(error, key) in actionForm.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
                    <button :disabled="actionForm.processing" class="rounded border px-3 py-2 text-sm">Save action</button>
                </form>
                <form class="space-y-3" @submit.prevent="recordOutcome">
                    <h3 class="font-medium">Record outcome</h3>
                    <label class="grid gap-2 text-sm">
                        Outcome
                        <select v-model="outcomeForm.outcome" class="rounded border bg-background p-2">
                            <option v-for="value in outcomes" :key="value" :value="value">{{ label(value) }}</option>
                        </select>
                    </label>
                    <label class="grid gap-2 text-sm">
                        Outcome note
                        <textarea v-model="outcomeForm.outcome_note" required rows="3" class="rounded border bg-background p-2"></textarea>
                    </label>
                    <p v-for="(error, key) in outcomeForm.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
                    <button :disabled="outcomeForm.processing" class="rounded border px-3 py-2 text-sm">Save outcome</button>
                </form>
                <form class="space-y-3" @submit.prevent="cancelReferral">
                    <h3 class="font-medium">Cancel referral</h3>
                    <label class="grid gap-2 text-sm">
                        Cancellation note
                        <textarea v-model="cancelForm.outcome_note" required rows="3" class="rounded border bg-background p-2"></textarea>
                    </label>
                    <p v-for="(error, key) in cancelForm.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
                    <button :disabled="cancelForm.processing" class="rounded border px-3 py-2 text-sm">Cancel referral</button>
                </form>
            </div>
        </section>
    </div>
</template>
