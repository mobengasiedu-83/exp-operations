<script setup lang="ts">
import { computed, reactive, ref } from "vue";
import { Head, router, useForm } from "@inertiajs/vue3";
import AppLayout from "@/layouts/AppLayout.vue";
import Heading from "@/components/Heading.vue";
import AdminTable from "@/components/AdminTable.vue";
import DentistNav from "@/components/DentistNav.vue";
import { formatClock } from "@/lib/live-operations";
import {
    coverageClasses,
    coverageLabel,
    credentialClasses,
    credentialLabel,
} from "@/lib/dentists";
import { destroy, index, store, update } from "@/routes/dentists";

defineOptions({ layout: AppLayout });

type DirectoryNumber = {
    id: number;
    number: string;
    formatted: string | null;
    label: string;
    is_primary: boolean;
    status: string;
};

type DentistRow = {
    id: number;
    name: string;
    email: string | null;
    notes: string | null;
    active: boolean;
    coverage_state: string;
    on_duty_until: string | null;
    next_shift_starts_at: string | null;
    selectable: boolean;
    numbers: DirectoryNumber[];
    credential_state: string;
    credentials_expired: number;
    credentials_expiring: number;
    call_activity: {
        attempts_24h: number;
        answered_24h: number;
        last_attempt_at: string | null;
    };
};

type UnmatchedRow = {
    number: string | null;
    channel: string;
    display: string;
    reason: string;
    attempts: number;
    last_seen_at: string | null;
};

const props = defineProps<{
    dentists: DentistRow[];
    summary: Record<string, number>;
    unmatched: {
        data: UnmatchedRow[];
        prev_page_url: string | null;
        next_page_url: string | null;
        current_page: number;
        last_page: number;
        total: number;
    };
    activityWindow: { from: string; to: string; days: number };
    asOf: string;
    filters: { q: string; state: string; status: string };
    canManage: boolean;
}>();

const filters = reactive({ ...props.filters });
const visible = ref(false);
const selected = ref<number | null>(null);
const form = useForm({ name: "", email: "", active: true, notes: "" });

const selectable = computed(() => props.dentists.filter((row) => row.selectable));

function search() {
    router.get(index.url(), { ...filters }, { preserveState: true });
}

function clearFilters() {
    filters.q = "";
    filters.state = "";
    filters.status = "";
    router.get(index.url(), {}, { preserveState: true });
}

function create() {
    selected.value = null;
    form.reset();
    form.clearErrors();
    visible.value = true;
}

function edit(row: DentistRow) {
    selected.value = row.id;
    form.name = row.name;
    form.email = row.email ?? "";
    form.active = row.active;
    form.notes = row.notes ?? "";
    form.clearErrors();
    visible.value = true;
}

function save() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            visible.value = false;
            form.reset();
        },
    };

    if (selected.value !== null) {
        form.patch(update.url(selected.value), options);
    } else {
        form.post(store.url(), options);
    }
}

function deactivate(row: DentistRow) {
    if (!window.confirm(`Deactivate ${row.name}? They will no longer be selectable for transfers.`)) {
        return;
    }

    router.delete(destroy.url(row.id), { preserveScroll: true });
}

function primaryNumber(row: DentistRow): DirectoryNumber | null {
    return (
        row.numbers.find((number) => number.is_primary && number.status === "approved") ??
        row.numbers.find((number) => number.status === "approved") ??
        null
    );
}
</script>

<template>
    <div>
        <Head title="Dentist directory" />
        <div class="space-y-6 p-6">
            <Heading
                title="Dentist directory"
                description="Approved contact numbers, credentials and published rosters. Coverage is driven by rosters and timestamped overrides; call activity is observed EXP transfer evidence only, never personal-mobile presence."
            />
            <DentistNav />

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
                <div
                    v-for="metric in [
                        { label: 'Rostered now', value: summary.rostered },
                        { label: 'On break', value: summary.on_break },
                        { label: 'On leave', value: summary.on_leave },
                        { label: 'Not rostered', value: summary.not_rostered },
                        { label: 'Selectable', value: summary.selectable },
                        { label: 'Unmatched numbers', value: summary.unmatched },
                    ]"
                    :key="metric.label"
                    class="rounded-xl border bg-white p-4 shadow-sm dark:bg-slate-900"
                >
                    <p class="text-xs uppercase tracking-wide text-slate-500">
                        {{ metric.label }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold">{{ metric.value }}</p>
                </div>
            </section>

            <p class="text-xs text-slate-500">
                Coverage as of {{ formatClock(asOf) }} · unmatched destinations
                over the last {{ activityWindow.days }} day(s).
            </p>

            <form class="flex flex-wrap items-end gap-4" @submit.prevent="search">
                <label class="grid gap-2"
                    >Search<input
                        v-model="filters.q"
                        placeholder="Name or number"
                        class="rounded border bg-background p-2"
                /></label>
                <label class="grid gap-2"
                    >Coverage<select
                        v-model="filters.state"
                        class="rounded border bg-background p-2"
                    >
                        <option value="">All</option>
                        <option
                            v-for="state in [
                                'rostered',
                                'on_break',
                                'on_leave',
                                'unavailable',
                                'not_rostered',
                                'inactive',
                            ]"
                            :key="state"
                            :value="state"
                        >
                            {{ coverageLabel(state) }}
                        </option>
                    </select></label
                >
                <label class="grid gap-2"
                    >Status<select
                        v-model="filters.status"
                        class="rounded border bg-background p-2"
                    >
                        <option value="">All</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select></label
                >
                <button type="submit" class="rounded bg-primary px-4 py-2 text-primary-foreground">
                    Apply
                </button>
                <button type="button" class="text-sm underline" @click="clearFilters">
                    Clear
                </button>
                <button
                    v-if="canManage"
                    type="button"
                    class="rounded border px-4 py-2"
                    @click="create"
                >
                    Add dentist
                </button>
            </form>

            <form
                v-if="visible && canManage"
                class="grid max-w-xl gap-4 rounded-xl border p-5"
                @submit.prevent="save"
            >
                <h2 class="font-semibold">{{ selected ? "Edit" : "Add" }} dentist</h2>
                <label class="grid gap-2"
                    >Name<input
                        v-model="form.name"
                        type="text"
                        required
                        class="rounded border bg-background p-2"
                /></label>
                <label class="grid gap-2"
                    >Email<input
                        v-model="form.email"
                        type="email"
                        class="rounded border bg-background p-2"
                /></label>
                <label class="flex gap-2"
                    ><input v-model="form.active" type="checkbox" />Active</label
                >
                <label class="grid gap-2"
                    >Notes<textarea
                        v-model="form.notes"
                        rows="3"
                        class="rounded border bg-background p-2"
                    ></textarea>
                </label>
                <p
                    v-for="(error, key) in form.errors"
                    :key="key"
                    class="text-sm text-red-600"
                    role="alert"
                >
                    {{ error }}
                </p>
                <div class="flex gap-3">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded bg-primary px-4 py-2 text-primary-foreground"
                    >
                        Save
                    </button>
                    <button type="button" @click="visible = false">Cancel</button>
                </div>
            </form>

            <section class="space-y-3">
                <h2 class="font-semibold">Select a rostered dentist</h2>
                <p class="text-xs text-slate-500">
                    Only rostered dentists with an approved number are
                    selectable. Being rostered never confirms their personal
                    mobile is free.
                </p>
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <div
                        v-for="row in selectable"
                        :key="row.id"
                        class="rounded-xl border bg-white p-4 shadow-sm dark:bg-slate-900"
                    >
                        <p class="font-medium">{{ row.name }}</p>
                        <p class="text-sm text-slate-500">
                            On duty until {{ formatClock(row.on_duty_until) }}
                        </p>
                        <p v-if="primaryNumber(row)" class="mt-2 font-mono text-sm">
                            <a :href="`tel:${primaryNumber(row)!.number}`">{{
                                primaryNumber(row)!.formatted
                            }}</a>
                        </p>
                        <p v-else class="mt-2 text-sm text-rose-600">
                            No approved number
                        </p>
                    </div>
                    <p
                        v-if="selectable.length === 0"
                        class="text-sm text-slate-500"
                    >
                        No dentist is currently rostered with an approved
                        number.
                    </p>
                </div>
            </section>

            <section class="space-y-3">
                <h2 class="font-semibold">Directory</h2>
                <div class="overflow-x-auto rounded-xl border bg-white dark:bg-slate-900">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-800">
                            <tr>
                                <th class="px-4 py-3">Dentist</th>
                                <th class="px-4 py-3">Coverage</th>
                                <th class="px-4 py-3">Credentials</th>
                                <th class="px-4 py-3">Numbers</th>
                                <th class="px-4 py-3">Duty until</th>
                                <th class="px-4 py-3">Next shift</th>
                                <th class="px-4 py-3">Calls (24h)</th>
                                <th class="px-4 py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in dentists" :key="row.id" class="border-t align-top">
                                <td class="px-4 py-3">
                                    <p class="font-medium">{{ row.name }}</p>
                                    <p class="text-xs text-slate-500">{{ row.email ?? "—" }}</p>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="coverageClasses(row.coverage_state)">
                                        {{ coverageLabel(row.coverage_state) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="credentialClasses(row.credential_state)">
                                        {{ credentialLabel(row.credential_state) }}
                                    </span>
                                    <p v-if="row.credentials_expired || row.credentials_expiring" class="mt-1 text-xs text-slate-500">
                                        {{ row.credentials_expired }} expired · {{ row.credentials_expiring }} expiring
                                    </p>
                                </td>
                                <td class="px-4 py-3">
                                    <p v-for="number in row.numbers" :key="number.id" class="font-mono text-xs">
                                        {{ number.formatted ?? number.number }}
                                        <span class="text-slate-500">({{ number.label }}, {{ number.status }})</span>
                                    </p>
                                    <p v-if="row.numbers.length === 0" class="text-xs text-slate-500">None</p>
                                </td>
                                <td class="px-4 py-3">{{ formatClock(row.on_duty_until) }}</td>
                                <td class="px-4 py-3">{{ formatClock(row.next_shift_starts_at) }}</td>
                                <td class="px-4 py-3">
                                    {{ row.call_activity.attempts_24h }} attempted /
                                    {{ row.call_activity.answered_24h }} answered
                                    <p class="text-xs text-slate-500">
                                        Last {{ formatClock(row.call_activity.last_attempt_at) }}
                                    </p>
                                </td>
                                <td class="px-4 py-3">
                                    <div v-if="canManage" class="flex gap-3">
                                        <button type="button" class="underline" @click="edit(row)">Edit</button>
                                        <button v-if="row.active" type="button" class="underline" @click="deactivate(row)">Deactivate</button>
                                    </div>
                                    <span v-else class="text-xs text-slate-500">Read only</span>
                                </td>
                            </tr>
                            <tr v-if="dentists.length === 0">
                                <td colspan="8" class="px-4 py-10 text-center text-slate-500">
                                    No dentists match the filters. Add a dentist to start the directory.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="space-y-3">
                <h2 class="font-semibold">Unknown mobile destinations ({{ unmatched.total }})</h2>
                <p class="text-xs text-slate-500">
                    Dialled numbers that do not match an approved or pending directory number over the last
                    {{ activityWindow.days }} day(s). Numbers stay masked here; add them to the directory so future
                    transfers can be attributed.
                </p>
                <AdminTable
                    :rows="unmatched.data"
                    :columns="[
                        { key: 'display', label: 'Destination' },
                        { key: 'reason', label: 'Reason' },
                        { key: 'attempts', label: 'Attempts' },
                        { key: 'last_seen_at', label: 'Last seen' },
                    ]"
                    :previous="unmatched.prev_page_url"
                    :next="unmatched.next_page_url"
                    row-noun="destination"
                    empty-title="No unknown destinations"
                    empty-description="Every recent mobile destination matches the directory."
                >
                    <template #cell-display="{ row }">
                        <span class="font-mono">{{ (row as UnmatchedRow).display }}</span>
                    </template>
                    <template #cell-reason="{ row }">
                        {{
                            (row as UnmatchedRow).reason === "unknown_number"
                                ? "Number not in directory"
                                : "Unparseable channel"
                        }}
                    </template>
                    <template #cell-last_seen_at="{ row }">
                        {{ formatClock((row as UnmatchedRow).last_seen_at) }}
                    </template>
                </AdminTable>
            </section>
        </div>
    </div>
</template>
