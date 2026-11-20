<script setup lang="ts">
import { reactive, ref } from "vue";
import { Head, router, useForm } from "@inertiajs/vue3";
import AppLayout from "@/layouts/AppLayout.vue";
import Heading from "@/components/Heading.vue";
import DentistNav from "@/components/DentistNav.vue";
import AdminTable from "@/components/AdminTable.vue";
import { toDateTimeInput } from "@/lib/dentists";
import {
    destroy as destroyRoster,
    generate as generateRoster,
    store as storeRoster,
    update as updateRoster,
} from "@/routes/dentists/rosters";
import { rosters as rostersPage } from "@/routes/dentists";

defineOptions({ layout: AppLayout });

type RosterRow = Record<string, unknown> & {
    id: number;
    dentist_id: number;
    kind: string;
    starts_at: string;
    ends_at: string;
    notes: string | null;
    dentist: { id: number; name: string } | null;
    creator: { id: number; name: string } | null;
};

const props = defineProps<{
    rosters: {
        data: RosterRow[];
        prev_page_url: string | null;
        next_page_url: string | null;
        total: number;
    };
    dentists: { id: number; name: string }[];
    counts: { current: number; upcoming: number; leave: number };
    kinds: string[];
    filters: { kind: string; dentist_id: string; window: string };
    canManage: boolean;
    service: {
        state: string;
        label: string;
        reason: string;
        opens_at: string;
        closes_at: string;
        next_opening_label: string | null;
        holiday: string | null;
    };
    holidays: { date: string; label: string; weekday: string }[];
    plannerPreview: {
        date: string;
        weekday: string;
        covered: boolean;
        reason: string | null;
    }[];
}>();

const filters = reactive({ ...props.filters });
const visible = ref(false);
const selected = ref<{ dentist: number; roster: number } | null>(null);

function defaultWindow(): { starts_at: string; ends_at: string } {
    const start = new Date();
    start.setMinutes(0, 0, 0);
    const end = new Date(start.getTime());
    end.setHours(end.getHours() + 8);

    return { starts_at: toDateTimeInput(start), ends_at: toDateTimeInput(end) };
}

const form = useForm({
    dentist_id: "",
    kind: "shift",
    ...defaultWindow(),
    notes: "",
});

function search() {
    router.get(rostersPage.url(), { ...filters }, { preserveState: true });
}

function clearFilters() {
    filters.kind = "";
    filters.dentist_id = "";
    filters.window = "";
    router.get(rostersPage.url(), {}, { preserveState: true });
}

function create() {
    selected.value = null;
    form.reset();
    form.dentist_id = props.dentists[0] ? String(props.dentists[0].id) : "";
    form.kind = "shift";
    Object.assign(form, defaultWindow());
    form.clearErrors();
    visible.value = true;
}

function edit(row: RosterRow) {
    selected.value = { dentist: row.dentist_id, roster: row.id };
    form.dentist_id = String(row.dentist_id);
    form.kind = row.kind;
    form.starts_at = row.starts_at.slice(0, 16);
    form.ends_at = row.ends_at.slice(0, 16);
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
        form.patch(updateRoster.url(selected.value), options);
    } else {
        form.post(storeRoster.url({ dentist: Number(form.dentist_id) }), options);
    }
}

function remove(row: RosterRow) {
    if (!window.confirm(`Remove the ${row.kind} window for ${row.dentist?.name ?? "this dentist"}?`)) {
        return;
    }

    router.delete(destroyRoster.url({ dentist: row.dentist_id, roster: row.id }), {
        preserveScroll: true,
    });
}

const generator = useForm({
    from: new Date().toISOString().slice(0, 10),
    weeks: 4,
    dentist_id: "",
});

function generateStandard(): void {
    generator.post(generateRoster.url(), { preserveScroll: true });
}
</script>

<template>
    <div>
        <Head title="Dentist rosters" />
        <div class="space-y-6 p-6">
            <Heading
                title="Dentist rosters"
                description="Shifts, cover and approved leave. A dentist is selectable only inside a published duty window and without a blocking override."
            />
            <DentistNav />

            <section
                class="overflow-hidden rounded-2xl border bg-gradient-to-br from-emerald-50 via-white to-sky-50 p-5 shadow-sm dark:from-emerald-950/40 dark:via-slate-900 dark:to-slate-900"
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold tracking-wide text-emerald-700 uppercase dark:text-emerald-300">
                            Published service window
                        </p>
                        <p class="mt-1 text-lg font-semibold">
                            Monday to Friday · {{ service.opens_at }}–{{ service.closes_at }} Ghana time
                        </p>
                        <p class="text-sm text-muted-foreground">
                            Closed on Ghana public holidays. {{ service.reason }}
                        </p>
                    </div>
                    <span
                        class="inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-semibold ring-1"
                        :class="
                            service.state === 'open'
                                ? 'bg-emerald-500/15 text-emerald-800 ring-emerald-500/30 dark:text-emerald-200'
                                : 'bg-slate-500/10 text-slate-700 ring-slate-500/20 dark:text-slate-200'
                        "
                    >
                        <span
                            class="size-2 rounded-full"
                            :class="service.state === 'open' ? 'exp-ring bg-emerald-500' : 'bg-slate-400'"
                            aria-hidden="true"
                        />
                        {{ service.label }}
                    </span>
                </div>

                <ul class="mt-4 flex flex-wrap gap-2">
                    <li
                        v-for="holiday in holidays"
                        :key="holiday.date"
                        class="rounded-full bg-white/80 px-3 py-1 text-xs text-slate-700 ring-1 ring-slate-200 dark:bg-slate-900/70 dark:text-slate-200 dark:ring-slate-800"
                    >
                        <span class="font-semibold">{{ holiday.weekday }} {{ holiday.date.slice(5) }}</span>
                        · {{ holiday.label }}
                    </li>
                </ul>
            </section>

            <section v-if="canManage" class="rounded-2xl border bg-card p-5 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold">Standard roster generator</h2>
                        <p class="text-xs text-muted-foreground">
                            Creates 10:00–14:00 shifts for every active dentist on service days only.
                            Public holidays and weekends are skipped, and existing windows are never
                            duplicated.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        <span
                            v-for="day in plannerPreview"
                            :key="day.date"
                            class="rounded-md px-2 py-1 text-[11px] font-medium ring-1"
                            :class="
                                day.covered
                                    ? 'bg-emerald-500/10 text-emerald-700 ring-emerald-500/20 dark:text-emerald-300'
                                    : 'bg-slate-500/5 text-slate-500 ring-slate-500/10'
                            "
                            :title="day.reason ?? 'Service day'"
                        >
                            {{ day.weekday }}
                        </span>
                    </div>
                </div>

                <form class="mt-4 grid gap-3 sm:grid-cols-4" @submit.prevent="generateStandard">
                    <label class="grid gap-1 text-xs font-medium text-muted-foreground">
                        Starting from
                        <input
                            v-model="generator.from"
                            type="date"
                            class="rounded-lg border bg-background px-3 py-2 text-sm"
                        />
                    </label>
                    <label class="grid gap-1 text-xs font-medium text-muted-foreground">
                        Weeks
                        <input
                            v-model.number="generator.weeks"
                            type="number"
                            min="1"
                            max="12"
                            class="rounded-lg border bg-background px-3 py-2 text-sm"
                        />
                    </label>
                    <label class="grid gap-1 text-xs font-medium text-muted-foreground">
                        Dentist
                        <select
                            v-model="generator.dentist_id"
                            class="rounded-lg border bg-background px-3 py-2 text-sm"
                        >
                            <option value="">Every active dentist</option>
                            <option v-for="dentist in dentists" :key="dentist.id" :value="String(dentist.id)">
                                {{ dentist.name }}
                            </option>
                        </select>
                    </label>
                    <div class="flex items-end">
                        <button
                            type="submit"
                            class="w-full rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-800 disabled:opacity-60"
                            :disabled="generator.processing"
                        >
                            Generate roster
                        </button>
                    </div>
                </form>
                <p v-if="generator.errors.from" class="mt-2 text-xs text-destructive">
                    {{ generator.errors.from }}
                </p>
            </section>

            <section class="grid gap-4 sm:grid-cols-3">
                <div
                    v-for="metric in [
                        { label: 'Windows now', value: counts.current },
                        { label: 'Upcoming', value: counts.upcoming },
                        { label: 'Leave on file', value: counts.leave },
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
                    >Dentist<select v-model="filters.dentist_id" class="rounded border bg-background p-2">
                        <option value="">All</option>
                        <option v-for="dentist in dentists" :key="dentist.id" :value="String(dentist.id)">
                            {{ dentist.name }}
                        </option>
                    </select></label
                >
                <label class="grid gap-2"
                    >Kind<select v-model="filters.kind" class="rounded border bg-background p-2">
                        <option value="">All</option>
                        <option v-for="kind in kinds" :key="kind" :value="kind">{{ kind }}</option>
                    </select></label
                >
                <label class="grid gap-2"
                    >Window<select v-model="filters.window" class="rounded border bg-background p-2">
                        <option value="">All</option>
                        <option value="current">In progress</option>
                        <option value="upcoming">Upcoming</option>
                        <option value="past">Past</option>
                    </select></label
                >
                <button type="submit" class="rounded bg-primary px-4 py-2 text-primary-foreground">Apply</button>
                <button type="button" class="text-sm underline" @click="clearFilters">Clear</button>
                <button v-if="canManage" type="button" class="rounded border px-4 py-2" @click="create">
                    Add window
                </button>
            </form>

            <form v-if="visible && canManage" class="grid max-w-xl gap-4 rounded-xl border p-5" @submit.prevent="save">
                <h2 class="font-semibold">{{ selected ? "Edit" : "Add" }} roster window</h2>
                <label class="grid gap-2"
                    >Dentist<select v-model="form.dentist_id" required class="rounded border bg-background p-2">
                        <option v-for="dentist in dentists" :key="dentist.id" :value="String(dentist.id)">
                            {{ dentist.name }}
                        </option>
                    </select></label
                >
                <label class="grid gap-2"
                    >Kind<select v-model="form.kind" class="rounded border bg-background p-2">
                        <option v-for="kind in kinds" :key="kind" :value="kind">{{ kind }}</option>
                    </select></label
                >
                <label class="grid gap-2"
                    >Starts (Africa/Accra)<input
                        v-model="form.starts_at"
                        type="datetime-local"
                        required
                        class="rounded border bg-background p-2"
                /></label>
                <label class="grid gap-2"
                    >Ends (Africa/Accra)<input
                        v-model="form.ends_at"
                        type="datetime-local"
                        required
                        class="rounded border bg-background p-2"
                /></label>
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
                :rows="props.rosters.data"
                :columns="[
                    { key: 'dentist', label: 'Dentist' },
                    { key: 'kind', label: 'Kind' },
                    { key: 'starts_at', label: 'Starts' },
                    { key: 'ends_at', label: 'Ends' },
                    { key: 'creator', label: 'Created by' },
                    { key: 'notes', label: 'Notes' },
                ]"
                :previous="props.rosters.prev_page_url"
                :next="props.rosters.next_page_url"
            >
                <template #actions="{ row }">
                    <div v-if="canManage" class="flex gap-3">
                        <button type="button" class="underline" @click="edit(row as RosterRow)">Edit</button>
                        <button type="button" class="underline" @click="remove(row as RosterRow)">Remove</button>
                    </div>
                    <span v-else class="text-xs text-slate-500">Read only</span>
                </template>
            </AdminTable>
        </div>
    </div>
</template>

