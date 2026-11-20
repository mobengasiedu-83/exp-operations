<script setup lang="ts">
import { ref } from "vue";
import { Head, router } from "@inertiajs/vue3";
import AppLayout from "@/layouts/AppLayout.vue";
import Heading from "@/components/Heading.vue";
import DentistNav from "@/components/DentistNav.vue";
import { formatClock, formatSeconds } from "@/lib/live-operations";
import { percent } from "@/lib/dentists";
import { performance as performancePage } from "@/routes/dentists";

defineOptions({ layout: AppLayout });

type PerformanceRow = {
    id: number;
    name: string;
    active: boolean;
    attempts: number;
    answered: number;
    failed: number;
    answer_rate: number | null;
    avg_dial_to_answer_seconds: number | null;
    avg_ringing_seconds: number | null;
    last_attempt_at: string | null;
};

const props = defineProps<{
    performance: {
        window_days: number;
        as_of: string;
        rows: PerformanceRow[];
        summary: {
            attempts: number;
            answered: number;
            failed: number;
            unmatched_attempts: number;
            answer_rate: number | null;
        };
    };
    filters: { days: number };
    canManage: boolean;
}>();

const days = ref(props.filters.days);

function search() {
    router.get(performancePage.url(), { days: days.value }, { preserveState: true });
}
</script>

<template>
    <div>
        <Head title="Dentist performance" />
        <div class="space-y-6 p-6">
            <Heading
                title="Dentist performance"
                description="Outcomes for mobile legs that match a directory number. These are observed EXP call attempts, not a measure of personal-mobile availability."
            />
            <DentistNav />

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <div
                    v-for="metric in [
                        { label: 'Attempts', value: String(performance.summary.attempts) },
                        { label: 'Answered', value: String(performance.summary.answered) },
                        { label: 'Failed', value: String(performance.summary.failed) },
                        { label: 'Answer rate', value: percent(performance.summary.answer_rate) },
                        { label: 'Unmatched attempts', value: String(performance.summary.unmatched_attempts) },
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
                    >Window (days)<input
                        v-model.number="days"
                        type="number"
                        min="1"
                        max="365"
                        class="w-28 rounded border bg-background p-2"
                /></label>
                <button type="submit" class="rounded bg-primary px-4 py-2 text-primary-foreground">Apply</button>
                <p class="text-xs text-slate-500">
                    Rolling {{ performance.window_days }} day window evaluated at {{ formatClock(performance.as_of) }}.
                </p>
            </form>

            <div class="overflow-x-auto rounded-xl border bg-white dark:bg-slate-900">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-800">
                        <tr>
                            <th class="px-4 py-3">Dentist</th>
                            <th class="px-4 py-3">Attempts</th>
                            <th class="px-4 py-3">Answered</th>
                            <th class="px-4 py-3">Failed</th>
                            <th class="px-4 py-3">Answer rate</th>
                            <th class="px-4 py-3">Avg dial to answer</th>
                            <th class="px-4 py-3">Avg ringing</th>
                            <th class="px-4 py-3">Last attempt</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in performance.rows" :key="row.id" class="border-t">
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ row.name }}</p>
                                <p v-if="!row.active" class="text-xs text-slate-500">Inactive</p>
                            </td>
                            <td class="px-4 py-3">{{ row.attempts }}</td>
                            <td class="px-4 py-3">{{ row.answered }}</td>
                            <td class="px-4 py-3">{{ row.failed }}</td>
                            <td class="px-4 py-3">{{ percent(row.answer_rate) }}</td>
                            <td class="px-4 py-3">{{ formatSeconds(row.avg_dial_to_answer_seconds) }}</td>
                            <td class="px-4 py-3">{{ formatSeconds(row.avg_ringing_seconds) }}</td>
                            <td class="px-4 py-3">{{ formatClock(row.last_attempt_at) }}</td>
                        </tr>
                        <tr v-if="performance.rows.length === 0">
                            <td colspan="8" class="px-4 py-10 text-center text-slate-500">
                                No dentists in the directory yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
