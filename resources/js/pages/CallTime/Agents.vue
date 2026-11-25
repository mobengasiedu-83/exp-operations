<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import {
    ChevronDown,
    CircleHelp,
    Inbox,
    LayoutDashboard,
    PhoneCall,
    PhoneIncoming,
    Stethoscope,
    Timer,
} from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import CallTimeFilters from '@/components/CallTimeFilters.vue';
import CallTimeNav from '@/components/CallTimeNav.vue';
import PageHero from '@/components/exp/PageHero.vue';
import StatTile from '@/components/exp/StatTile.vue';
import { formatTime, share, windowQuery } from '@/lib/call-time';
import { percent } from '@/lib/dentists';
import { formatClock } from '@/lib/live-operations';
import {
    agents as agentsPage,
    dentists as dentistsPage,
    overview as overviewPage,
} from '@/routes/call-time';
import type {
    CallTimeAgentRow,
    CallTimeAgents,
    CallTimeFilters as CallTimeFiltersState,
} from '@/types/call-time';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    agents: CallTimeAgents;
    filters: CallTimeFiltersState;
    windows: number[];
}>();

function label(row: CallTimeAgentRow): string {
    return row.extension === null
        ? 'Unattributed'
        : `Extension ${row.extension}`;
}

function answerRate(row: CallTimeAgentRow): string {
    return percent(row.calls === 0 ? null : row.answered / row.calls);
}

/** Width of the answer-rate bar, kept at 0 for rows without journeys. */
function rateWidth(row: CallTimeAgentRow): number {
    return row.calls === 0 ? 0 : Math.round((row.answered / row.calls) * 100);
}

type SortKey =
    | 'extension'
    | 'calls'
    | 'answered'
    | 'rate'
    | 'talk'
    | 'average'
    | 'longest'
    | 'wait'
    | 'dentist'
    | 'last';

const columns: { key: SortKey; label: string; numeric?: boolean }[] = [
    { key: 'extension', label: 'Agent' },
    { key: 'calls', label: 'Journeys', numeric: true },
    { key: 'answered', label: 'Answered', numeric: true },
    { key: 'rate', label: 'Answer rate', numeric: true },
    { key: 'talk', label: 'Agent time', numeric: true },
    { key: 'average', label: 'Average', numeric: true },
    { key: 'longest', label: 'Longest', numeric: true },
    { key: 'wait', label: 'Average wait', numeric: true },
    { key: 'dentist', label: 'Dentist time', numeric: true },
    { key: 'last', label: 'Last call', numeric: true },
];

const sorters: Record<
    SortKey,
    (row: CallTimeAgentRow) => number | string | null
> = {
    extension: (row) => row.extension,
    calls: (row) => row.calls,
    answered: (row) => row.answered,
    rate: (row) => (row.calls === 0 ? null : row.answered / row.calls),
    talk: (row) => row.talk_seconds,
    average: (row) => row.avg_talk_seconds,
    longest: (row) => row.longest_talk_seconds,
    wait: (row) => row.avg_wait_seconds,
    dentist: (row) => row.dentist_seconds,
    last: (row) => row.last_call_at,
};

const sort = ref<{ key: SortKey; dir: 'asc' | 'desc' }>({
    key: 'talk',
    dir: 'desc',
});

/** Rows keep a stable, server-independent order until a header is clicked. */
const rows = computed(() => {
    const direction = sort.value.dir === 'asc' ? 1 : -1;
    const read = sorters[sort.value.key];

    return [...props.agents.rows].sort((left, right) => {
        const a = read(left);
        const b = read(right);

        if (a === null) {
            return 1;
        }

        if (b === null) {
            return -1;
        }

        if (typeof a === 'number' && typeof b === 'number') {
            return (a - b) * direction;
        }

        return String(a).localeCompare(String(b)) * direction;
    });
});

const sortedLabel = computed(
    () => columns.find((column) => column.key === sort.value.key)?.label ?? '',
);

function toggle(key: SortKey): void {
    sort.value =
        sort.value.key === key
            ? { key, dir: sort.value.dir === 'desc' ? 'asc' : 'desc' }
            : { key, dir: key === 'extension' ? 'asc' : 'desc' };
}

function arrowClass(key: SortKey): string {
    if (sort.value.key !== key) {
        return 'opacity-0 group-hover:opacity-40';
    }

    return sort.value.dir === 'asc' ? 'rotate-180' : '';
}

function ariaSort(key: SortKey): 'ascending' | 'descending' | 'none' {
    if (sort.value.key !== key) {
        return 'none';
    }

    return sort.value.dir === 'asc' ? 'ascending' : 'descending';
}
</script>

<template>
    <Head title="Call time by agent" />

    <div class="min-h-full bg-slate-50/80 p-4 sm:p-6 lg:p-8 dark:bg-slate-950">
        <div class="mx-auto max-w-7xl space-y-6">
            <PageHero
                eyebrow="Agent handling time"
                title="Call time by agent"
                description="Agent handling time measured from the moment the caller reached an agent to the first dentist dial — or to the end of the journey when no transfer was attempted. Legs are attributed by the PBX extension on the agent channel."
            >
                <template #actions>
                    <Link
                        :href="
                            overviewPage.url({ query: windowQuery(filters) })
                        "
                        class="inline-flex items-center gap-2 rounded-xl border border-white/15 bg-white/10 px-4 py-2.5 text-sm font-semibold text-white backdrop-blur transition hover:-translate-y-0.5 hover:bg-white/20 focus-visible:ring-2 focus-visible:ring-white/60 focus-visible:outline-none"
                    >
                        <LayoutDashboard class="size-4" aria-hidden="true" />
                        Call time overview
                    </Link>
                    <Link
                        :href="
                            dentistsPage.url({ query: windowQuery(filters) })
                        "
                        class="inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-emerald-800 shadow-lg shadow-emerald-950/20 transition hover:-translate-y-0.5 hover:bg-emerald-50 focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none"
                    >
                        <Stethoscope class="size-4" aria-hidden="true" />
                        Time by dentist
                    </Link>
                </template>

                <template #stats>
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <StatTile
                            label="Agent time"
                            :value="formatTime(agents.summary.talk_seconds)"
                            hint="Measured agent legs only"
                            :icon="Timer"
                            tone="onDark"
                            :delay="1"
                        />
                        <StatTile
                            label="Journeys"
                            :value="String(agents.summary.calls)"
                            hint="Handled in this window"
                            :icon="PhoneCall"
                            tone="onDark"
                            :delay="2"
                        />
                        <StatTile
                            label="Answered"
                            :value="String(agents.summary.answered)"
                            hint="Reached a dentist"
                            :icon="PhoneIncoming"
                            tone="onDark"
                            :delay="3"
                        />
                        <StatTile
                            label="Unattributed"
                            :value="String(agents.summary.unattributed_calls)"
                            hint="No agent extension retained"
                            :icon="CircleHelp"
                            tone="onDark"
                            :delay="4"
                        />
                    </div>
                </template>
            </PageHero>

            <CallTimeNav />

            <CallTimeFilters
                :action="agentsPage.url()"
                :filters="filters"
                :windows="windows"
                :measured="agents.window"
            />

            <section
                class="exp-rise exp-rise-3 overflow-hidden rounded-3xl border bg-card shadow-sm"
                aria-labelledby="agent-league-heading"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <span
                            class="grid size-9 shrink-0 place-items-center rounded-xl bg-emerald-500/10 text-emerald-700 ring-1 ring-emerald-500/20 dark:text-emerald-300"
                            aria-hidden="true"
                        >
                            <PhoneCall class="size-4" />
                        </span>
                        <div class="min-w-0">
                            <h2
                                id="agent-league-heading"
                                class="text-sm font-semibold"
                            >
                                Agent league table
                            </h2>
                            <p class="text-xs text-muted-foreground">
                                Sorted by {{ sortedLabel.toLowerCase() }} ·
                                every column sorts on click
                            </p>
                        </div>
                    </div>
                    <span
                        class="rounded-full bg-emerald-500/10 px-3 py-1 text-[11px] font-semibold text-emerald-700 ring-1 ring-emerald-500/20 dark:text-emerald-300"
                    >
                        {{ rows.length }} row{{ rows.length === 1 ? '' : 's' }}
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead
                            class="border-b bg-muted/40 text-[11px] tracking-wide text-muted-foreground uppercase"
                        >
                            <tr>
                                <th
                                    v-for="column in columns"
                                    :key="column.key"
                                    scope="col"
                                    class="px-5 py-3 font-semibold"
                                    :class="column.numeric ? 'text-right' : ''"
                                    :aria-sort="ariaSort(column.key)"
                                >
                                    <button
                                        type="button"
                                        class="group inline-flex items-center gap-1 rounded tracking-wide uppercase transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-emerald-500/50 focus-visible:outline-none"
                                        :class="
                                            column.numeric
                                                ? 'flex-row-reverse'
                                                : ''
                                        "
                                        @click="toggle(column.key)"
                                    >
                                        {{ column.label }}
                                        <ChevronDown
                                            class="size-3 transition-transform duration-200"
                                            :class="arrowClass(column.key)"
                                            aria-hidden="true"
                                        />
                                    </button>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(row, index) in rows"
                                :key="row.key"
                                class="exp-row border-t transition-colors first:border-t-0 hover:bg-emerald-500/[0.04]"
                                :style="{
                                    animationDelay: `${Math.min(index, 12) * 25}ms`,
                                }"
                            >
                                <th scope="row" class="px-5 py-3">
                                    <div class="flex items-center gap-3">
                                        <span
                                            class="grid size-9 shrink-0 place-items-center rounded-xl bg-emerald-500/10 text-[11px] font-bold text-emerald-700 ring-1 ring-emerald-500/20 dark:text-emerald-300"
                                            aria-hidden="true"
                                        >
                                            {{ row.extension ?? '—' }}
                                        </span>
                                        <div class="min-w-0">
                                            <p
                                                class="font-medium whitespace-nowrap"
                                            >
                                                {{ label(row) }}
                                            </p>
                                            <p
                                                class="text-[11px] whitespace-nowrap text-muted-foreground"
                                            >
                                                {{
                                                    row.extension === null
                                                        ? 'No agent channel retained'
                                                        : `${row.talk_samples} timed sample${row.talk_samples === 1 ? '' : 's'}`
                                                }}
                                            </p>
                                        </div>
                                    </div>
                                </th>
                                <td class="px-5 py-3 text-right tabular-nums">
                                    {{ row.calls }}
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums">
                                    {{ row.answered }}
                                </td>
                                <td class="px-5 py-3">
                                    <div
                                        class="flex items-center justify-end gap-2"
                                    >
                                        <span
                                            class="hidden h-1.5 w-14 overflow-hidden rounded-full bg-muted sm:block"
                                            aria-hidden="true"
                                        >
                                            <span
                                                class="block h-full rounded-full bg-gradient-to-r from-emerald-400 to-emerald-600"
                                                :style="{
                                                    width: `${rateWidth(row)}%`,
                                                }"
                                            />
                                        </span>
                                        <span
                                            class="w-12 text-right tabular-nums"
                                        >
                                            {{ answerRate(row) }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-5 py-3 text-right">
                                    <p class="font-semibold tabular-nums">
                                        {{ formatTime(row.talk_seconds) }}
                                    </p>
                                    <span
                                        class="mt-1 ml-auto block h-1 w-16 overflow-hidden rounded-full bg-muted"
                                        aria-hidden="true"
                                    >
                                        <span
                                            class="block h-full rounded-full bg-emerald-500/70"
                                            :style="{
                                                width: `${share(row.talk_seconds, agents.summary.talk_seconds)}%`,
                                            }"
                                        />
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums">
                                    {{ formatTime(row.avg_talk_seconds) }}
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums">
                                    {{ formatTime(row.longest_talk_seconds) }}
                                </td>
                                <td
                                    class="px-5 py-3 text-right text-amber-700 tabular-nums dark:text-amber-300"
                                >
                                    {{ formatTime(row.avg_wait_seconds) }}
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums">
                                    {{ formatTime(row.dentist_seconds) }}
                                </td>
                                <td
                                    class="px-5 py-3 text-right text-xs whitespace-nowrap text-muted-foreground"
                                >
                                    {{ formatClock(row.last_call_at) }}
                                </td>
                            </tr>
                            <tr v-if="rows.length === 0">
                                <td colspan="10">
                                    <div
                                        class="flex flex-col items-center gap-2 py-12 text-center"
                                        role="status"
                                    >
                                        <span
                                            class="grid size-12 place-items-center rounded-2xl bg-muted text-muted-foreground"
                                            aria-hidden="true"
                                        >
                                            <Inbox class="size-5" />
                                        </span>
                                        <p class="text-sm font-medium">
                                            No journeys started in this window
                                            yet
                                        </p>
                                        <p
                                            class="max-w-sm text-xs text-muted-foreground"
                                        >
                                            Agent time is attributed from the
                                            PBX extension on the agent channel,
                                            so a window without answered agent
                                            legs has nothing to measure.
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <p
                class="exp-rise exp-rise-4 flex items-start gap-2 px-1 text-xs text-muted-foreground"
            >
                <CircleHelp
                    class="mt-0.5 size-3.5 shrink-0"
                    aria-hidden="true"
                />
                <span>
                    {{ agents.summary.agents }} identified extension(s). An
                    extension is taken from the agent channel the PBX named, for
                    example 204 in
                    <code
                        class="rounded bg-muted px-1 py-0.5 font-mono text-[11px]"
                        >PJSIP/204-0000074a</code
                    >.
                </span>
            </p>
        </div>
    </div>
</template>
