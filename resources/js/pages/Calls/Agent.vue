<script setup lang="ts">
import { computed, reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowRight, Clock, Headphones, List, PhoneIncoming, Route } from '@lucide/vue';
import PageHero from '@/components/exp/PageHero.vue';
import StatTile from '@/components/exp/StatTile.vue';
import JourneyTrack from '@/components/exp/JourneyTrack.vue';
import { agent as agentPage, did as didPage, index as allCalls } from '@/routes/calls';

type Step = {
    key: string;
    label: string;
    detail: string | null;
    at: string | null;
    tone: string;
};

type Row = {
    id: number;
    started_at: string | null;
    ended_at: string | null;
    status: string;
    caller_number: string | null;
    inbound_line: string | null;
    basis: string;
    basis_label: string;
    basis_detail: string;
    chips: { label: string; tone: string; title: string }[];
    track: Step[];
    outcome_label: string | null;
    outcome: string;
    connection_state: 'verified' | 'unverified' | 'none';
    connection_label: string | null;
    transfer_to: string | null;
    agent: string | null;
    answered_questions: number;
    question_count: number;
    attempts_count: number;
    duration_seconds: number | null;
    href: string;
};

type Service = {
    state: string;
    label: string;
    reason: string;
    opens_at: string;
    closes_at: string;
    next_opening_label: string | null;
    holiday: string | null;
};

const props = defineProps<{
    calls: {
        data: Row[];
        prev_page_url: string | null;
        next_page_url: string | null;
        total?: number;
    };
    filters: { from: string; to: string; basis: string; search: string };
    stats: {
        total: number;
        reached_agent: number;
        screened: number;
        connected: number;
        self_service: number;
        open: number;
    };
    service: Service;
    agentOption: string;
    menu: Record<string, { label: string; kind: string }>;
    today: string;
}>();

const filters = reactive({ ...props.filters });
const expanded = reactive<Record<number, boolean>>({});

const serviceTone = computed(() => {
    switch (props.service.state) {
        case 'open':
            return 'bg-emerald-500/15 text-emerald-800 ring-emerald-500/30 dark:text-emerald-200';
        case 'holiday':
            return 'bg-amber-500/15 text-amber-800 ring-amber-500/30 dark:text-amber-200';
        default:
            return 'bg-slate-500/10 text-slate-700 ring-slate-500/20 dark:text-slate-200';
    }
});

const connectionTone: Record<string, string> = {
    verified: 'bg-teal-500/15 text-teal-700 ring-teal-500/30 dark:text-teal-200',
    unverified: 'bg-amber-500/15 text-amber-700 ring-amber-500/30 dark:text-amber-200',
    none: 'bg-slate-500/10 text-slate-600 ring-slate-500/20 dark:text-slate-300',
};

function search(): void {
    router.get(
        agentPage.url(),
        Object.fromEntries(
            Object.entries(filters).filter(([, value]) => value !== ''),
        ),
        { preserveState: true, preserveScroll: true },
    );
}

function reset(): void {
    filters.from = '';
    filters.to = '';
    filters.basis = 'agent';
    filters.search = '';
    search();
}

function clock(value: string | null): string {
    if (value === null) {
        return 'Time unknown';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? 'Time unknown'
        : date.toLocaleString('en-GB', {
              day: '2-digit',
              month: 'short',
              hour: '2-digit',
              minute: '2-digit',
          });
}

function duration(seconds: number | null): string {
    if (seconds === null) {
        return '—';
    }

    const total = Math.round(seconds);

    return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
}

function screeningPercent(row: Row): number {
    return row.question_count === 0
        ? 0
        : Math.round((row.answered_questions / row.question_count) * 100);
}

function toggle(id: number): void {
    expanded[id] = !expanded[id];
}
</script>

<template>
    <Head title="Agent calls" />

    <div class="min-h-full bg-slate-50/80 p-4 dark:bg-slate-950 sm:p-6 lg:p-8">
        <div class="mx-auto max-w-7xl space-y-6">
            <PageHero
                :eyebrow="`Inbound · IVR option ${agentOption}`"
                title="Agent calls"
                description="Only calls where the caller pressed the agent option on the IVR tree, or where the PBX delivered the caller to the agent. Each row keeps the screening answers and the dentist transfer that followed."
            >
                <template #actions>
                    <Link
                        :href="didPage.url()"
                        class="inline-flex items-center gap-2 rounded-xl bg-white/15 px-4 py-2.5 text-sm font-semibold text-white ring-1 ring-white/30 backdrop-blur transition hover:bg-white/25"
                    >
                        <Route class="size-4" /> DID journeys
                    </Link>
                    <Link
                        :href="allCalls.url()"
                        class="inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-emerald-800 shadow-sm transition hover:bg-emerald-50"
                    >
                        <List class="size-4" /> All calls
                    </Link>
                </template>
                <template #stats>
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <StatTile
                            label="Today's total"
                            :value="stats.total"
                            hint="Calls received on the service day"
                            tone="onDark"
                            :icon="PhoneIncoming"
                        />
                        <StatTile
                            label="Reached the agent"
                            :value="stats.reached_agent"
                            :hint="`IVR option ${agentOption} or agent leg`"
                            tone="onDark"
                            :icon="Headphones"
                            :delay="2"
                        />
                        <StatTile
                            label="Screened"
                            :value="stats.screened"
                            hint="Cases with recorded answers"
                            tone="onDark"
                            :icon="ArrowRight"
                            :delay="3"
                        />
                        <StatTile
                            label="Connected"
                            :value="stats.connected"
                            hint="Verified dentist bridge"
                            tone="onDark"
                            :icon="Clock"
                            :delay="4"
                        />
                    </div>
                </template>
            </PageHero>

            <div class="flex flex-wrap items-center gap-3">
                <span
                    class="inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-semibold ring-1"
                    :class="serviceTone"
                >
                    <span
                        class="size-2 rounded-full"
                        :class="
                            service.state === 'open'
                                ? 'exp-ring bg-emerald-500'
                                : 'bg-slate-400'
                        "
                        aria-hidden="true"
                    />
                    {{ service.label }}
                </span>
                <span class="text-xs text-muted-foreground">
                    Mon–Fri {{ service.opens_at }}–{{ service.closes_at }} Ghana time
                    <template v-if="service.next_opening_label">
                        · next opening {{ service.next_opening_label }}
                    </template>
                </span>
            </div>

            <form
                class="exp-rise exp-rise-1 grid gap-3 rounded-2xl border bg-card p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-5"
                @submit.prevent="search"
            >
                <label class="grid gap-1 text-xs font-medium text-muted-foreground">
                    From
                    <input
                        v-model="filters.from"
                        type="date"
                        class="rounded-lg border bg-background px-3 py-2 text-sm text-foreground"
                    />
                </label>
                <label class="grid gap-1 text-xs font-medium text-muted-foreground">
                    To
                    <input
                        v-model="filters.to"
                        type="date"
                        class="rounded-lg border bg-background px-3 py-2 text-sm text-foreground"
                    />
                </label>
                <label class="grid gap-1 text-xs font-medium text-muted-foreground">
                    Evidence
                    <select
                        v-model="filters.basis"
                        class="rounded-lg border bg-background px-3 py-2 text-sm text-foreground"
                    >
                        <option value="agent">Menu option {{ agentOption }} or agent leg</option>
                        <option value="all">Also include case-only history</option>
                    </select>
                </label>
                <label class="grid gap-1 text-xs font-medium text-muted-foreground">
                    Caller number
                    <input
                        v-model="filters.search"
                        placeholder="233*****34"
                        class="rounded-lg border bg-background px-3 py-2 text-sm text-foreground"
                    />
                </label>
                <div class="flex items-end gap-2">
                    <button
                        type="submit"
                        class="w-full rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-800"
                    >
                        Apply filters
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border px-3 py-2 text-sm"
                        @click="reset"
                    >
                        Reset
                    </button>
                </div>
            </form>

            <div class="exp-rise exp-rise-2 overflow-hidden rounded-2xl border bg-card shadow-sm">
                <div
                    class="flex flex-wrap items-center justify-between gap-2 border-b bg-gradient-to-r from-emerald-50/80 via-card to-card px-4 py-3 dark:from-emerald-950/40"
                >
                    <h2 class="text-sm font-semibold">Calls received by the agent</h2>
                    <p class="text-xs text-muted-foreground">
                        {{ calls.total ?? calls.data.length }} call(s) ·
                        {{ menu[agentOption]?.label ?? 'Agent option' }}
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[52rem] text-left text-sm">
                        <thead class="text-xs tracking-wide text-muted-foreground uppercase">
                            <tr class="border-b">
                                <th class="px-4 py-3 font-semibold">Started</th>
                                <th class="px-4 py-3 font-semibold">Caller</th>
                                <th class="px-4 py-3 font-semibold">Reached the agent</th>
                                <th class="px-4 py-3 font-semibold">Screening</th>
                                <th class="px-4 py-3 font-semibold">Outcome</th>
                                <th class="px-4 py-3 text-right font-semibold">Duration</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="row in calls.data" :key="row.id">
                                <tr
                                    class="group border-b transition hover:bg-emerald-50/50 dark:hover:bg-emerald-950/20"
                                >
                                    <td class="px-4 py-3 align-top">
                                        <button
                                            type="button"
                                            class="text-left font-medium transition group-hover:text-emerald-700 dark:group-hover:text-emerald-300"
                                            @click="toggle(row.id)"
                                        >
                                            {{ clock(row.started_at) }}
                                        </button>
                                        <p class="text-xs text-muted-foreground">
                                            {{ row.status === 'active' ? 'Live now' : 'Completed' }}
                                            <template v-if="row.inbound_line">
                                                · line {{ row.inbound_line }}
                                            </template>
                                        </p>
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        <p class="font-medium tabular-nums">
                                            {{ row.caller_number ?? 'Caller ID withheld' }}
                                        </p>
                                        <p class="text-xs text-muted-foreground">
                                            Agent {{ row.agent ?? 'not attributed' }}
                                        </p>
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        <span
                                            class="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-500/20 dark:text-emerald-300"
                                            :title="row.basis_detail"
                                        >
                                            {{ row.basis_label }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        <div class="flex items-center gap-2">
                                            <div
                                                class="h-1.5 w-24 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800"
                                            >
                                                <div
                                                    class="h-full rounded-full bg-gradient-to-r from-emerald-500 to-teal-500 transition-all duration-700"
                                                    :style="{ width: `${screeningPercent(row)}%` }"
                                                />
                                            </div>
                                            <span class="text-xs tabular-nums text-muted-foreground">
                                                {{ row.answered_questions }}/{{ row.question_count }}
                                            </span>
                                        </div>
                                        <p class="mt-1 text-xs text-muted-foreground">
                                            {{ row.attempts_count }} dentist attempt(s)
                                        </p>
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        <span
                                            class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ring-1"
                                            :class="connectionTone[row.connection_state]"
                                        >
                                            {{ row.connection_label }}
                                        </span>
                                        <p class="mt-1 text-xs text-muted-foreground">
                                            {{ row.outcome }}
                                        </p>
                                    </td>
                                    <td class="px-4 py-3 text-right align-top tabular-nums">
                                        {{ duration(row.duration_seconds) }}
                                        <a
                                            :href="row.href"
                                            class="mt-1 block text-xs font-semibold text-emerald-700 hover:underline dark:text-emerald-300"
                                            >Open journey</a
                                        >
                                    </td>
                                </tr>
                                <tr v-if="expanded[row.id]" class="border-b bg-muted/30">
                                    <td colspan="6" class="px-4 py-4">
                                        <JourneyTrack :steps="row.track" />
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="calls.data.length === 0">
                                <td colspan="6" class="px-4 py-12">
                                    <div class="mx-auto max-w-md text-center">
                                        <div
                                            class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-gradient-to-br from-emerald-500/15 to-teal-500/10 text-emerald-700 dark:text-emerald-300"
                                        >
                                            <Headphones class="size-7" />
                                        </div>
                                        <p class="mt-3 text-sm font-semibold">
                                            No agent calls in this window
                                        </p>
                                        <p class="mt-1 text-xs text-muted-foreground">
                                            Only callers who press option {{ agentOption }} reach an
                                            agent. Calls where the menu step was not captured can be
                                            shown by choosing “Also include case-only history”.
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <nav
                    v-if="calls.prev_page_url || calls.next_page_url"
                    class="flex items-center justify-between gap-3 border-t px-4 py-3 text-sm"
                    aria-label="Pagination"
                >
                    <Link
                        v-if="calls.prev_page_url"
                        :href="calls.prev_page_url"
                        class="rounded-lg border px-3 py-1.5 hover:bg-muted"
                        >Previous</Link
                    >
                    <span v-else />
                    <Link
                        v-if="calls.next_page_url"
                        :href="calls.next_page_url"
                        class="rounded-lg border px-3 py-1.5 hover:bg-muted"
                        >Next</Link
                    >
                </nav>
            </div>
        </div>
    </div>
</template>



