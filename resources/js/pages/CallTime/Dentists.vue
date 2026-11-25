<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import {
    ChevronDown,
    CircleAlert,
    LayoutDashboard,
    PhoneIncoming,
    PhoneOutgoing,
    ShieldCheck,
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
    CallTimeDentistRow,
    CallTimeDentists,
    CallTimeFilters as CallTimeFiltersState,
} from '@/types/call-time';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    dentists: CallTimeDentists;
    filters: CallTimeFiltersState;
    windows: number[];
}>();

function label(row: CallTimeDentistRow): string {
    return row.name ?? 'Unattributed mobile legs';
}

/** Initials for the directory avatar; unmatched legs fall back to `?`. */
function initials(row: CallTimeDentistRow): string {
    const name = row.name?.trim();

    if (!name) {
        return '?';
    }

    return name
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

/** Width of the answer-rate bar, kept at 0 for rows without attempts. */
function rateWidth(row: CallTimeDentistRow): number {
    return row.attempts === 0
        ? 0
        : Math.round((row.answered / row.attempts) * 100);
}

type SortKey =
    | 'dentist'
    | 'attempts'
    | 'answered'
    | 'rate'
    | 'connected'
    | 'average'
    | 'dial'
    | 'ringing'
    | 'consultation'
    | 'last';

const columns: { key: SortKey; label: string; numeric?: boolean }[] = [
    { key: 'dentist', label: 'Dentist' },
    { key: 'attempts', label: 'Attempts', numeric: true },
    { key: 'answered', label: 'Answered', numeric: true },
    { key: 'rate', label: 'Answer rate', numeric: true },
    { key: 'connected', label: 'Connected time', numeric: true },
    { key: 'average', label: 'Average', numeric: true },
    { key: 'dial', label: 'Avg dial to answer', numeric: true },
    { key: 'ringing', label: 'Avg ringing', numeric: true },
    { key: 'consultation', label: 'Consultation', numeric: true },
    { key: 'last', label: 'Last attempt', numeric: true },
];

const sorters: Record<
    SortKey,
    (row: CallTimeDentistRow) => number | string | null
> = {
    dentist: (row) => row.name,
    attempts: (row) => row.attempts,
    answered: (row) => row.answered,
    rate: (row) => (row.attempts === 0 ? null : row.answered / row.attempts),
    connected: (row) => row.connected_seconds,
    average: (row) => row.avg_connected_seconds,
    dial: (row) => row.avg_dial_to_answer_seconds,
    ringing: (row) => row.avg_ringing_seconds,
    consultation: (row) => row.consultation_seconds,
    last: (row) => row.last_attempt_at,
};

const sort = ref<{ key: SortKey; dir: 'asc' | 'desc' }>({
    key: 'connected',
    dir: 'desc',
});

/** Rows keep a stable, server-independent order until a header is clicked. */
const rows = computed(() => {
    const direction = sort.value.dir === 'asc' ? 1 : -1;
    const read = sorters[sort.value.key];

    return [...props.dentists.rows].sort((left, right) => {
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
            : { key, dir: key === 'dentist' ? 'asc' : 'desc' };
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
    <Head title="Call time by dentist" />

    <div class="min-h-full bg-slate-50/80 p-4 sm:p-6 lg:p-8 dark:bg-slate-950">
        <div class="mx-auto max-w-7xl space-y-6">
            <PageHero
                eyebrow="Dentist mobile time"
                title="Call time by dentist"
                description="Connected time for every answered mobile leg, attributed to the dentist whose directory number the transfer tracker matched. A network answer does not prove a human dentist accepted the call."
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
                        :href="agentsPage.url({ query: windowQuery(filters) })"
                        class="inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-emerald-800 shadow-lg shadow-emerald-950/20 transition hover:-translate-y-0.5 hover:bg-emerald-50 focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none"
                    >
                        <Stethoscope class="size-4" aria-hidden="true" />
                        Time by agent
                    </Link>
                </template>

                <template #stats>
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                        <StatTile
                            label="Dentist time"
                            :value="
                                formatTime(dentists.summary.connected_seconds)
                            "
                            hint="Answered mobile legs only"
                            :icon="Timer"
                            tone="onDark"
                            :delay="1"
                        />
                        <StatTile
                            label="Attempts"
                            :value="String(dentists.summary.attempts)"
                            hint="Dialled in this window"
                            :icon="PhoneOutgoing"
                            tone="onDark"
                            :delay="2"
                        />
                        <StatTile
                            label="Answered"
                            :value="String(dentists.summary.answered)"
                            hint="Network accepted the leg"
                            :icon="PhoneIncoming"
                            tone="onDark"
                            :delay="3"
                        />
                        <StatTile
                            label="Answer rate"
                            :value="percent(dentists.summary.answer_rate)"
                            hint="Answered ÷ attempts"
                            :icon="ShieldCheck"
                            tone="onDark"
                            :delay="4"
                        />
                        <StatTile
                            label="Matched"
                            :value="percent(dentists.summary.match_rate)"
                            hint="Directory number resolved"
                            :icon="Stethoscope"
                            tone="onDark"
                            :delay="5"
                        />
                    </div>
                </template>
            </PageHero>

            <CallTimeNav />

            <CallTimeFilters
                :action="dentistsPage.url()"
                :filters="filters"
                :windows="windows"
                :measured="dentists.window"
                measure="dial"
            />

            <p
                v-if="dentists.summary.unmatched_attempts > 0"
                role="status"
                class="exp-rise flex items-start gap-3 rounded-2xl border border-amber-300/70 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100"
            >
                <CircleAlert
                    class="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                <span>
                    {{ dentists.summary.unmatched_attempts }} attempt(s) could
                    not be matched to a directory number, so their connected
                    time is shown as unattributed. Check the dentist directory
                    numbers against the number the PBX actually dialled.
                </span>
            </p>

            <section
                class="exp-rise exp-rise-3 overflow-hidden rounded-3xl border bg-card shadow-sm"
                aria-labelledby="dentist-league-heading"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <span
                            class="grid size-9 shrink-0 place-items-center rounded-xl bg-emerald-500/10 text-emerald-700 ring-1 ring-emerald-500/20 dark:text-emerald-300"
                            aria-hidden="true"
                        >
                            <Stethoscope class="size-4" />
                        </span>
                        <div class="min-w-0">
                            <h2
                                id="dentist-league-heading"
                                class="text-sm font-semibold"
                            >
                                Dentist league table
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
                                            {{ initials(row) }}
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
                                                    row.matcher === 'unmatched'
                                                        ? 'Directory number not matched'
                                                        : 'Matched from directory number'
                                                }}
                                            </p>
                                        </div>
                                    </div>
                                </th>
                                <td class="px-5 py-3 text-right tabular-nums">
                                    {{ row.attempts }}
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
                                            {{ percent(row.answer_rate) }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-5 py-3 text-right">
                                    <p class="font-semibold tabular-nums">
                                        {{ formatTime(row.connected_seconds) }}
                                    </p>
                                    <span
                                        class="mt-1 ml-auto block h-1 w-16 overflow-hidden rounded-full bg-muted"
                                        aria-hidden="true"
                                    >
                                        <span
                                            class="block h-full rounded-full bg-emerald-500/70"
                                            :style="{
                                                width: `${share(row.connected_seconds, dentists.summary.connected_seconds)}%`,
                                            }"
                                        />
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums">
                                    {{ formatTime(row.avg_connected_seconds) }}
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums">
                                    {{
                                        formatTime(
                                            row.avg_dial_to_answer_seconds,
                                        )
                                    }}
                                </td>
                                <td
                                    class="px-5 py-3 text-right text-amber-700 tabular-nums dark:text-amber-300"
                                >
                                    {{ formatTime(row.avg_ringing_seconds) }}
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums">
                                    {{ formatTime(row.consultation_seconds) }}
                                </td>
                                <td
                                    class="px-5 py-3 text-right text-xs whitespace-nowrap text-muted-foreground"
                                >
                                    {{ formatClock(row.last_attempt_at) }}
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
                                            <Stethoscope class="size-5" />
                                        </span>
                                        <p class="text-sm font-medium">
                                            No dentist attempts were dialled in
                                            this window yet
                                        </p>
                                        <p
                                            class="max-w-sm text-xs text-muted-foreground"
                                        >
                                            Connected time needs an answered
                                            mobile leg. Widen the window or
                                            check that transfers are being
                                            dialled at all.
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
