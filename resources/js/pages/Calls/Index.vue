<script setup lang="ts">
import { computed, reactive } from "vue";
import { Head, Link, router, usePage } from "@inertiajs/vue3";
import AdminTable from "@/components/AdminTable.vue";
import PageHero from "@/components/exp/PageHero.vue";
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
const props = defineProps<{
    interactions: Page;
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
function search() {
    router.get(index.url(), { ...activeFilters.value }, { preserveState: true });
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
                    class="inline-flex items-center gap-2 rounded-xl bg-white/15 px-4 py-2.5 text-sm font-semibold text-white ring-1 ring-white/30 backdrop-blur transition hover:bg-white/25"
                >
                    Agent calls
                </Link>
                <Link
                    :href="didPage.url()"
                    class="inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-emerald-800 shadow-sm transition hover:bg-emerald-50"
                >
                    DID journeys
                </Link>
            </template>
        </PageHero>
        <div class="flex flex-wrap items-center gap-3">
            <a
                v-if="canExport"
                :href="exportMethod.url({ query: activeFilters })"
                class="rounded border px-4 py-2"
                >Export privacy-limited CSV</a
            >
            <p class="text-sm text-muted-foreground">
                Exports follow the current filters, contain masked numbers only and are written to the
                audit trail.
            </p>
        </div>
        <form class="flex flex-wrap items-end gap-4" @submit.prevent="search">
            <label class="grid gap-2"
                >Scope<select v-model="filters.scope" class="rounded border bg-background p-2">
                    <option value="">All calls</option>
                    <option value="mine">My calls</option>
                </select></label
            >
            <label class="grid gap-2"
                >Connection<select v-model="filters.connection" class="rounded border bg-background p-2">
                    <option value="">All</option>
                    <option value="verified">Connected (verified)</option>
                    <option value="unverified">Unverified bridge evidence</option>
                    <option value="none">No connected dentist</option>
                </select></label
            >
            <label class="grid gap-2"
                >Caller name<input v-model="filters.caller" class="rounded border bg-background p-2"
            /></label>
            <label class="grid gap-2"
                >Call ID<input
                    v-model="filters.call_id"
                    type="number"
                    min="1"
                    class="w-28 rounded border bg-background p-2"
            /></label>
            <label class="grid gap-2"
                >Agent<select v-model="filters.agent_id" class="rounded border bg-background p-2">
                    <option value="">All agents</option>
                    <option v-for="agent in agents" :key="agent.id" :value="String(agent.id)">
                        {{ agent.name }}
                    </option>
                </select></label
            >
            <label class="grid gap-2"
                >Dentist<select v-model="filters.dentist_id" class="rounded border bg-background p-2">
                    <option value="">All dentists</option>
                    <option v-for="dentist in dentists" :key="dentist.id" :value="String(dentist.id)">
                        {{ dentist.name }}
                    </option>
                </select></label
            >
            <label class="grid gap-2"
                >From<input v-model="filters.from" type="date" class="rounded border bg-background p-2"
            /></label>
            <label class="grid gap-2"
                >To<input v-model="filters.to" type="date" class="rounded border bg-background p-2"
            /></label>
            <label class="grid gap-2"
                >State<select v-model="filters.status" class="rounded border bg-background p-2">
                    <option value="">All</option>
                    <option value="active">Active / missing hangup</option>
                    <option value="completed">Completed</option>
                </select></label
            >
            <button class="rounded border px-4 py-2" type="submit">Filter</button>
            <Link :href="index()">Clear</Link>
            <details class="w-full">
                <summary class="cursor-pointer text-sm">Advanced filters</summary>
                <div class="mt-3 flex flex-wrap items-end gap-4">
                    <label class="grid gap-2"
                        >Phone number<input
                            v-model="filters.number"
                            class="w-44 rounded border bg-background p-2"
                            placeholder="e.g. 0241234567"
                    /></label>
                    <label class="grid gap-2"
                        >Correlation ID<input v-model="filters.key" class="rounded border bg-background p-2"
                    /></label>
                </div>
            </details>
        </form>
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
        >
            <template #cell-id="{ row }">
                <Link :href="show(Number(row.id), { query: activeFilters })" class="font-medium">
                    #{{ row.id }}
                </Link>
                <p class="text-xs text-muted-foreground">{{ formatStarted(row.started_at) }}</p>
            </template>
            <template #cell-caller_number="{ row }">
                <p>{{ row.caller_number ?? "Unknown / not captured" }}</p>
                <p v-if="row.caller_name" class="text-xs text-muted-foreground">
                    {{ row.caller_name }}
                </p>
            </template>
            <template #cell-connected_dentist="{ row }">
                <p>{{ dentistPrimary(row) }}</p>
                <p v-if="dentistSecondary(row)" class="text-xs text-muted-foreground">
                    {{ dentistSecondary(row) }}
                </p>
            </template>
            <template #cell-connection_label="{ row }">
                <span
                    class="rounded px-2 py-1 text-xs font-semibold"
                    :class="
                        row.connection_state === 'verified'
                            ? 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900 dark:text-emerald-100'
                            : row.connection_state === 'unverified'
                              ? 'bg-amber-100 text-amber-900 dark:bg-amber-900 dark:text-amber-100'
                              : 'bg-muted text-muted-foreground'
                    "
                    >{{ row.connection_label }}</span
                >
            </template>
            <template #cell-answered_questions="{ row }">
                {{ row.answered_questions }}/{{ row.question_count }}
            </template>
            <template #cell-duration_seconds="{ row }">{{
                formatDuration(row.duration_seconds)
            }}</template>
            <template #actions="{ row }"
                ><Link :href="show(Number(row.id), { query: activeFilters })">Open call detail</Link></template
            >
        </AdminTable>
    </div>
</template>
