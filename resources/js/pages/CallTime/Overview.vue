<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    AudioLines,
    CalendarDays,
    CheckCheck,
    CircleCheck,
    CircleDashed,
    Clock,
    Headphones,
    Layers,
    PhoneCall,
    PhoneIncoming,
    Stethoscope,
} from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import CallTimeFilters from '@/components/CallTimeFilters.vue';
import CallTimeNav from '@/components/CallTimeNav.vue';
import CallTimeArtwork from '@/components/exp/CallTimeArtwork.vue';
import DailyTrendChart from '@/components/exp/DailyTrendChart.vue';
import OutcomeRing from '@/components/exp/OutcomeRing.vue';
import PageHero from '@/components/exp/PageHero.vue';
import StatTile from '@/components/exp/StatTile.vue';
import { formatDay, formatTime, share, windowQuery } from '@/lib/call-time';
import {
    agents as agentsPage,
    dentists as dentistsPage,
    overview as overviewPage,
} from '@/routes/call-time';
import type {
    CallTimeFilters as CallTimeFiltersState,
    CallTimeOverview,
} from '@/types/call-time';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    overview: CallTimeOverview;
    filters: CallTimeFiltersState;
    windows: number[];
}>();

const headline = computed(() => [
    {
        label: 'Inbound calls',
        value: props.overview.calls.total,
        hint: `${props.overview.calls.answered} answered · ${props.overview.calls.abandoned} no agent answer`,
        icon: PhoneCall,
    },
    {
        label: 'Total call time',
        value: formatTime(props.overview.totals.total_seconds),
        hint: `Journey start to last hangup · average ${formatTime(props.overview.averages.total_seconds)}`,
        icon: Clock,
    },
    {
        label: 'Wait time',
        value: formatTime(props.overview.totals.wait_seconds),
        hint: `Queue and IVR · average ${formatTime(props.overview.averages.wait_seconds)}`,
        icon: PhoneIncoming,
    },
    {
        label: 'Time by agent',
        value: formatTime(props.overview.totals.agent_seconds),
        hint: `Answer to first dial · average ${formatTime(props.overview.averages.agent_seconds)}`,
        icon: Headphones,
    },
    {
        label: 'Time by dentist',
        value: formatTime(props.overview.totals.dentist_seconds),
        hint: `Connected mobile time · average ${formatTime(props.overview.averages.dentist_seconds)}`,
        icon: Stethoscope,
    },
]);

const stages = computed(() => [
    {
        label: 'Hold while dialling',
        seconds: props.overview.totals.hold_seconds,
        tone: 'from-amber-400 to-amber-500',
    },
    {
        label: 'Dentist ringing',
        seconds: props.overview.totals.ringing_seconds,
        tone: 'from-sky-400 to-sky-500',
    },
    {
        label: 'Dial to answer',
        seconds: props.overview.totals.dial_to_answer_seconds,
        tone: 'from-teal-400 to-emerald-500',
    },
    {
        label: 'Consultation bridge',
        seconds: props.overview.totals.consultation_seconds,
        tone: 'from-emerald-400 to-emerald-600',
    },
    {
        label: 'Private handover',
        seconds: props.overview.totals.handover_seconds,
        tone: 'from-rose-400 to-rose-500',
    },
]);

const averages = computed(() => [
    { label: 'Avg journey', seconds: props.overview.averages.total_seconds },
    { label: 'Avg wait', seconds: props.overview.averages.wait_seconds },
    { label: 'Avg ringing', seconds: props.overview.averages.ringing_seconds },
]);

const outcomeCounters = computed(() => [
    {
        label: 'Reached a dentist',
        value: props.overview.calls.transferred,
        icon: CircleCheck,
    },
    {
        label: 'Still active',
        value: props.overview.calls.active,
        icon: CircleDashed,
    },
    {
        label: 'Completed',
        value: props.overview.calls.completed,
        icon: CheckCheck,
    },
]);
</script>

<template>
    <Head title="Call time" />

    <div class="min-h-full bg-slate-50/80 p-4 sm:p-6 lg:p-8 dark:bg-slate-950">
        <div class="mx-auto max-w-7xl space-y-6">
            <PageHero
                eyebrow="Call time · measured from PBX evidence"
                title="Call time"
                description="How long callers wait, how long agents handle each journey and how much connected time dentists receive. Every figure is measured from retained PBX evidence; a missing measurement stays blank rather than estimated."
            >
                <template #artwork>
                    <CallTimeArtwork />
                </template>
                <template #actions>
                    <Link
                        :href="agentsPage.url({ query: windowQuery(filters) })"
                        class="inline-flex items-center gap-2 rounded-xl bg-white/15 px-4 py-2.5 text-sm font-semibold text-white ring-1 ring-white/30 backdrop-blur transition hover:bg-white/25"
                    >
                        <Headphones class="size-4" aria-hidden="true" />
                        By agent
                    </Link>
                    <Link
                        :href="
                            dentistsPage.url({ query: windowQuery(filters) })
                        "
                        class="inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-emerald-800 shadow-sm transition hover:bg-emerald-50"
                    >
                        <Stethoscope class="size-4" aria-hidden="true" />
                        By dentist
                        <ArrowRight class="size-4" aria-hidden="true" />
                    </Link>
                </template>
                <template #stats>
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                        <StatTile
                            v-for="(metric, index) in headline"
                            :key="metric.label"
                            :label="metric.label"
                            :value="metric.value"
                            :hint="metric.hint"
                            :icon="metric.icon"
                            tone="onDark"
                            :delay="(index + 1) as 1 | 2 | 3 | 4 | 5"
                        />
                    </div>
                </template>
            </PageHero>

            <CallTimeNav />

            <CallTimeFilters
                :action="overviewPage.url()"
                :filters="filters"
                :windows="windows"
                :measured="overview.window"
            />

            <section
                class="grid gap-6 lg:grid-cols-2"
                aria-label="Call time breakdown"
            >
                <article
                    class="exp-rise exp-rise-3 overflow-hidden rounded-3xl border bg-card shadow-sm"
                >
                    <div
                        class="flex items-start justify-between gap-3 border-b bg-muted/40 px-5 py-3.5"
                    >
                        <div class="flex items-start gap-3">
                            <span
                                class="mt-0.5 grid size-9 place-items-center rounded-xl bg-amber-500/10 text-amber-600 dark:text-amber-300"
                                aria-hidden="true"
                            >
                                <Layers class="size-4" />
                            </span>
                            <div>
                                <h2 class="text-sm font-semibold">
                                    Time composition
                                </h2>
                                <p class="text-xs text-muted-foreground">
                                    Share of
                                    {{
                                        formatTime(
                                            overview.totals.total_seconds,
                                        )
                                    }}
                                    measured call time
                                </p>
                            </div>
                        </div>
                        <span
                            class="exp-chip bg-amber-500/10 text-amber-700 dark:text-amber-300"
                        >
                            stage seconds
                        </span>
                    </div>

                    <div class="space-y-4 p-5">
                        <div
                            v-for="(stage, index) in stages"
                            :key="stage.label"
                            class="group"
                        >
                            <div
                                class="flex items-center justify-between gap-3 text-sm"
                            >
                                <span
                                    class="text-muted-foreground transition group-hover:text-foreground"
                                >
                                    {{ stage.label }}
                                </span>
                                <span class="font-semibold tabular-nums">
                                    {{ formatTime(stage.seconds) }}
                                </span>
                            </div>
                            <div
                                class="mt-1.5 h-2 overflow-hidden rounded-full bg-muted"
                            >
                                <div
                                    class="exp-grow-x h-full rounded-full bg-gradient-to-r transition-[width] duration-500"
                                    :class="stage.tone"
                                    :style="{
                                        width: `${share(stage.seconds, overview.totals.total_seconds)}%`,
                                        animationDelay: `${index * 70}ms`,
                                    }"
                                />
                            </div>
                            <p class="mt-1 text-[11px] text-muted-foreground">
                                {{
                                    share(
                                        stage.seconds,
                                        overview.totals.total_seconds,
                                    )
                                }}% of total call time
                            </p>
                        </div>

                        <dl class="grid grid-cols-3 gap-3 border-t pt-4">
                            <div
                                v-for="average in averages"
                                :key="average.label"
                            >
                                <dt
                                    class="text-[10px] font-semibold tracking-wide text-muted-foreground uppercase"
                                >
                                    {{ average.label }}
                                </dt>
                                <dd
                                    class="mt-0.5 text-sm font-semibold tabular-nums"
                                >
                                    {{ formatTime(average.seconds) }}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <p
                        class="border-t bg-muted/30 px-5 py-3 text-xs text-muted-foreground"
                    >
                        Bridge stages can overlap the connected dentist window
                        while a call is bridged, so these figures describe each
                        stage rather than adding up to the total.
                    </p>
                </article>

                <article
                    class="exp-rise exp-rise-3 overflow-hidden rounded-3xl border bg-card shadow-sm"
                >
                    <div
                        class="flex items-start justify-between gap-3 border-b bg-muted/40 px-5 py-3.5"
                    >
                        <div class="flex items-start gap-3">
                            <span
                                class="mt-0.5 grid size-9 place-items-center rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-300"
                                aria-hidden="true"
                            >
                                <PhoneCall class="size-4" />
                            </span>
                            <div>
                                <h2 class="text-sm font-semibold">
                                    Journey outcomes
                                </h2>
                                <p class="text-xs text-muted-foreground">
                                    An answer is the caller reaching an agent
                                </p>
                            </div>
                        </div>
                        <span
                            class="exp-chip bg-emerald-500/10 text-emerald-700 dark:text-emerald-300"
                        >
                            {{
                                overview.calls.answered +
                                overview.calls.abandoned
                            }}
                            resolved
                        </span>
                    </div>

                    <div class="p-5">
                        <OutcomeRing
                            :answered="overview.calls.answered"
                            :abandoned="overview.calls.abandoned"
                            :total="overview.calls.total"
                            :counters="outcomeCounters"
                        />

                        <p
                            class="mt-4 flex items-start gap-2 rounded-xl bg-muted/50 px-3 py-2 text-xs text-muted-foreground"
                        >
                            <AudioLines
                                class="mt-0.5 size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            <span>
                                A dentist connection is a network answer on a
                                mobile leg and does not prove a human dentist
                                accepted the call.
                                {{ overview.samples.wait }} wait ·
                                {{ overview.samples.agent }} agent ·
                                {{ overview.samples.dentist }} dentist
                                measurements.
                            </span>
                        </p>
                    </div>
                </article>
            </section>

            <DailyTrendChart :days="overview.daily" />

            <section
                class="exp-rise exp-rise-4 overflow-hidden rounded-3xl border bg-card shadow-sm"
                aria-label="Daily call time"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b bg-muted/40 px-5 py-3.5"
                >
                    <div class="flex items-center gap-3">
                        <span
                            class="grid size-9 place-items-center rounded-xl bg-sky-500/10 text-sky-600 dark:text-sky-300"
                            aria-hidden="true"
                        >
                            <CalendarDays class="size-4" />
                        </span>
                        <div>
                            <h2 class="text-sm font-semibold">Daily detail</h2>
                            <p class="text-xs text-muted-foreground">
                                Every day that recorded a journey in this window
                            </p>
                        </div>
                    </div>
                    <span class="exp-chip bg-muted text-muted-foreground">
                        {{ overview.daily.length }} day(s)
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead
                            class="border-b bg-muted/30 text-[11px] tracking-wide text-muted-foreground uppercase"
                        >
                            <tr>
                                <th scope="col" class="px-5 py-3 font-semibold">
                                    Day
                                </th>
                                <th
                                    scope="col"
                                    class="px-5 py-3 text-right font-semibold"
                                >
                                    Journeys
                                </th>
                                <th
                                    scope="col"
                                    class="px-5 py-3 text-right font-semibold"
                                >
                                    Answered
                                </th>
                                <th
                                    scope="col"
                                    class="px-5 py-3 text-right font-semibold"
                                >
                                    Total time
                                </th>
                                <th
                                    scope="col"
                                    class="px-5 py-3 text-right font-semibold"
                                >
                                    Wait time
                                </th>
                                <th
                                    scope="col"
                                    class="px-5 py-3 text-right font-semibold"
                                >
                                    Agent time
                                </th>
                                <th
                                    scope="col"
                                    class="px-5 py-3 text-right font-semibold"
                                >
                                    Dentist time
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="day in overview.daily"
                                :key="day.date"
                                class="border-t transition-colors first:border-t-0 hover:bg-emerald-500/[0.04]"
                            >
                                <th
                                    scope="row"
                                    class="px-5 py-3 font-medium whitespace-nowrap"
                                >
                                    {{ formatDay(day.date) }}
                                </th>
                                <td class="px-5 py-3 text-right tabular-nums">
                                    {{ day.calls }}
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums">
                                    {{ day.answered }}
                                </td>
                                <td
                                    class="px-5 py-3 text-right font-medium tabular-nums"
                                >
                                    {{ formatTime(day.total_seconds) }}
                                </td>
                                <td
                                    class="px-5 py-3 text-right text-amber-700 tabular-nums dark:text-amber-300"
                                >
                                    {{ formatTime(day.wait_seconds) }}
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums">
                                    {{ formatTime(day.agent_seconds) }}
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums">
                                    {{ formatTime(day.dentist_seconds) }}
                                </td>
                            </tr>
                            <tr v-if="overview.daily.length === 0">
                                <td colspan="7">
                                    <div
                                        class="flex flex-col items-center gap-2 py-12 text-center"
                                        role="status"
                                    >
                                        <span
                                            class="grid size-12 place-items-center rounded-2xl bg-muted text-muted-foreground"
                                            aria-hidden="true"
                                        >
                                            <CalendarDays class="size-5" />
                                        </span>
                                        <p class="text-sm font-medium">
                                            No journeys started in this window
                                            yet
                                        </p>
                                        <p
                                            class="max-w-sm text-xs text-muted-foreground"
                                        >
                                            Widen the window or pick a day you
                                            know had calls. A blank measurement
                                            is never replaced with an estimate.
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</template>
