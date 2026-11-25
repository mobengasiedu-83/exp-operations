<script setup lang="ts">
import { reactive, ref } from "vue";
import { Head, router, useForm } from "@inertiajs/vue3";
import AppLayout from "@/layouts/AppLayout.vue";
import Heading from "@/components/Heading.vue";
import AdminTable from "@/components/AdminTable.vue";
import DentistNav from "@/components/DentistNav.vue";
import { formatClock } from "@/lib/live-operations";
import {
    destroy as destroyNumber,
    store as storeNumber,
    update as updateNumber,
} from "@/routes/dentists/numbers";
import { numbers as numbersPage } from "@/routes/dentists";

defineOptions({ layout: AppLayout });

type NumberRow = Record<string, unknown> & {
    id: number;
    dentist_id: number;
    number: string;
    original: string;
    label: string;
    is_primary: boolean;
    status: string;
    verified_at: string | null;
    notes: string | null;
    dentist: { id: number; name: string } | null;
};

type UnmatchedRow = Record<string, unknown> & {
    number: string | null;
    channel: string;
    display: string;
    reason: string;
    attempts: number;
    last_seen_at: string | null;
};

const props = defineProps<{
    numbers: {
        data: NumberRow[];
        prev_page_url: string | null;
        next_page_url: string | null;
        total: number;
    };
    dentists: { id: number; name: string }[];
    unmatched: {
        data: UnmatchedRow[];
        prev_page_url: string | null;
        next_page_url: string | null;
        current_page: number;
        last_page: number;
        total: number;
    };
    activityWindow: { from: string; to: string; days: number };
    counts: { approved: number; pending: number; retired: number };
    filters: { status: string; dentist_id: string; q: string };
    canManage: boolean;
}>();

const filters = reactive({ ...props.filters });
const visible = ref(false);
const selected = ref<{ dentist: number; number: number } | null>(null);
const form = useForm({
    dentist_id: "",
    number: "",
    label: "Mobile",
    is_primary: false,
    status: "approved",
    notes: "",
});

function search() {
    router.get(numbersPage.url(), { ...filters }, { preserveState: true });
}

function clearFilters() {
    filters.status = "";
    filters.dentist_id = "";
    filters.q = "";
    router.get(numbersPage.url(), {}, { preserveState: true });
}

function create() {
    selected.value = null;
    form.reset();
    form.dentist_id = props.dentists[0] ? String(props.dentists[0].id) : "";
    form.status = "approved";
    form.clearErrors();
    visible.value = true;
}

function edit(row: NumberRow) {
    selected.value = { dentist: row.dentist_id, number: row.id };
    form.dentist_id = String(row.dentist_id);
    form.number = row.original;
    form.label = row.label;
    form.is_primary = row.is_primary;
    form.status = row.status;
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
        form.patch(updateNumber.url(selected.value), options);
    } else {
        form.post(storeNumber.url({ dentist: Number(form.dentist_id) }), options);
    }
}

function retire(row: NumberRow) {
    if (!window.confirm(`Retire ${row.number}? It will no longer be matched for transfers.`)) {
        return;
    }

    router.delete(destroyNumber.url({ dentist: row.dentist_id, number: row.id }), {
        preserveScroll: true,
    });
}
</script>

<template>
    <div>
        <Head title="Dentist numbers" />
        <div class="space-y-6 p-6">
            <Heading
                title="Dentist numbers"
                description="Approved Ghana mobile numbers in E.164 form. Dialled mobile channels are normalised before matching so roster attribution never depends on operator formatting."
            />
            <DentistNav />

            <section class="grid gap-4 sm:grid-cols-3">
                <div
                    v-for="metric in [
                        { label: 'Approved', value: counts.approved },
                        { label: 'Pending', value: counts.pending },
                        { label: 'Retired', value: counts.retired },
                    ]"
                    :key="metric.label"
                    class="rounded-xl border bg-white p-4 shadow-sm dark:bg-slate-900"
                >
                    <p class="text-xs uppercase tracking-wide text-slate-500">{{ metric.label }}</p>
                    <p class="mt-1 text-2xl font-semibold">{{ metric.value }}</p>
                </div>
            </section>

            <form class="flex flex-wrap items-end gap-4" @submit.prevent="search">
                <label class="grid gap-2"
                    >Search<input
                        v-model="filters.q"
                        placeholder="024 123 4567"
                        class="rounded border bg-background p-2"
                /></label>
                <label class="grid gap-2"
                    >Dentist<select v-model="filters.dentist_id" class="rounded border bg-background p-2">
                        <option value="">All</option>
                        <option v-for="dentist in dentists" :key="dentist.id" :value="String(dentist.id)">
                            {{ dentist.name }}
                        </option>
                    </select></label
                >
                <label class="grid gap-2"
                    >Status<select v-model="filters.status" class="rounded border bg-background p-2">
                        <option value="">All</option>
                        <option v-for="status in ['approved', 'pending', 'retired']" :key="status" :value="status">
                            {{ status }}
                        </option>
                    </select></label
                >
                <button type="submit" class="rounded bg-primary px-4 py-2 text-primary-foreground">Apply</button>
                <button type="button" class="text-sm underline" @click="clearFilters">Clear</button>
                <button v-if="canManage" type="button" class="rounded border px-4 py-2" @click="create">
                    Add number
                </button>
            </form>

            <form v-if="visible && canManage" class="grid max-w-xl gap-4 rounded-xl border p-5" @submit.prevent="save">
                <h2 class="font-semibold">{{ selected ? "Edit" : "Add" }} dentist number</h2>
                <label class="grid gap-2"
                    >Dentist<select v-model="form.dentist_id" required class="rounded border bg-background p-2">
                        <option v-for="dentist in dentists" :key="dentist.id" :value="String(dentist.id)">
                            {{ dentist.name }}
                        </option>
                    </select></label
                >
                <label class="grid gap-2"
                    >Number<input
                        v-model="form.number"
                        type="tel"
                        required
                        placeholder="024 123 4567"
                        class="rounded border bg-background p-2"
                /></label>
                <label class="grid gap-2"
                    >Label<input v-model="form.label" type="text" class="rounded border bg-background p-2"
                /></label>
                <label class="grid gap-2"
                    >Status<select v-model="form.status" class="rounded border bg-background p-2">
                        <option v-for="status in ['approved', 'pending', 'retired']" :key="status" :value="status">
                            {{ status }}
                        </option>
                    </select></label
                >
                <label class="flex gap-2"><input v-model="form.is_primary" type="checkbox" />Primary number</label>
                <label class="grid gap-2"
                    >Notes<textarea v-model="form.notes" rows="3" class="rounded border bg-background p-2"></textarea>
                </label>
                <p v-for="(error, key) in form.errors" :key="key" class="text-sm text-red-600" role="alert">
                    {{ error }}
                </p>
                <div class="flex gap-3">
                    <button type="submit" :disabled="form.processing" class="rounded bg-primary px-4 py-2 text-primary-foreground">
                        Save
                    </button>
                    <button type="button" @click="visible = false">Cancel</button>
                </div>
            </form>

            <AdminTable
                :rows="props.numbers.data"
                :columns="[
                    { key: 'dentist', label: 'Dentist' },
                    { key: 'number', label: 'Normalised' },
                    { key: 'original', label: 'Entered as' },
                    { key: 'label', label: 'Label' },
                    { key: 'is_primary', label: 'Primary' },
                    { key: 'status', label: 'Status' },
                    { key: 'verified_at', label: 'Verified' },
                ]"
                :previous="props.numbers.prev_page_url"
                :next="props.numbers.next_page_url"
            >
                <template #actions="{ row }">
                    <div v-if="canManage" class="flex gap-3">
                        <button type="button" class="underline" @click="edit(row as NumberRow)">Edit</button>
                        <button
                            v-if="(row as NumberRow).status !== 'retired'"
                            type="button"
                            class="underline"
                            @click="retire(row as NumberRow)"
                        >
                            Retire
                        </button>
                    </div>
                    <span v-else class="text-xs text-slate-500">Read only</span>
                </template>
            </AdminTable>

            <section class="space-y-3">
                <h2 class="font-semibold">Unknown mobile destinations ({{ unmatched.total }})</h2>
                <p class="text-xs text-slate-500">
                    Dialled numbers without a directory match over the last {{ activityWindow.days }} day(s). Add the
                    number above so future transfers attribute to the right dentist. Destinations stay masked here.
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
                />
                <p class="text-xs text-slate-500">Checked at {{ formatClock(activityWindow.to) }}.</p>
            </section>
        </div>
    </div>
</template>

