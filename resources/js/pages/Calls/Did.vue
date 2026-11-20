<script setup lang="ts">
import { computed, reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronDown, Headphones, PhoneIncoming, Route, Sparkles } from '@lucide/vue';
import PageHero from '@/components/exp/PageHero.vue';
import StatTile from '@/components/exp/StatTile.vue';
import JourneyTrack from '@/components/exp/JourneyTrack.vue';
import { did as didPage, agent as agentPage, index as allCalls } from '@/routes/calls';

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
    selections: { option: string | null; label: string; kind: string; at: string | null }[];
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
    filters: {
        from: string;
        to: string;
        option: string;
        agent: string;
        search: string;
    };
    stats: {
        total: number;
        reached_agent: number;
        screened: number;
        connected: number;
        self_service: number;
        open: number;
    };
    service: Service;
    menu: Record<string, { label: string; kind: string }>;
    agentOption: string;
    inboundDids: string[];
    today: string;
}>();

const filters = reactive({ ...props.filters });
const expanded = reactive<Record<number, boolean>>({});

const menuRows = computed(() =>
    Object.entries(props.menu).map(([digit, option]) => ({
        digit,
        ...option,
        agent: digit === props.agentOption,
    })),
);

const kindTone: Record<string, string> = {
    agent: 'bg-emerald-500/10 text-emerald-700 ring-emerald-500/20 dark:text-emerald-300',
    recording: 'bg-sky-500/10 text-sky-700 ring-sky-500/20 dark:text-sky-300',
    callback: 'bg-amber-500/10 text-amber-700 ring-amber-500/20 dark:text-amber-300',
    unknown: 'bg-slate-500/10 text-slate-600 ring-slate-500/20 dark:text-slate-300',
};

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

function search(): void {
    router.get(
        didPage.url(),
        Object.fromEntries(
            Object.entries(filters).filter(([, value]) => value !== ''),
        ),
        { preserveState: true, preserveScroll: true },
    );
}

function reset(): void {
    filters.from = '';
    filters.to = '';
    filters.option = '';
    filters.agent = '';
    filters.search = '';
    search();
}

function toggle(id: number): void {
    expanded[id] = !expanded[id];
}
</script>

<template>
    <Head title="DID call journeys" />

    <div class="min-h-full bg-slate-50/80 p-4 dark:bg-slate-950 sm:p-6 lg:p-8">
        <div class="mx-auto max-w-7xl space-y-6">
            <PageHero
                eyebrow="Inbound · assigned DID"
                title="Every call to the line"
                description="Each call that reaches the assigned DID, with the menu the caller heard and the option they pressed — recorded message, callback or agent — and how the journey ended."
                accent="violet"
            >
                <template #actions>
                    <Link
                        :href="agentPage.url()"
                        class="inline-flex items-center gap-2 rounded-xl bg-white/15 px-4 py-2.5 text-sm font-semibold text-white ring-1 ring-white/30 backdrop-blur transition hover:bg-white/25"
                    >
                        <Headphones class="size-4" /> Agent calls
                    </Link>
                    <Link
                        :href="allCalls.url()"
                        class="inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-indigo-800 shadow-sm transition hover:bg-indigo-50"
                    >
                        <PhoneIncoming class="size-4" /> All calls
                    </Link>
                </template>
                <template #stats>
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <StatTile
                            label="Calls today"
                            :value="stats.total"
                            hint="Arrivals on the assigned DID"
                            tone="onDark"
                            :icon="PhoneIncoming"
                        />
                        <StatTile
                            label="Reached an agent"
                            :value="stats.reached_agent"
                            :hint="`Pressed option ${agentOption} or agent leg`"
                            tone="onDark"
                            :icon="Headphones"
                            :delay="2"
                        />
                        <StatTile
                            label="Self-service"
                            :value="stats.self_service"
                            hint="No agent contact"
                            tone="onDark"
                            :icon="Sparkles"
                            :delay="3"
                        />
                        <StatTile
                            label="Live now"
                            :value="stats.open"
                            hint="Calls still in progress"
                            tone="onDark"
                            :icon="Route"
                            :delay="4"
                        />
                    </div>
                </template>
            </PageHero>

            <div class="flex flex-wrap items-center gap-3">
                <span
                    class="inline-flex items-center gap-2 rounded-full bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-200 dark:ring-slate-800"
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
                    Assigned DID:
                    {{ inboundDids.length ? inboundDids.join(', ') : 'not configured' }}
                    · Mon–Fri {{ service.opens_at }}–{{ service.closes_at }} Ghana time
                </span>
            </div>

            <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_19rem]">
                <div class="space-y-4">
                    <form
                        class="exp-rise exp-rise-1 grid gap-3 rounded-2xl border bg-card p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-3"
                        @submit.prevent="search"
                    >
                        <label class="grid gap-1 text-xs font-medium text-muted-foreground">
                            From
                            <input
                                v-model="filters.from"
                                type="date"
                                class="rounded-lg border bg-background px-3 py-2 text-sm"
                            />
                        </label>
                        <label class="grid gap-1 text-xs font-medium text-muted-foreground">
                            To
                            <input
                                v-model="filters.to"
                                type="date"
                                class="rounded-lg border bg-background px-3 py-2 text-sm"
                            />
                        </label>
                        <label class="grid gap-1 text-xs font-medium text-muted-foreground">
                            Menu option
                            <select
                                v-model="filters.option"
                                class="rounded-lg border bg-background px-3 py-2 text-sm"
                            >
                                <option value="">Any option</option>
                                <option
                                    v-for="item in menuRows"
                                    :key="item.digit"
                                    :value="item.digit"
                                >
                                    {{ item.digit }} — {{ item.label }}
                                </option>
                            </select>
                        </label>
                        <label class="grid gap-1 text-xs font-medium text-muted-foreground">
                            Reached an agent
                            <select
                                v-model="filters.agent"
                                class="rounded-lg border bg-background px-3 py-2 text-sm"
                            >
                                <option value="">Either</option>
                                <option value="yes">Agent contact</option>
                                <option value="no">Self-service only</option>
                            </select>
                        </label>
                        <label class="grid gap-1 text-xs font-medium text-muted-foreground">
                            Caller number
                            <input
                                v-model="filters.search"
                                placeholder="233*****34"
                                class="rounded-lg border bg-background px-3 py-2 text-sm"
                            />
                        </label>
                        <div class="flex items-end gap-2">
                            <button
                                type="submit"
                                class="w-full rounded-lg bg-indigo-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-800"
                            >
                                Apply
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

                    <div class="space-y-3">
                        <article
                            v-for="(row, index) in calls.data"
                            :key="row.id"
                            class="exp-rise group overflow-hidden rounded-2xl border bg-card shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
                            :class="`exp-rise-${Math.min(index + 1, 4)}`"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-3 p-4">
                                <div class="space-y-1">
                                    <p class="text-sm font-semibold tabular-nums">
                                        {{ row.caller_number ?? 'Caller ID withheld' }}
                                        <span class="text-xs font-normal text-muted-foreground">
                                            → {{ row.inbound_line ?? 'assigned DID' }}
                                        </span>
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ clock(row.started_at) }} · {{ duration(row.duration_seconds) }}
                                        <template v-if="row.agent"> · Agent {{ row.agent }}</template>
                                    </p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span
                                        class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ring-1"
                                        :class="kindTone[row.selections.at(-1)?.kind ?? 'unknown']"
                                    >
                                        {{ row.selections.at(-1)?.option ? `Option ${row.selections.at(-1)?.option}` : 'Menu not recorded' }}
                                    </span>
                                    <a
                                        :href="row.href"
                                        class="text-xs font-semibold text-indigo-700 hover:underline dark:text-indigo-300"
                                        >Open</a
                                    >
                                </div>
                            </div>

                            <div class="border-t bg-muted/20 px-4 py-3">
                                <JourneyTrack :steps="row.chips" variant="chips" />
                                <p class="mt-2 text-xs text-muted-foreground">{{ row.outcome }}</p>
                            </div>

                            <button
                                type="button"
                                class="flex w-full items-center justify-between gap-2 px-4 py-2 text-xs font-medium text-muted-foreground transition hover:bg-muted/40"
                                @click="toggle(row.id)"
                            >
                                <span>{{ expanded[row.id] ? 'Hide journey' : 'Show full journey' }}</span>
                                <ChevronDown
                                    class="size-4 transition-transform"
                                    :class="expanded[row.id] ? 'rotate-180' : ''"
                                />
                            </button>

                            <div v-if="expanded[row.id]" class="border-t px-4 py-4">
                                <JourneyTrack :steps="row.track" />
                            </div>
                        </article>

                        <div
                            v-if="calls.data.length === 0"
                            class="rounded-2xl border bg-card p-10 text-center shadow-sm"
                        >
                            <div
                                class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-500/15 to-sky-500/10 text-indigo-700 dark:text-indigo-300"
                            >
                                <Route class="size-7" />
                            </div>
                            <p class="mt-3 text-sm font-semibold">No calls in this window</p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Calls appear here once the PBX reports an arrival on the assigned
                                DID. Menu steps are shown only when the dialplan supplied them.
                            </p>
                        </div>
                    </div>


                    <nav
                        v-if="calls.prev_page_url || calls.next_page_url"
                        class="flex items-center justify-between gap-3 rounded-2xl border bg-card px-4 py-3 text-sm shadow-sm"
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

                <aside class="space-y-4 lg:sticky lg:top-6 lg:self-start">
                    <div class="exp-rise exp-rise-2 overflow-hidden rounded-2xl border bg-card shadow-sm">
                        <div
                            class="border-b bg-gradient-to-r from-violet-50/80 to-card px-4 py-3 dark:from-violet-950/40"
                        >
                            <p class="text-sm font-semibold">The menu callers hear</p>
                            <p class="text-xs text-muted-foreground">
                                Option {{ agentOption }} is the only route to an agent.
                            </p>
                        </div>
                        <ul class="divide-y">
                            <li
                                v-for="item in menuRows"
                                :key="item.digit"
                                class="flex items-start gap-3 px-4 py-3"
                            >
                                <span
                                    class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg text-xs font-bold"
                                    :class="kindTone[item.kind]"
                                >
                                    {{ item.digit }}
                                </span>
                                <div>
                                    <p class="text-xs font-medium">{{ item.label }}</p>
                                    <p class="text-[11px] text-muted-foreground">
                                        {{ item.agent ? 'Agent screening and transfer' : 'Self-service journey' }}
                                    </p>
                                </div>
                            </li>
                        </ul>
                    </div>

                    <div class="rounded-2xl border bg-card p-4 text-xs text-muted-foreground shadow-sm">
                        <p class="font-semibold text-foreground">Evidence rules</p>
                        <ul class="mt-2 space-y-1.5">
                            <li>· A key press is shown only when the dialplan reported it.</li>
                            <li>· “Menu not recorded” means the PBX evidence is missing, not that the caller pressed nothing.</li>
                            <li>· Phone numbers stay masked; caller IDs are never stored in full.</li>
                        </ul>
                    </div>
                </aside>
            </div>
        </div>
    </div>
</template>

