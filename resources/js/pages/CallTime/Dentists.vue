<script setup lang="ts">
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import CallTimeNav from '@/components/CallTimeNav.vue';
import { formatTime } from '@/lib/call-time';
import { percent } from '@/lib/dentists';
import { formatClock } from '@/lib/live-operations';
import { dentists as dentistsPage } from '@/routes/call-time';
import type { CallTimeDentistRow, CallTimeDentists } from '@/types/call-time';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    dentists: CallTimeDentists;
    filters: { days: number };
    windows: number[];
}>();

const days = ref(props.filters.days);

function search(): void {
    router.get(
        dentistsPage.url(),
        { days: days.value },
        { preserveState: true, preserveScroll: true },
    );
}

function label(row: CallTimeDentistRow): string {
    return row.name ?? 'Unattributed mobile legs';
}
</script>

<template>
    <div>
        <Head title="Call time by dentist" />
        <div class="space-y-6 p-6">
            <Heading
                title="Call time by dentist"
                description="Connected time for every answered mobile leg, attributed to the dentist whose directory number the transfer tracker matched. A network answer does not prove a human dentist accepted the call."
            />
            <CallTimeNav />

            <form class="flex flex-wrap items-end gap-4" @submit.prevent="search">
                <label class="grid gap-2 text-sm">
                    Window
                    <select v-model.number="days" class="rounded border bg-background p-2">
                        <option v-for="option in windows" :key="option" :value="option">
                            {{ option === 1 ? 'Today' : `Last ${option} days` }}
                        </option>
                    </select>
                </label>
                <button
                    type="submit"
                    class="rounded bg-primary px-4 py-2 text-primary-foreground"
                >
                    Apply
                </button>
            </form>

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <div
                    v-for="metric in [
                        { label: 'Dentist time', value: formatTime(dentists.summary.connected_seconds) },
                        { label: 'Attempts', value: String(dentists.summary.attempts) },
                        { label: 'Answered', value: String(dentists.summary.answered) },
                        { label: 'Answer rate', value: percent(dentists.summary.answer_rate) },
                        { label: 'Matched', value: percent(dentists.summary.match_rate) },
                    ]"
                    :key="metric.label"
                    class="rounded-xl border bg-white p-4 shadow-sm dark:bg-slate-900"
                >
                    <p class="text-xs uppercase tracking-wide text-slate-500">
                        {{ metric.label }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums">{{ metric.value }}</p>
                </div>
            </section>

            <p
                v-if="dentists.summary.unmatched_attempts > 0"
                role="status"
                class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100"
            >
                {{ dentists.summary.unmatched_attempts }} attempt(s) could not be matched to a
                directory number, so their connected time is shown as unattributed. Check the
                dentist directory numbers against the number the PBX actually dialled.
            </p>

            <div class="overflow-x-auto rounded-xl border bg-white dark:bg-slate-900">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-800">
                        <tr>
                            <th class="px-4 py-3">Dentist</th>
                            <th class="px-4 py-3">Attempts</th>
                            <th class="px-4 py-3">Answered</th>
                            <th class="px-4 py-3">Answer rate</th>
                            <th class="px-4 py-3">Connected time</th>
                            <th class="px-4 py-3">Average</th>
                            <th class="px-4 py-3">Avg dial to answer</th>
                            <th class="px-4 py-3">Avg ringing</th>
                            <th class="px-4 py-3">Consultation</th>
                            <th class="px-4 py-3">Last attempt</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in dentists.rows"
                            :key="row.key"
                            class="border-t border-slate-100 dark:border-slate-800"
                        >
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ label(row) }}</p>
                                <p v-if="row.matcher === 'unmatched'" class="text-xs text-slate-500">
                                    Directory number not matched
                                </p>
                            </td>
                            <td class="px-4 py-3 tabular-nums">{{ row.attempts }}</td>
                            <td class="px-4 py-3 tabular-nums">{{ row.answered }}</td>
                            <td class="px-4 py-3 tabular-nums">{{ percent(row.answer_rate) }}</td>
                            <td class="px-4 py-3 tabular-nums">
                                {{ formatTime(row.connected_seconds) }}
                            </td>
                            <td class="px-4 py-3 tabular-nums">
                                {{ formatTime(row.avg_connected_seconds) }}
                            </td>
                            <td class="px-4 py-3 tabular-nums">
                                {{ formatTime(row.avg_dial_to_answer_seconds) }}
                            </td>
                            <td class="px-4 py-3 tabular-nums">
                                {{ formatTime(row.avg_ringing_seconds) }}
                            </td>
                            <td class="px-4 py-3 tabular-nums">
                                {{ formatTime(row.consultation_seconds) }}
                            </td>
                            <td class="px-4 py-3 text-xs">
                                {{ formatClock(row.last_attempt_at) }}
                            </td>
                        </tr>
                        <tr v-if="dentists.rows.length === 0">
                            <td colspan="10" class="px-4 py-10 text-center text-slate-500">
                                No dentist attempts were dialled in this window yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>

