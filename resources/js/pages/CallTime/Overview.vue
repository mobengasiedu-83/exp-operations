<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { AudioLines, Clock, Headphones, PhoneCall, PhoneIncoming, Stethoscope } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import CallTimeNav from '@/components/CallTimeNav.vue';
import StatTile from '@/components/exp/StatTile.vue';
import { formatDay, formatTime } from '@/lib/call-time';
import { overview as overviewPage } from '@/routes/call-time';
import type { CallTimeOverview } from '@/types/call-time';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    overview: CallTimeOverview;
    filters: { days: number };
    windows: number[];
}>();

const days = ref(props.filters.days);

function search(): void {
    router.get(
        overviewPage.url(),
        { days: days.value },
        { preserveState: true, preserveScroll: true },
    );
}

const headline = computed(() => [
    {
        label: 'Inbound calls',
        value: props.overview.calls.total,
        hint: `${props.overview.calls.answered} answered · ${props.overview.calls.abandoned} no agent answer`,
        tone: 'emerald' as const,
        icon: PhoneCall,
    },
    {
        label: 'Total call time',
        value: formatTime(props.overview.totals.total_seconds),
        hint: `Journey start to last hangup · average ${formatTime(props.overview.averages.total_seconds)}`,
        tone: 'slate' as const,
        icon: Clock,
    },
    {
        label: 'Wait time',
        value: formatTime(props.overview.totals.wait_seconds),
        hint: `Queue and IVR · average ${formatTime(props.overview.averages.wait_seconds)}`,
        tone: 'amber' as const,
        icon: PhoneIncoming,
    },
    {
        label: 'Time by agent',
        value: formatTime(props.overview.totals.agent_seconds),
        hint: `Answer to first dial · average ${formatTime(props.overview.averages.agent_seconds)}`,
        tone: 'sky' as const,
        icon: Headphones,
    },
    {
        label: 'Time by dentist',
        value: formatTime(props.overview.totals.dentist_seconds),
        hint: `Connected mobile time · average ${formatTime(props.overview.averages.dentist_seconds)}`,
        tone: 'rose' as const,
        icon: Stethoscope,
    },
]);

const stages = computed(() => [
    { label: 'Hold while dialling', seconds: props.overview.totals.hold_seconds },
    { label: 'Dentist ringing', seconds: props.overview.totals.ringing_seconds },
    { label: 'Dial to answer', seconds: props.overview.totals.dial_to_answer_seconds },
    { label: 'Consultation bridge', seconds: props.overview.totals.consultation_seconds },
    { label: 'Private handover', seconds: props.overview.totals.handover_seconds },
    { label: 'Average ringing', seconds: props.overview.averages.ringing_seconds },
]);

const outcomes = computed(() => [
    { label: 'Answered by an agent', value: props.overview.calls.answered },
    { label: 'No agent answer', value: props.overview.calls.abandoned },
    { label: 'Reached a dentist', value: props.overview.calls.transferred },
    { label: 'Still active', value: props.overview.calls.active },
    { label: 'Completed', value: props.overview.calls.completed },
]);
</script>

<template>
    <div>
        <Head title="Call time" />
        <div class="space-y-6 p-6">
            <Heading
                title="Call time"
                description="How long callers wait, how long agents handle each journey and how much connected time dentists receive. Every figure is measured from retained PBX evidence; a missing measurement stays blank rather than estimated."
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
                <p class="text-xs text-slate-500">
                    Journeys are counted by their start time in this rolling window.
                </p>
            </form>

            <section
                class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5"
                aria-label="Call time totals"
            >
                <StatTile
                    v-for="(metric, index) in headline"
                    :key="metric.label"
                    :label="metric.label"
                    :value="metric.value"
                    :hint="metric.hint"
                    :icon="metric.icon"
                    :tone="metric.tone"
                    :delay="(index + 1) as 1 | 2 | 3 | 4 | 5"
                />
            </section>

            <section class="grid gap-4 lg:grid-cols-2">
                <article class="rounded-xl border bg-white p-4 shadow-sm dark:bg-slate-900">
                    <h2 class="font-semibold">Stage detail</h2>
                    <p class="mt-1 text-xs text-slate-500">
                        Bridge stages can overlap the connected dentist window while a call is
                        bridged, so these figures describe each stage rather than adding up to the
                        total.
                    </p>
                    <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div v-for="stage in stages" :key="stage.label" class="border-t pt-3">
                            <dt class="text-xs uppercase tracking-wide text-slate-500">
                                {{ stage.label }}
                            </dt>
                            <dd class="mt-1 text-lg font-semibold tabular-nums">
                                {{ formatTime(stage.seconds) }}
                            </dd>
                        </div>
                    </dl>
                </article>

                <article class="rounded-xl border bg-white p-4 shadow-sm dark:bg-slate-900">
                    <h2 class="font-semibold">Journey outcomes</h2>
                    <p class="mt-1 text-xs text-slate-500">
                        An answer is the caller reaching an agent. A dentist connection is a
                        network answer on a mobile leg and does not prove a human dentist accepted
                        the call.
                    </p>
                    <dl class="mt-4 space-y-3">
                        <div
                            v-for="outcome in outcomes"
                            :key="outcome.label"
                            class="flex items-center justify-between border-t pt-3 text-sm"
                        >
                            <dt class="text-slate-600 dark:text-slate-300">{{ outcome.label }}</dt>
                            <dd class="font-semibold tabular-nums">{{ outcome.value }}</dd>
                        </div>
                    </dl>
                    <p class="mt-4 flex items-center gap-2 text-xs text-slate-500">
                        <AudioLines class="size-3.5" />
                        {{ overview.samples.wait }} wait · {{ overview.samples.agent }} agent ·
                        {{ overview.samples.dentist }} dentist measurements
                    </p>
                </article>
            </section>

            <section
                class="overflow-x-auto rounded-xl border bg-white dark:bg-slate-900"
                aria-label="Daily call time"
            >
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-800">
                        <tr>
                            <th class="px-4 py-3">Day</th>
                            <th class="px-4 py-3">Journeys</th>
                            <th class="px-4 py-3">Answered</th>
                            <th class="px-4 py-3">Total time</th>
                            <th class="px-4 py-3">Wait time</th>
                            <th class="px-4 py-3">Agent time</th>
                            <th class="px-4 py-3">Dentist time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="day in overview.daily"
                            :key="day.date"
                            class="border-t border-slate-100 dark:border-slate-800"
                        >
                            <td class="px-4 py-3 font-medium">{{ formatDay(day.date) }}</td>
                            <td class="px-4 py-3 tabular-nums">{{ day.calls }}</td>
                            <td class="px-4 py-3 tabular-nums">{{ day.answered }}</td>
                            <td class="px-4 py-3 tabular-nums">
                                {{ formatTime(day.total_seconds) }}
                            </td>
                            <td class="px-4 py-3 tabular-nums">
                                {{ formatTime(day.wait_seconds) }}
                            </td>
                            <td class="px-4 py-3 tabular-nums">
                                {{ formatTime(day.agent_seconds) }}
                            </td>
                            <td class="px-4 py-3 tabular-nums">
                                {{ formatTime(day.dentist_seconds) }}
                            </td>
                        </tr>
                        <tr v-if="overview.daily.length === 0">
                            <td colspan="7" class="px-4 py-10 text-center text-slate-500">
                                No journeys started in this window yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </div>
</template>

