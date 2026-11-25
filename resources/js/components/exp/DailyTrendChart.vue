<script setup lang="ts">
import { computed, ref } from 'vue';
import { BarChart3, Timer } from '@lucide/vue';
import { formatDay, formatTime } from '@/lib/call-time';
import type { CallTimeOverview } from '@/types/call-time';

/**
 * The per-day trend behind the totals. A manager reads this to answer "when in
 * this window was the queue slow", so the metric is switchable instead of
 * showing one arbitrary measure. Exact figures stay in the daily detail table
 * below, which keeps the chart itself decorative.
 */
const props = defineProps<{ days: CallTimeOverview['daily'] }>();

type Metric = 'total_seconds' | 'calls' | 'wait_seconds';

const metrics: {
    key: Metric;
    label: string;
    unit: 'time' | 'count';
    bar: string;
    dot: string;
}[] = [
    {
        key: 'total_seconds',
        label: 'Call time',
        unit: 'time',
        bar: 'from-emerald-500/25 to-emerald-500',
        dot: 'bg-emerald-500',
    },
    {
        key: 'calls',
        label: 'Journeys',
        unit: 'count',
        bar: 'from-sky-500/25 to-sky-500',
        dot: 'bg-sky-500',
    },
    {
        key: 'wait_seconds',
        label: 'Wait time',
        unit: 'time',
        bar: 'from-amber-500/25 to-amber-500',
        dot: 'bg-amber-500',
    },
];

const metric = ref<Metric>('total_seconds');
const active = computed(
    () => metrics.find((entry) => entry.key === metric.value) ?? metrics[0],
);

const peak = computed(() =>
    Math.max(1, ...props.days.map((day) => Number(day[metric.value]))),
);

const total = computed(() =>
    props.days.reduce((sum, day) => sum + Number(day[metric.value]), 0),
);

/** Label every few bars, so a 90-day window stays readable. */
const labelStep = computed(() =>
    Math.max(1, Math.ceil(props.days.length / 12)),
);

function height(day: CallTimeOverview['daily'][number]): string {
    const value = Number(day[metric.value]);

    return `${Math.max(value === 0 ? 2 : 6, (value / peak.value) * 100)}%`;
}

function display(day: CallTimeOverview['daily'][number]): string {
    return active.value.unit === 'count'
        ? `${day.calls} journey${day.calls === 1 ? '' : 's'}`
        : formatTime(Number(day[metric.value]));
}

function isPeak(day: CallTimeOverview['daily'][number]): boolean {
    return Number(day[metric.value]) === peak.value && peak.value > 1;
}

function dayNumber(date: string): string {
    return String(Number(date.slice(8, 10)));
}
</script>

<template>
    <section
        class="exp-rise exp-rise-3 overflow-hidden rounded-3xl border bg-card shadow-sm"
        aria-labelledby="call-time-trend-heading"
    >
        <div
            class="flex flex-wrap items-center justify-between gap-3 border-b bg-muted/40 px-5 py-3.5"
        >
            <div class="flex items-center gap-3">
                <span
                    class="grid size-9 place-items-center rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-300"
                    aria-hidden="true"
                >
                    <BarChart3 class="size-4" />
                </span>
                <div>
                    <h2
                        id="call-time-trend-heading"
                        class="text-sm font-semibold"
                    >
                        Daily rhythm
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        {{ days.length }} day(s) with journeys ·
                        {{
                            active.unit === 'count'
                                ? `${total} total`
                                : `${formatTime(total)} measured`
                        }}
                    </p>
                </div>
            </div>

            <div
                class="inline-flex items-center gap-1 rounded-xl border bg-background p-1"
                role="group"
                aria-label="Chart metric"
            >
                <button
                    v-for="entry in metrics"
                    :key="entry.key"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold transition duration-200"
                    :class="
                        metric === entry.key
                            ? 'bg-slate-900 text-white shadow-sm dark:bg-slate-100 dark:text-slate-900'
                            : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                    "
                    :aria-pressed="metric === entry.key"
                    @click="metric = entry.key"
                >
                    <span
                        class="size-1.5 rounded-full"
                        :class="entry.dot"
                        aria-hidden="true"
                    />
                    {{ entry.label }}
                </button>
            </div>
        </div>

        <div class="p-5">
            <div v-if="days.length > 0">
                <p class="sr-only">
                    Daily {{ active.label.toLowerCase() }} per day with
                    journeys. Exact values are listed in the daily detail table
                    below.
                </p>
                <div class="flex h-44 items-end gap-[3px]" aria-hidden="true">
                    <div
                        v-for="day in days"
                        :key="day.date"
                        class="group relative flex h-full flex-1 items-end"
                    >
                        <div
                            class="w-full rounded-t-md bg-gradient-to-t transition-[height] duration-500 ease-out group-hover:brightness-110"
                            :class="[
                                active.bar,
                                isPeak(day)
                                    ? 'ring-1 ring-white/40 ring-inset'
                                    : '',
                            ]"
                            :style="{ height: height(day) }"
                        />
                        <span
                            class="pointer-events-none absolute -top-1 left-1/2 z-10 -translate-x-1/2 -translate-y-full rounded-lg bg-slate-900 px-2 py-1 text-[10px] font-semibold whitespace-nowrap text-white opacity-0 shadow-lg transition duration-150 group-hover:opacity-100 dark:bg-slate-100 dark:text-slate-900"
                        >
                            {{ formatDay(day.date) }} · {{ display(day) }}
                        </span>
                    </div>
                </div>

                <div class="mt-2 flex gap-[3px]" aria-hidden="true">
                    <span
                        v-for="(day, index) in days"
                        :key="day.date"
                        class="flex-1 text-center text-[10px] text-muted-foreground tabular-nums"
                    >
                        {{ index % labelStep === 0 ? dayNumber(day.date) : '' }}
                    </span>
                </div>

                <p
                    class="mt-3 flex items-center gap-1.5 text-xs text-muted-foreground"
                >
                    <Timer class="size-3.5" aria-hidden="true" />
                    Hover a bar for the day's figure. Peak
                    {{ active.label.toLowerCase() }}:
                    {{
                        active.unit === 'count'
                            ? `${peak} journeys`
                            : formatTime(peak)
                    }}.
                </p>
            </div>

            <div
                v-else
                class="flex flex-col items-center gap-2 py-10 text-center"
                role="status"
            >
                <span
                    class="grid size-12 place-items-center rounded-2xl bg-muted text-muted-foreground"
                    aria-hidden="true"
                >
                    <BarChart3 class="size-5" />
                </span>
                <p class="text-sm font-medium">
                    Nothing to chart in this window
                </p>
                <p class="max-w-sm text-xs text-muted-foreground">
                    No journeys started in this window, so there is no daily
                    rhythm to show.
                </p>
            </div>
        </div>
    </section>
</template>
