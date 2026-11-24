<script setup lang="ts">
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import CallTimeNav from '@/components/CallTimeNav.vue';
import { formatTime } from '@/lib/call-time';
import { percent } from '@/lib/dentists';
import { formatClock } from '@/lib/live-operations';
import { agents as agentsPage } from '@/routes/call-time';
import type { CallTimeAgents, CallTimeAgentRow } from '@/types/call-time';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    agents: CallTimeAgents;
    filters: { days: number };
    windows: number[];
}>();

const days = ref(props.filters.days);

function search(): void {
    router.get(
        agentsPage.url(),
        { days: days.value },
        { preserveState: true, preserveScroll: true },
    );
}

function label(row: CallTimeAgentRow): string {
    return row.extension === null ? 'Unattributed' : `Extension ${row.extension}`;
}

function answerRate(row: CallTimeAgentRow): string {
    return percent(row.calls === 0 ? null : row.answered / row.calls);
}
</script>


<template>
    <div>
        <Head title="Call time by agent" />
        <div class="space-y-6 p-6">
            <Heading
                title="Call time by agent"
                description="Agent handling time measured from the moment the caller reached an agent to the first dentist dial — or to the end of the journey when no transfer was attempted. Legs are attributed by the PBX extension on the agent channel."
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

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div
                    v-for="metric in [
                        { label: 'Agent time', value: formatTime(agents.summary.talk_seconds) },
                        { label: 'Journeys', value: String(agents.summary.calls) },
                        { label: 'Answered', value: String(agents.summary.answered) },
                        { label: 'Unattributed', value: String(agents.summary.unattributed_calls) },
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

            <div class="overflow-x-auto rounded-xl border bg-white dark:bg-slate-900">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-800">
                        <tr>
                            <th class="px-4 py-3">Agent</th>
                            <th class="px-4 py-3">Journeys</th>
                            <th class="px-4 py-3">Answered</th>
                            <th class="px-4 py-3">Answer rate</th>
                            <th class="px-4 py-3">Agent time</th>
                            <th class="px-4 py-3">Average</th>
                            <th class="px-4 py-3">Longest</th>
                            <th class="px-4 py-3">Average wait</th>
                            <th class="px-4 py-3">Dentist time</th>
                            <th class="px-4 py-3">Last call</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in agents.rows"
                            :key="row.key"
                            class="border-t border-slate-100 dark:border-slate-800"
                        >
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ label(row) }}</p>
                                <p v-if="row.extension === null" class="text-xs text-slate-500">
                                    No agent channel was retained for these journeys
                                </p>
                            </td>
                            <td class="px-4 py-3 tabular-nums">{{ row.calls }}</td>
                            <td class="px-4 py-3 tabular-nums">{{ row.answered }}</td>
                            <td class="px-4 py-3 tabular-nums">{{ answerRate(row) }}</td>
                            <td class="px-4 py-3 tabular-nums">
                                {{ formatTime(row.talk_seconds) }}
                            </td>
                            <td class="px-4 py-3 tabular-nums">
                                {{ formatTime(row.avg_talk_seconds) }}
                            </td>
                            <td class="px-4 py-3 tabular-nums">
                                {{ formatTime(row.longest_talk_seconds) }}
                            </td>
                            <td class="px-4 py-3 tabular-nums">
                                {{ formatTime(row.avg_wait_seconds) }}
                            </td>
                            <td class="px-4 py-3 tabular-nums">
                                {{ formatTime(row.dentist_seconds) }}
                            </td>
                            <td class="px-4 py-3 text-xs">{{ formatClock(row.last_call_at) }}</td>
                        </tr>
                        <tr v-if="agents.rows.length === 0">
                            <td colspan="10" class="px-4 py-10 text-center text-slate-500">
                                No journeys started in this window yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="text-xs text-slate-500">
                {{ agents.summary.agents }} identified extension(s). An extension is taken from the
                agent channel the PBX named, for example 204 in
                <code class="font-mono">PJSIP/204-0000074a</code>.
            </p>
        </div>
    </div>
</template>
