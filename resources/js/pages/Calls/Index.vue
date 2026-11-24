<script setup lang="ts">
import { computed, reactive } from "vue";
import { Head, Link, router, usePage } from "@inertiajs/vue3";
import {
    Activity,
    ArrowUpRight,
    CalendarRange,
    ChevronDown,
    CircleCheck,
    Clock,
    Clock3,
    Download,
    Headphones,
    MinusCircle,
    PhoneCall,
    PhoneIncoming,
    PhoneOff,
    RotateCcw,
    SlidersHorizontal,
    Stethoscope,
    Timer,
    TriangleAlert,
} from "@lucide/vue";
import AdminTable from "@/components/AdminTable.vue";
import CallFlowArtwork from "@/components/exp/CallFlowArtwork.vue";
import PageHero from "@/components/exp/PageHero.vue";
import StatTile from "@/components/exp/StatTile.vue";
import { agent as agentPage, did as didPage, exportMethod, index, show } from "@/routes/calls";

type Option = { id: number; name: string };
type InteractionRow = {
    id: number;
    started_at: string | null;
    caller_number: string | null;
    caller_name: string | null;
    agent: string | null;
    connected_dentist: string;
    connected_destination: string | null;
    connection_state: "verified" | "unverified" | "none";
    connection_label: string;
    unverified_dentist: string | null;
    attempted_dentists: string[];
    attempts_count: number;
    answered_questions: number;
    question_count: number;
    outcome_label: string;
    duration_seconds: number | null;
    status: string;
    exceptions_count: number;
};
type Page = {
    data: InteractionRow[];
    prev_page_url: string | null;
    next_page_url: string | null;
};
type Summary = {
    total_calls: number;
    completed_calls: number;
    active_calls: number;
    measured_calls: number;
    total_seconds: number;
    average_seconds: number | null;
    connected_calls: number;
    unverified_calls: number;
    no_connection_calls: number;
    connected_share: number;
};
const props = defineProps<{
    interactions: Page;
    summary: Summary;
    filters: Record<string, string>;
    agents: Option[];
    dentists: Option[];
}>();
const page = usePage();
const filters = reactive({
    scope: props.filters.scope ?? "",
    connection: props.filters.connection ?? "",
    caller: props.filters.caller ?? "",
    call_id: props.filters.call_id ?? "",
    agent_id: props.filters.agent_id ?? "",
    dentist_id: props.filters.dentist_id ?? "",
    from: props.filters.from ?? "",
    to: props.filters.to ?? "",
    status: props.filters.status ?? "",
    number: props.filters.number ?? "",
    key: props.filters.key ?? "",
});
const activeFilters = computed<Record<string, string>>(() =>
    Object.fromEntries(
        Object.entries(filters).filter(([, value]) => value !== "" && value !== null),
    ),
);
const canExport = computed(() =>
    (page.props.auth.permissions as string[]).includes("export call history"),
);
const rangeLabel = computed(() => {
    const from = formatFilterDate(props.filters.from);
    const to = formatFilterDate(props.filters.to);
    if (from && to) return from === to ? from : `${from} – ${to}`;
    if (from) return `From ${from}`;
    if (to) return `Up to ${to}`;
    return "All retained calls";
});
const connectedShare = computed(() => {
    const share = props.summary.connected_share;
    return `${Number.isInteger(share) ? share : share.toFixed(1)}%`;
});
const activeFilterCount = computed(() => Object.keys(activeFilters.value).length);

/** Connection mix behind the summary bar; percentages always add up to 100. */
const connectionMix = computed(() => {
    const total = props.summary.total_calls;
    const connected = props.summary.connected_calls;
    const unverified = props.summary.unverified_calls;
    const plain = Math.max(0, total - connected - unverified);
    const share = (value: number) =>
        total === 0 ? 0 : Math.round((value / total) * 1000) / 10;

    return [
        {
            key: "connected",
            label: "Verified connection",
            value: connected,
            percent: share(connected),
            bar: "bg-emerald-500",
            dot: "bg-emerald-500",
        },
        {
            key: "unverified",
            label: "Unverified bridge evidence",
            value: unverified,
            percent: share(unverified),
            bar: "bg-amber-400",
            dot: "bg-amber-400",
        },
        {
            key: "none",
            label: "No connected dentist",
            value: plain,
            percent: share(plain),
            bar: "bg-slate-300 dark:bg-slate-600",
            dot: "bg-slate-400",
        },
    ];
});

const secondaryMetrics = computed(() => [
    {
        key: "average",
        label: "Average call duration",
        value: formatClock(props.summary.average_seconds),
        hint: "Across calls with hangup evidence",
        icon: Timer,
    },
    {
        key: "completed",
        label: "Completed calls",
        value: props.summary.completed_calls.toLocaleString(),
        hint: "Start and end both retained",
        icon: CircleCheck,
    },
    {
        key: "active",
        label: "Active / missing hangup",
        value: props.summary.active_calls.toLocaleString(),
        hint: "Still open or never closed",
        icon: Activity,
    },
    {
        key: "measured",
        label: "Duration measured on",
        value: `${props.summary.measured_calls} of ${props.summary.total_calls}`,
        hint: "Closed calls contributing time",
        icon: Clock3,
    },
]);

type RangeKey = "today" | "week" | "thirty" | "month" | "all";
const quickRanges: { key: RangeKey; label: string }[] = [
    { key: "today", label: "Today" },
    { key: "week", label: "Last 7 days" },
    { key: "thirty", label: "Last 30 days" },
    { key: "month", label: "This month" },
    { key: "all", label: "All dates" },
];

/** `input[type=date]` needs a local calendar date, not a UTC instant. */
function toDateInput(date: Date): string {
    const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000);
    return local.toISOString().slice(0, 10);
}

function rangeBounds(key: RangeKey): { from: string; to: string } {
    const today = new Date();
    const to = toDateInput(today);
    const back = (days: number) => {
        const date = new Date(today);
        date.setDate(date.getDate() - days);

        return toDateInput(date);
    };

    switch (key) {
        case "today":
            return { from: to, to };
        case "week":
            return { from: back(6), to };
        case "thirty":
            return { from: back(29), to };
        case "month":
            return { from: toDateInput(new Date(today.getFullYear(), today.getMonth(), 1)), to };
        default:
            return { from: "", to: "" };
    }
}

function rangeIsActive(key: RangeKey): boolean {
    const bounds = rangeBounds(key);
    return filters.from === bounds.from && filters.to === bounds.to;
}

function applyRange(key: RangeKey): void {
    const bounds = rangeBounds(key);
    filters.from = bounds.from;
    filters.to = bounds.to;
    search();
}

/** Two-letter avatar for the caller, falling back to the call marker. */
function callerInitials(row: Record<string, unknown>): string {
    const name = typeof row.caller_name === "string" ? row.caller_name.trim() : "";
    if (name === "") return "#";
    return name
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join("");
}

function answerShare(row: Record<string, unknown>): number {
    const answered = Number(row.answered_questions ?? 0);
    const total = Number(row.question_count ?? 0);
    return total === 0 ? 0 : Math.min(100, Math.round((answered / total) * 100));
}

function connectionIcon(state: unknown) {
    if (state === "verified") return CircleCheck;
    if (state === "unverified") return TriangleAlert;
    return MinusCircle;
}

function connectionTone(state: unknown): string {
    if (state === "verified") {
        return "bg-emerald-500/10 text-emerald-700 ring-emerald-500/25 dark:text-emerald-300";
    }
    if (state === "unverified") {
        return "bg-amber-500/10 text-amber-700 ring-amber-500/25 dark:text-amber-300";
    }
    return "bg-muted text-muted-foreground ring-border";
}

function stateTone(status: unknown): string {
    return status === "completed"
        ? "bg-emerald-500/10 text-emerald-700 ring-emerald-500/25 dark:text-emerald-300"
        : "bg-sky-500/10 text-sky-700 ring-sky-500/25 dark:text-sky-300";
}
function search() {
    router.get(index.url(), { ...activeFilters.value }, { preserveState: true });
}
/** Whole-second clock (h:mm:ss) used for the filtered totals and averages. */
function formatClock(seconds: number | null | undefined) {
    if (typeof seconds !== "number" || !Number.isFinite(seconds)) return "—";
    const total = Math.max(0, Math.round(seconds));
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const remainder = total % 60;
    return [hours, minutes, remainder]
        .map((part) => String(part).padStart(2, "0"))
        .join(":");
}
function formatFilterDate(value: string | undefined) {
    if (!value) return "";
    const parsed = new Date(`${value}T00:00:00`);
    return Number.isNaN(parsed.getTime())
        ? value
        : parsed.toLocaleDateString("en-GB", {
              day: "2-digit",
              month: "short",
              year: "numeric",
          });
}
function formatDuration(seconds: unknown) {
    if (typeof seconds !== "number") return "Not available";
    const total = Math.round(seconds);
    const minutes = Math.floor(total / 60);
    return `${String(minutes).padStart(2, "0")}:${String(total % 60).padStart(2, "0")}`;
}
function formatStarted(value: unknown) {
    return typeof value === "string" ? value.replace("T", " ").slice(0, 16) : "Time unknown";
}
function dentistPrimary(row: Record<string, unknown>) {
    if (row.connection_state === "verified") return String(row.connected_dentist ?? "No connected dentist");
    if (row.connection_state === "unverified") return String(row.unverified_dentist ?? "Unverified destination");
    return "No connected dentist";
}
function dentistSecondary(row: Record<string, unknown>) {
    const attempted = Array.isArray(row.attempted_dentists) ? row.attempted_dentists.map(String) : [];
    if (row.connection_state === "verified" || attempted.length === 0) return "";
    return `Tried: ${attempted.join(", ")}`;
}
</script>
<template>
    <div class="space-y-6 p-6">
        <Head title="Call history" />
        <PageHero
            eyebrow="Call history"
            title="Every caller-to-dentist journey"
            description="A dentist is shown as connected only when verified bridge evidence exists; every attempted dentist is retained. Phone numbers are masked and caller IDs are never stored in full."
        >
            <template #actions>
                <Link
                    :href="agentPage.url()"
                    class="inline-flex items-center gap-2 rounded-xl bg-white/15 px-4 py-2.5 text-sm font-semibold text-white ring-1 ring-white/30 backdrop-blur transition duration-200 hover:-translate-y-px hover:bg-white/25"
                >
                    <Headphones class="size-4" aria-hidden="true" />
                    Agent calls
                </Link>
                <Link
                    :href="didPage.url()"
                    class="inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-emerald-800 shadow-sm transition duration-200 hover:-translate-y-px hover:bg-emerald-50"
                >
                    <PhoneIncoming class="size-4" aria-hidden="true" />
                    DID journeys
                </Link>
            </template>
            <template #artwork>
                <CallFlowArtwork />
            </template>
        </PageHero>
        <section
            class="exp-rise exp-rise-1 overflow-hidden rounded-3xl border bg-card/80 shadow-sm backdrop-blur"
            aria-label="Call history filters"
        >
            <div class="flex flex-wrap items-center justify-between gap-3 border-b bg-muted/40 px-5 py-3.5">
                <div class="flex items-center gap-3">
                    <span
                        class="grid size-9 place-items-center rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-300"
                    >
                        <SlidersHorizontal class="size-4" aria-hidden="true" />
                    </span>
                    <div>
                        <h2 class="text-sm font-semibold">Refine the history</h2>
                        <p class="text-xs text-muted-foreground">
                            {{
                                activeFilterCount === 0
                                    ? "No filters applied — showing every retained call"
                                    : `${activeFilterCount} filter${activeFilterCount === 1 ? "" : "s"} applied`
                            }}
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <a
                        v-if="canExport"
                        :href="exportMethod.url({ query: activeFilters })"
                        class="exp-btn"
                    >
                        <Download class="size-4" aria-hidden="true" />
                        Export CSV
                    </a>
                    <Link :href="index()" class="exp-btn">
                        <RotateCcw class="size-4" aria-hidden="true" />
                        Reset
                    </Link>
                </div>
            </div>

            <form class="space-y-5 p-5" @submit.prevent="search">
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="inline-flex items-center gap-1.5 text-[11px] font-semibold tracking-[0.14em] text-muted-foreground uppercase"
                    >
                        <CalendarRange class="size-3.5" aria-hidden="true" />
                        Quick range
                    </span>
                    <button
                        v-for="range in quickRanges"
                        :key="range.key"
                        type="button"
                        class="rounded-full border px-3 py-1.5 text-xs font-semibold transition duration-200"
                        :class="
                            rangeIsActive(range.key)
                                ? 'border-emerald-500/50 bg-emerald-500/10 text-emerald-700 shadow-xs dark:text-emerald-300'
                                : 'border-border bg-background text-muted-foreground hover:-translate-y-px hover:border-emerald-500/40 hover:text-foreground'
                        "
                        @click="applyRange(range.key)"
                    >
                        {{ range.label }}
                    </button>
                </div>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    <label class="exp-label"
                        >Scope<select v-model="filters.scope" class="exp-field">
                            <option value="">All calls</option>
                            <option value="mine">My calls</option>
                        </select></label
                    >
                    <label class="exp-label"
                        >Connection<select v-model="filters.connection" class="exp-field">
                            <option value="">All</option>
                            <option value="verified">Connected (verified)</option>
                            <option value="unverified">Unverified bridge evidence</option>
                            <option value="none">No connected dentist</option>
                        </select></label
                    >
                    <label class="exp-label"
                        >Caller name<input
                            v-model="filters.caller"
                            class="exp-field"
                            placeholder="Name as captured"
                    /></label>
                    <label class="exp-label"
                        >Call ID<input
                            v-model="filters.call_id"
                            type="number"
                            min="1"
                            class="exp-field"
                            placeholder="e.g. 1042"
                    /></label>
                    <label class="exp-label"
                        >Agent<select v-model="filters.agent_id" class="exp-field">
                            <option value="">All agents</option>
                            <option v-for="agent in agents" :key="agent.id" :value="String(agent.id)">
                                {{ agent.name }}
                            </option>
                        </select></label
                    >
                    <label class="exp-label"
                        >Dentist<select v-model="filters.dentist_id" class="exp-field">
                            <option value="">All dentists</option>
                            <option
                                v-for="dentist in dentists"
                                :key="dentist.id"
                                :value="String(dentist.id)"
                            >
                                {{ dentist.name }}
                            </option>
                        </select></label
                    >
                    <label class="exp-label"
                        >From<input v-model="filters.from" type="date" class="exp-field"
                    /></label>
                    <label class="exp-label"
                        >To<input v-model="filters.to" type="date" class="exp-field"
                    /></label>
                    <label class="exp-label"
                        >State<select v-model="filters.status" class="exp-field">
                            <option value="">All</option>
                            <option value="active">Active / missing hangup</option>
                            <option value="completed">Completed</option>
                        </select></label
                    >
                </div>
                <details class="group rounded-2xl border border-dashed bg-muted/25 px-4 py-3">
                    <summary
                        class="flex cursor-pointer list-none items-center justify-between gap-3 text-sm font-medium"
                    >
                        <span class="inline-flex items-center gap-2">
                            <SlidersHorizontal
                                class="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            Advanced filters
                        </span>
                        <ChevronDown
                            class="size-4 text-muted-foreground transition-transform duration-300 group-open:rotate-180"
                            aria-hidden="true"
                        />
                    </summary>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <label class="exp-label"
                            >Phone number<input
                                v-model="filters.number"
                                class="exp-field"
                                placeholder="e.g. 0241234567"
                        /></label>
                        <label class="exp-label"
                            >Correlation ID<input
                                v-model="filters.key"
                                class="exp-field"
                                placeholder="Bridge correlation key"
                        /></label>
                    </div>
                </details>

                <div class="flex flex-wrap items-center gap-3">
                    <button type="submit" class="exp-btn exp-btn-primary">Apply filters</button>
                    <p class="ml-auto max-w-md text-xs text-muted-foreground">
                        Exports follow the current filters, contain masked numbers only and are written
                        to the audit trail.
                    </p>
                </div>
            </form>
        </section>
        <section
            class="exp-rise exp-rise-2 rounded-3xl border bg-gradient-to-br from-card via-card to-emerald-500/[0.06] p-5 shadow-sm sm:p-6"
            aria-labelledby="call-summary-heading"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex items-start gap-3">
                    <span
                        class="relative mt-0.5 grid size-9 place-items-center rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-300"
                        aria-hidden="true"
                    >
                        <Activity class="size-4" />
                        <span class="exp-ping-soft absolute inset-0 rounded-xl ring-2 ring-emerald-500/30" />
                    </span>
                    <div>
                        <h2 id="call-summary-heading" class="text-sm font-semibold">
                            Filtered call summary
                        </h2>
                        <p class="text-xs text-muted-foreground">
                            {{ rangeLabel }} · every matching call, not just this page
                        </p>
                    </div>
                </div>
                <span class="exp-chip bg-muted text-muted-foreground ring-1 ring-border">
                    {{ activeFilterCount === 0 ? "No filters" : `${activeFilterCount} filters` }}
                </span>
            </div>

            <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatTile
                    label="Total calls"
                    :value="summary.total_calls"
                    :hint="`${summary.completed_calls} completed · ${summary.active_calls} active or missing hangup`"
                    :icon="PhoneCall"
                    tone="emerald"
                    :delay="1"
                />
                <StatTile
                    label="Total call duration"
                    :value="formatClock(summary.total_seconds)"
                    :hint="`Measured on ${summary.measured_calls} closed call(s)`"
                    :icon="Clock"
                    tone="sky"
                    :delay="2"
                />
                <StatTile
                    label="Connected to a dentist"
                    :value="summary.connected_calls"
                    :hint="`${connectedShare} of matching calls`"
                    :icon="Stethoscope"
                    tone="emerald"
                    :delay="3"
                />
                <StatTile
                    label="No connected dentist"
                    :value="summary.no_connection_calls"
                    :hint="`${summary.unverified_calls} with unverified bridge evidence`"
                    :icon="PhoneOff"
                    tone="rose"
                    :delay="4"
                />
            </div>
            <div class="mt-4 grid gap-4 lg:grid-cols-5">
                <div class="rounded-2xl border bg-card/80 p-4 lg:col-span-2">
                    <div class="flex items-baseline justify-between gap-2">
                        <h3
                            class="text-[11px] font-semibold tracking-[0.12em] text-muted-foreground uppercase"
                        >
                            Connection mix
                        </h3>
                        <span
                            class="text-lg font-semibold tabular-nums text-emerald-600 dark:text-emerald-300"
                        >
                            {{ connectedShare }}
                        </span>
                    </div>
                    <div class="mt-3 flex h-2.5 gap-px overflow-hidden rounded-full bg-muted">
                        <span
                            v-for="segment in connectionMix"
                            v-show="segment.value > 0"
                            :key="segment.key"
                            class="h-full transition-[width] duration-1000 ease-out"
                            :class="segment.bar"
                            :style="{ width: `${segment.percent}%` }"
                        />
                    </div>
                    <ul class="mt-4 space-y-2.5">
                        <li
                            v-for="segment in connectionMix"
                            :key="segment.key"
                            class="flex items-center justify-between gap-3 text-xs"
                        >
                            <span class="flex items-center gap-2 text-muted-foreground">
                                <span
                                    class="size-2 rounded-full"
                                    :class="segment.dot"
                                    aria-hidden="true"
                                />
                                {{ segment.label }}
                            </span>
                            <span class="font-semibold tabular-nums">
                                {{ segment.value.toLocaleString() }}
                                <span class="font-normal text-muted-foreground"
                                    >({{ segment.percent }}%)</span
                                >
                            </span>
                        </li>
                    </ul>
                </div>

                <dl class="grid gap-3 sm:grid-cols-2 lg:col-span-3">
                    <div
                        v-for="metric in secondaryMetrics"
                        :key="metric.key"
                        class="rounded-2xl border bg-card/80 p-4 transition duration-200 hover:-translate-y-px hover:shadow-sm"
                    >
                        <dt
                            class="flex items-center gap-2 text-[11px] font-semibold tracking-[0.12em] text-muted-foreground uppercase"
                        >
                            <component :is="metric.icon" class="size-3.5" aria-hidden="true" />
                            {{ metric.label }}
                        </dt>
                        <dd class="mt-1.5 text-xl font-semibold tabular-nums">{{ metric.value }}</dd>
                        <p class="text-[11px] text-muted-foreground">{{ metric.hint }}</p>
                    </div>
                </dl>
            </div>
        </section>
        <AdminTable
            :rows="interactions.data"
            :columns="[
                { key: 'id', label: 'Call' },
                { key: 'caller_number', label: 'Caller' },
                { key: 'agent', label: 'Agent' },
                { key: 'connected_dentist', label: 'Final verified dentist' },
                { key: 'connection_label', label: 'Connection' },
                { key: 'attempts_count', label: 'Attempts' },
                { key: 'answered_questions', label: 'Answers' },
                { key: 'outcome_label', label: 'Transfer outcome' },
                { key: 'duration_seconds', label: 'Duration' },
                { key: 'status', label: 'State' },
                { key: 'exceptions_count', label: 'Flags' },
            ]"
            :previous="interactions.prev_page_url"
            :next="interactions.next_page_url"
            row-noun="call"
            empty-title="No calls match these filters."
            empty-description="Widen the date range or clear a filter to see more journeys."
        >
            <template #cell-id="{ row }">
                <Link
                    :href="show(Number(row.id), { query: activeFilters })"
                    class="group/link inline-flex items-center gap-1 font-semibold tabular-nums transition hover:text-emerald-700 dark:hover:text-emerald-300"
                >
                    #{{ row.id }}
                    <ArrowUpRight
                        class="size-3 opacity-0 transition group-hover/link:opacity-100"
                        aria-hidden="true"
                    />
                </Link>
                <p class="mt-0.5 text-xs whitespace-nowrap text-muted-foreground">
                    {{ formatStarted(row.started_at) }}
                </p>
            </template>
            <template #cell-caller_number="{ row }">
                <div class="flex items-center gap-2.5">
                    <span
                        class="grid size-8 shrink-0 place-items-center rounded-full bg-gradient-to-br from-emerald-500/15 to-sky-500/15 text-[11px] font-semibold text-emerald-700 ring-1 ring-emerald-500/20 dark:text-emerald-300"
                        aria-hidden="true"
                    >
                        {{ callerInitials(row) }}
                    </span>
                    <div class="min-w-0">
                        <p class="font-medium tabular-nums">
                            {{ row.caller_number ?? "Unknown / not captured" }}
                        </p>
                        <p class="truncate text-xs text-muted-foreground">
                            {{ row.caller_name ?? "No caller name stored" }}
                        </p>
                    </div>
                </div>
            </template>
            <template #cell-connected_dentist="{ row }">
                <p class="font-medium">{{ dentistPrimary(row) }}</p>
                <p v-if="dentistSecondary(row)" class="mt-0.5 text-xs text-muted-foreground">
                    {{ dentistSecondary(row) }}
                </p>
            </template>
            <template #cell-connection_label="{ row }">
                <span class="exp-chip ring-1" :class="connectionTone(row.connection_state)">
                    <component
                        :is="connectionIcon(row.connection_state)"
                        class="size-3.5"
                        aria-hidden="true"
                    />
                    {{ row.connection_label }}
                </span>
            </template>
            <template #cell-attempts_count="{ row }">
                <span
                    class="inline-flex min-w-7 justify-center rounded-lg bg-muted px-2 py-0.5 text-xs font-semibold tabular-nums"
                >
                    {{ row.attempts_count }}
                </span>
            </template>
            <template #cell-answered_questions="{ row }">
                <div class="flex items-center gap-2">
                    <span class="text-xs font-semibold tabular-nums">
                        {{ row.answered_questions }}/{{ row.question_count }}
                    </span>
                    <span class="h-1 w-10 overflow-hidden rounded-full bg-muted" aria-hidden="true">
                        <span
                            class="block h-full rounded-full bg-emerald-500 transition-[width] duration-700 ease-out"
                            :style="{ width: `${answerShare(row)}%` }"
                        />
                    </span>
                </div>
            </template>
            <template #cell-duration_seconds="{ row }">
                <span
                    v-if="typeof row.duration_seconds === 'number'"
                    class="inline-flex items-center gap-1.5 text-xs font-medium tabular-nums"
                >
                    <Clock3 class="size-3.5 text-muted-foreground" aria-hidden="true" />
                    {{ formatDuration(row.duration_seconds) }}
                </span>
                <span v-else class="text-xs text-muted-foreground">Not measured</span>
            </template>
            <template #cell-status="{ row }">
                <span class="exp-chip capitalize ring-1" :class="stateTone(row.status)">{{
                    row.status
                }}</span>
            </template>
            <template #cell-exceptions_count="{ row }">
                <span
                    class="exp-chip ring-1"
                    :class="
                        Number(row.exceptions_count) > 0
                            ? 'bg-rose-500/10 text-rose-700 ring-rose-500/25 dark:text-rose-300'
                            : 'bg-muted text-muted-foreground ring-border'
                    "
                >
                    {{ Number(row.exceptions_count) > 0 ? row.exceptions_count : "None" }}
                </span>
            </template>
        </AdminTable>
    </div>
</template>
