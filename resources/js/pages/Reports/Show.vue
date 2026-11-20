<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import { exportMethod, index, show, store } from '@/routes/reports';

defineOptions({ layout: AppLayout });

type Kpi = {
    key: string;
    label: string;
    category: string;
    unit: string;
    value: number | null;
    numerator: number | null;
    denominator: number | null;
    definition: string;
    exclusions: string[];
};

type Run = {
    id: number;
    reference: string;
    period_start: string;
    period_end: string;
    generated_at: string;
    generated_by: string | null;
    kpi_count: number;
    revision: number;
    checksum: string;
};

const props = defineProps<{
    definition: {
        id: number;
        key: string;
        name: string;
        category: string;
        grain: string;
        description: string;
        sensitive: boolean;
        revision: number;
    };
    period: { from: string; to: string; max_days: number; timezone: string };
    kpis: Kpi[];
    runs: Run[];
    canExport: boolean;
}>();

const filters = ref({ from: props.period.from, to: props.period.to });
const saveForm = useForm({ from: props.period.from, to: props.period.to });

function preview(): void {
    router.get(show.url(props.definition.id), filters.value, {
        preserveState: true,
        preserveScroll: true,
    });
}

function save(): void {
    saveForm.from = filters.value.from;
    saveForm.to = filters.value.to;
    saveForm.post(store.url(props.definition.id), { preserveScroll: true });
}

function format(row: Kpi): string {
    if (row.value === null || row.value === undefined) {
        return 'Not available';
    }

    const value = Number(row.value);

    switch (row.unit) {
        case 'percent':
            return `${value.toFixed(2)}%`;
        case 'seconds':
            return `${value.toFixed(1)} s`;
        case 'minutes':
            return `${value.toFixed(2)} min`;
        case 'hours':
            return `${value.toFixed(2)} h`;
        case 'currency':
        case 'score':
            return value.toFixed(2);
        default:
            return String(value);
    }
}

function evidence(row: Kpi): string {
    return `${row.numerator ?? '—'} / ${row.denominator ?? '—'}`;
}

const grouped = computed(() => {
    const groups = new Map<string, Kpi[]>();

    for (const kpi of props.kpis) {
        groups.set(kpi.category, [...(groups.get(kpi.category) ?? []), kpi]);
    }

    return [...groups.entries()].map(([category, kpis]) => ({
        category,
        kpis,
    }));
});
</script>

<template>
    <Head :title="`${definition.name} report`" />
    <div class="space-y-6 p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading :title="definition.name" :description="definition.description" />
            <Link
                :href="index()"
                class="rounded-md border px-3 py-1.5 text-sm font-medium"
            >
                All reports
            </Link>
        </div>

        <form
            class="flex flex-wrap items-end gap-3 rounded-xl border p-4"
            @submit.prevent="preview"
        >
            <label class="text-sm">
                <span class="mb-1 block font-medium">From</span>
                <input
                    v-model="filters.from"
                    type="date"
                    class="rounded-md border px-2 py-1.5"
                />
            </label>
            <label class="text-sm">
                <span class="mb-1 block font-medium">To</span>
                <input
                    v-model="filters.to"
                    type="date"
                    class="rounded-md border px-2 py-1.5"
                />
            </label>
            <button
                type="submit"
                class="rounded-md border px-3 py-1.5 text-sm font-medium"
            >
                Preview
            </button>
            <button
                type="button"
                class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white dark:bg-slate-100 dark:text-slate-900"
                :disabled="saveForm.processing"
                @click="save"
            >
                Save run
            </button>
            <a
                v-if="canExport"
                :href="exportMethod(definition.id, { query: filters }).url"
                class="rounded-md border px-3 py-1.5 text-sm font-medium"
            >
                Export KPI evidence (CSV)
            </a>
            <p class="w-full text-xs text-muted-foreground">
                Dates are {{ period.timezone }} calendar dates and a manual
                report covers at most {{ period.max_days }} days. Export writes
                an audit entry and needs the explicit export grant.
            </p>
            <p
                v-if="saveForm.errors.to || saveForm.errors.from"
                class="w-full text-xs text-red-600"
            >
                {{ saveForm.errors.to ?? saveForm.errors.from }}
            </p>
        </form>



        <section
            v-for="group in grouped"
            :key="group.category"
            class="space-y-2"
        >
            <h2 class="text-lg font-semibold capitalize">
                {{ group.category.replace('_', ' ') }}
            </h2>
            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b">
                            <th class="p-3">KPI</th>
                            <th class="p-3">Value</th>
                            <th class="p-3">Numerator / denominator</th>
                            <th class="p-3">Definition</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in group.kpis"
                            :key="row.key"
                            class="border-b last:border-0"
                        >
                            <td class="p-3 font-medium">
                                {{ row.label }}
                                <span class="block text-xs font-normal text-muted-foreground">
                                    {{ row.key }} · {{ row.unit }}
                                </span>
                            </td>
                            <td class="p-3">
                                <span
                                    :class="
                                        row.value === null
                                            ? 'text-muted-foreground'
                                            : 'font-semibold'
                                    "
                                >
                                    {{ format(row) }}
                                </span>
                            </td>
                            <td class="p-3 text-muted-foreground">
                                {{ evidence(row) }}
                            </td>
                            <td class="max-w-md p-3 text-xs text-muted-foreground">
                                {{ row.definition }}
                                <span v-if="row.exclusions.length" class="mt-1 block">
                                    Excludes: {{ row.exclusions.join('; ') }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <p
            v-if="kpis.length === 0"
            class="rounded-xl border p-6 text-muted-foreground"
        >
            This definition publishes no KPI you are permitted to read.
        </p>

        <section class="space-y-2">
            <h2 class="text-lg font-semibold">Saved snapshots</h2>
            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b">
                            <th class="p-3">Reference</th>
                            <th class="p-3">Period</th>
                            <th class="p-3">Generated</th>
                            <th class="p-3">By</th>
                            <th class="p-3">KPIs</th>
                            <th class="p-3">Revision</th>
                            <th class="p-3">Checksum</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="run in runs"
                            :key="run.id"
                            class="border-b last:border-0"
                        >
                            <td class="p-3 font-medium">{{ run.reference }}</td>
                            <td class="p-3">
                                {{ run.period_start }} → {{ run.period_end }}
                            </td>
                            <td class="p-3">{{ run.generated_at }}</td>
                            <td class="p-3">{{ run.generated_by ?? '—' }}</td>
                            <td class="p-3">{{ run.kpi_count }}</td>
                            <td class="p-3">{{ run.revision }}</td>
                            <td class="p-3 font-mono text-xs">
                                {{ run.checksum.slice(0, 12) }}…
                            </td>
                        </tr>
                        <tr v-if="runs.length === 0">
                            <td colspan="7" class="p-6 text-muted-foreground">
                                No snapshot saved yet. A preview never stores
                                data; use “Save run” to keep the evidence.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>

