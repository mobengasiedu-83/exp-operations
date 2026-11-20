<script setup lang="ts">
import { reactive, ref } from "vue";
import { Head, router, useForm } from "@inertiajs/vue3";
import AppLayout from "@/layouts/AppLayout.vue";
import Heading from "@/components/Heading.vue";
import DentistNav from "@/components/DentistNav.vue";
import AdminTable from "@/components/AdminTable.vue";
import { coverageClasses, coverageLabel, toDateTimeInput } from "@/lib/dentists";
import { formatClock } from "@/lib/live-operations";
import { destroy as destroyOverride, store as storeOverride } from "@/routes/dentists/availability";
import { availability as availabilityPage } from "@/routes/dentists";

defineOptions({ layout: AppLayout });

type CoverageRow = {
    id: number;
    name: string;
    active: boolean;
    coverage_state: string;
    on_duty_until: string | null;
    override: {
        status: string;
        starts_at: string;
        ends_at: string;
        reason: string | null;
    } | null;
};

type OverrideRow = Record<string, unknown> & {
    id: number;
    dentist_id: number;
    status: string;
    starts_at: string;
    ends_at: string;
    reason: string | null;
    dentist: { id: number; name: string } | null;
    recorder: { id: number; name: string } | null;
};

const props = defineProps<{
    dentists: CoverageRow[];
    summary: Record<string, number>;
    asOf: string;
    overrides: {
        data: OverrideRow[];
        prev_page_url: string | null;
        next_page_url: string | null;
        total: number;
    };
    allDentists: { id: number; name: string }[];
    statuses: string[];
    filters: { state: string; dentist_id: string };
    canManage: boolean;
}>();

const filters = reactive({ ...props.filters });
const visible = ref(false);

function defaultWindow(): { starts_at: string; ends_at: string } {
    const start = new Date();
    const end = new Date(start.getTime() + 30 * 60000);

    return { starts_at: toDateTimeInput(start), ends_at: toDateTimeInput(end) };
}

const form = useForm({
    dentist_id: "",
    status: "break",
    ...defaultWindow(),
    reason: "",
});

function search() {
    router.get(availabilityPage.url(), { ...filters }, { preserveState: true });
}

function clearFilters() {
    filters.state = "";
    filters.dentist_id = "";
    router.get(availabilityPage.url(), {}, { preserveState: true });
}

function open(row?: CoverageRow | { id: number }) {
    form.reset();
    form.dentist_id = String(row?.id ?? props.allDentists[0]?.id ?? "");
    form.status = "break";
    Object.assign(form, defaultWindow());
    form.clearErrors();
    visible.value = true;
}

function save() {
    form.post(storeOverride.url({ dentist: Number(form.dentist_id) }), {
        preserveScroll: true,
        onSuccess: () => {
            visible.value = false;
            form.reset();
        },
    });
}

function end(row: OverrideRow) {
    if (!window.confirm(`End the ${row.status} override for ${row.dentist?.name ?? "this dentist"}?`)) {
        return;
    }

    router.delete(destroyOverride.url({ dentist: row.dentist_id, override: row.id }), {
        preserveScroll: true,
    });
}
</script>

<template>
    <div>
        <Head title="Dentist availability" />
        <div class="space-y-6 p-6">
            <Heading
                title="Dentist availability"
                description="Timestamped manual overrides layered over published rosters. Availability is a scheduling statement, not evidence that a personal mobile is reachable."
            />
            <DentistNav />

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
                <div
                    v-for="metric in [
                        { label: 'Rostered', value: summary.rostered },
                        { label: 'On break', value: summary.on_break },
                        { label: 'On leave', value: summary.on_leave },
                        { label: 'Unavailable', value: summary.unavailable },
                        { label: 'Not rostered', value: summary.not_rostered },
                        { label: 'Inactive', value: summary.inactive },
                    ]"
                    :key="metric.label"
                    class="rounded-xl border bg-white p-4 shadow-sm dark:bg-slate-900"
                >
                    <p class="text-xs uppercase tracking-wide text-slate-500">{{ metric.label }}</p>
                    <p class="mt-1 text-2xl font-semibold">{{ metric.value }}</p>
                </div>
            </section>

            <p class="text-xs text-slate-500">Evaluated at {{ formatClock(asOf) }}.</p>

            <form class="flex flex-wrap items-end gap-4" @submit.prevent="search">
                <label class="grid gap-2"
                    >Dentist<select v-model="filters.dentist_id" class="rounded border bg-background p-2">
                        <option value="">All</option>
                        <option v-for="dentist in allDentists" :key="dentist.id" :value="String(dentist.id)">
                            {{ dentist.name }}
                        </option>
                    </select></label
                >
                <label class="grid gap-2"
                    >Coverage<select v-model="filters.state" class="rounded border bg-background p-2">
                        <option value="">All</option>
                        <option
                            v-for="state in ['rostered', 'on_break', 'on_leave', 'unavailable', 'not_rostered', 'inactive']"
                            :key="state"
                            :value="state"
                        >
                            {{ coverageLabel(state) }}
                        </option>
                    </select></label
                >
                <button type="submit" class="rounded bg-primary px-4 py-2 text-primary-foreground">Apply</button>
                <button type="button" class="text-sm underline" @click="clearFilters">Clear</button>
                <button v-if="canManage" type="button" class="rounded border px-4 py-2" @click="open()">
                    Record override
                </button>
            </form>

            <form v-if="visible && canManage" class="grid max-w-xl gap-4 rounded-xl border p-5" @submit.prevent="save">
                <h2 class="font-semibold">Availability override</h2>
                <label class="grid gap-2"
                    >Dentist<select v-model="form.dentist_id" required class="rounded border bg-background p-2">
                        <option v-for="dentist in allDentists" :key="dentist.id" :value="String(dentist.id)">
                            {{ dentist.name }}
                        </option>
                    </select></label
                >
                <label class="grid gap-2"
                    >Status<select v-model="form.status" class="rounded border bg-background p-2">
                        <option v-for="status in statuses" :key="status" :value="status">{{ status }}</option>
                    </select></label
                >
                <label class="grid gap-2"
                    >Starts<input
                        v-model="form.starts_at"
                        type="datetime-local"
                        required
                        class="rounded border bg-background p-2"
                /></label>
                <label class="grid gap-2"
                    >Ends<input
                        v-model="form.ends_at"
                        type="datetime-local"
                        required
                        class="rounded border bg-background p-2"
                /></label>
                <label class="grid gap-2"
                    >Reason<input v-model="form.reason" type="text" class="rounded border bg-background p-2"
                /></label>
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

            <section class="space-y-3">
                <h2 class="font-semibold">Current coverage</h2>
                <div class="overflow-x-auto rounded-xl border bg-white dark:bg-slate-900">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-800">
                            <tr>
                                <th class="px-4 py-3">Dentist</th>
                                <th class="px-4 py-3">Coverage</th>
                                <th class="px-4 py-3">Duty until</th>
                                <th class="px-4 py-3">Active override</th>
                                <th class="px-4 py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in dentists" :key="row.id" class="border-t align-top">
                                <td class="px-4 py-3 font-medium">{{ row.name }}</td>
                                <td class="px-4 py-3">
                                    <span
                                        class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                        :class="coverageClasses(row.coverage_state)"
                                    >
                                        {{ coverageLabel(row.coverage_state) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">{{ formatClock(row.on_duty_until) }}</td>
                                <td class="px-4 py-3">
                                    <template v-if="row.override">
                                        {{ row.override.status }} until {{ formatClock(row.override.ends_at) }}
                                        <p v-if="row.override.reason" class="text-xs text-slate-500">
                                            {{ row.override.reason }}
                                        </p>
                                    </template>
                                    <span v-else class="text-slate-500">—</span>
                                </td>
                                <td class="px-4 py-3">
                                    <button v-if="canManage" type="button" class="underline" @click="open(row)">
                                        Override
                                    </button>
                                    <span v-else class="text-xs text-slate-500">Read only</span>
                                </td>
                            </tr>
                            <tr v-if="dentists.length === 0">
                                <td colspan="5" class="px-4 py-10 text-center text-slate-500">
                                    No dentists match the filters.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="space-y-3">
                <h2 class="font-semibold">Override history</h2>
                <p class="text-xs text-slate-500">
                    Timestamped records are retained when ended; ending an override never rewrites its start time.
                </p>
                <AdminTable
                    :rows="props.overrides.data"
                    :columns="[
                        { key: 'dentist', label: 'Dentist' },
                        { key: 'status', label: 'Status' },
                        { key: 'starts_at', label: 'Starts' },
                        { key: 'ends_at', label: 'Ends' },
                        { key: 'reason', label: 'Reason' },
                        { key: 'recorder', label: 'Recorded by' },
                    ]"
                    :previous="props.overrides.prev_page_url"
                    :next="props.overrides.next_page_url"
                >
                    <template #actions="{ row }">
                        <button
                            v-if="canManage"
                            type="button"
                            class="underline"
                            @click="end(row as OverrideRow)"
                        >
                            End now
                        </button>
                        <span v-else class="text-xs text-slate-500">Read only</span>
                    </template>
                </AdminTable>
            </section>
        </div>
    </div>
</template>

