<script setup lang="ts">
import { computed, reactive, ref } from "vue";
import { Head, Link, router, useForm, usePoll } from "@inertiajs/vue3";
import {
    ClipboardPenLine,
    Headphones,
    RefreshCw,
    PhoneIncoming,
    Search,
    ArrowRight,
} from "lucide-vue-next";
import AppLayout from "@/layouts/AppLayout.vue";
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from "@/components/ui/dialog";
import { index, store, show } from "@/routes/workspace";
import { index as protocols } from "@/routes/screening-protocols";
import {
    formatClock,
    formatSeconds,
    freshnessClasses,
    stageClasses,
    stageLabel,
} from "@/lib/live-operations";

defineOptions({ layout: AppLayout });
type CallRecord = {
    id: number;
    correlation_key: string;
    status: string;
    started_at: string | null;
    stage: string;
    caller_number: string | null;
    inbound_did: string | null;
    agent_extension: string;
    timing_quality: string;
    exceptions: number;
    case_id: number | null;
    ambiguity: { requires_confirmation: boolean; reasons: string[] };
};
type CaseRow = {
    id: number;
    interaction_id: number;
    status: string;
    priority: string | null;
    updated_at: string;
};
const props = defineProps<{
    cases: {
        data: CaseRow[];
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    callContext: {
        generated_at: string;
        agent_extension: string;
        freshness: { state: string; heartbeat_age_seconds: number | null };
        calls: CallRecord[];
        recent: CallRecord[];
    } | null;
    filters: { status?: string; priority?: string; mine?: boolean };
    canEdit: boolean;
    canProtocols: boolean;
    canOpenCase: boolean;
}>();
const filters = reactive({
    status: props.filters.status ?? "",
    priority: props.filters.priority ?? "",
    mine: Boolean(props.filters.mine),
});
const form = useForm({
    interaction_id: "",
    association_confirmed: false,
    ambiguity_acknowledged: false,
});
const query = ref("");
const selected = ref<CallRecord | null>(null);
const dialogOpen = ref(false);
const liveUpdates = ref(props.canEdit);
const { start, stop } = usePoll(
    5000,
    { only: ["callContext", "cases"] },
    { autoStart: props.canEdit },
);
const records = computed(() => {
    const unique = new Map<number, CallRecord>();
    for (const call of [
        ...(props.callContext?.calls ?? []),
        ...(props.callContext?.recent ?? []),
    ]) {
        unique.set(call.id, call);
    }
    return [...unique.values()].sort(
        (a, b) => (b.started_at ?? "").localeCompare(a.started_at ?? "") || b.id - a.id,
    );
});
const filteredRecords = computed(() => {
    const term = query.value.trim().toLowerCase();
    return records.value.filter((call) =>
        `${call.id} ${call.correlation_key} ${call.caller_number ?? ""} ${call.case_id ?? ""}`
            .toLowerCase()
            .includes(term),
    );
});
const needsCase = computed(() => records.value.filter((call) => call.case_id === null).length);
const ambiguityLabels: Record<string, string> = {
    multiple_active_calls:
        "Several calls are marked active. Check this call’s reference and arrival time.",
    correlation_exceptions: "Some call evidence is incomplete or conflicting.",
    possible_missing_hangup: "The PBX may have missed this call’s end.",
};
function selectCall(call: CallRecord) {
    selected.value = call;
    form.reset();
    form.clearErrors();
    form.interaction_id = String(call.id);
    dialogOpen.value = true;
}
function toggleUpdates() {
    liveUpdates.value = !liveUpdates.value;
    if (liveUpdates.value) start();
    else stop();
}
function search() {
    router.get(index.url(), { ...filters, mine: filters.mine ? 1 : 0 });
}
const filterClass = "min-h-10 rounded-lg border bg-background px-3 py-2 text-sm";
</script>

<template>
    <div class="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-6">
        <Head title="Agent workspace" />
        <header
            class="overflow-hidden rounded-2xl bg-gradient-to-br from-emerald-800 via-teal-800 to-cyan-900 p-6 text-white md:p-8"
        >
            <div class="flex flex-wrap items-start justify-between gap-5">
                <div class="space-y-2">
                    <p class="text-xs font-semibold tracking-widest text-emerald-100 uppercase">
                        Call desk
                    </p>
                    <h1 class="text-3xl font-semibold tracking-tight">Agent workspace</h1>
                    <p class="max-w-xl text-sm leading-relaxed text-emerald-50">
                        Only calls where the caller selected IVR option 3 appear here. Open the case and record the caller’s answers. Each call
                        keeps one case.
                    </p>
                </div>
                <div class="flex items-center gap-3 rounded-xl bg-white/10 px-4 py-3">
                    <Headphones class="size-5" />
                    <div>
                        <p class="text-xs text-emerald-100">Agent extension</p>
                        <p class="font-semibold">{{ callContext?.agent_extension ?? "—" }}</p>
                    </div>
                </div>
            </div>
            <div class="mt-6 grid gap-3 sm:grid-cols-3">
                <div class="rounded-xl bg-white/10 p-4">
                    <p class="text-sm text-emerald-100">Recent call records</p>
                    <p class="mt-1 text-3xl font-semibold">{{ records.length }}</p>
                </div>
                <div class="rounded-xl bg-white/10 p-4">
                    <p class="text-sm text-emerald-100">Cases to start</p>
                    <p class="mt-1 text-3xl font-semibold">{{ needsCase }}</p>
                </div>
                <div class="rounded-xl bg-white/10 p-4">
                    <p class="text-sm text-emerald-100">Cases in this view</p>
                    <p class="mt-1 text-3xl font-semibold">{{ cases.total }}</p>
                </div>
            </div>
        </header>
        <p
            v-if="canEdit && !canOpenCase"
            role="alert"
            class="rounded-xl border border-amber-400 bg-amber-50 p-4 text-sm text-amber-950 dark:bg-amber-950 dark:text-amber-100"
        >
            You can update existing cases. Opening a new case needs the create-cases permission —
            ask your supervisor if the button below stays disabled.
        </p>

        <section v-if="canEdit" class="overflow-hidden rounded-2xl border bg-card shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-4 border-b p-5">
                <div>
                    <h2 class="flex items-center gap-2 text-lg font-semibold">
                        <PhoneIncoming class="size-5 text-emerald-700 dark:text-emerald-400" />Call
                        records
                    </h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        IVR option 3 · latest 24 hours. Answer and transfer calls in Jitsi.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="rounded-full px-3 py-1 text-xs font-semibold uppercase"
                        :class="freshnessClasses(callContext?.freshness.state ?? 'offline')"
                        >PBX {{ callContext?.freshness.state ?? "offline" }}</span
                    >
                    <button type="button" :class="filterClass" @click="toggleUpdates">
                        {{ liveUpdates ? "Pause updates" : "Resume updates" }}
                    </button>
                    <button
                        type="button"
                        :class="filterClass"
                        @click="router.reload({ only: ['callContext', 'cases'] })"
                    >
                        <RefreshCw class="mr-1 inline size-4" />Refresh
                    </button>
                </div>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                <label class="flex min-w-0 items-center gap-2 rounded-lg border px-3 py-2"
                    ><Search class="size-4 text-muted-foreground" /><input
                        v-model="query"
                        type="search"
                        aria-label="Find a call record"
                        placeholder="Find call, masked number or case…"
                        class="w-64 max-w-full bg-transparent text-sm outline-none"
                /></label>
                <p class="text-xs text-muted-foreground">
                    Heartbeat {{ formatSeconds(callContext?.freshness.heartbeat_age_seconds) }} ago
                    · {{ filteredRecords.length }} record(s)
                </p>
            </div>
            <p
                v-if="callContext && callContext.freshness.state !== 'live'"
                role="alert"
                class="mx-5 mb-4 rounded-lg border border-amber-400 bg-amber-50 p-3 text-sm text-amber-950 dark:bg-amber-950 dark:text-amber-100"
            >
                Call evidence is {{ callContext.freshness.state }}. Check Jitsi; missing evidence
                does not mean no call is waiting.
            </p>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-y bg-muted/50 text-xs text-muted-foreground uppercase">
                        <tr>
                            <th class="px-5 py-3">Call / caller</th>
                            <th class="px-5 py-3">Arrived</th>
                            <th class="px-5 py-3">Call state</th>
                            <th class="px-5 py-3">Case</th>
                            <th class="px-5 py-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="call in filteredRecords"
                            :key="call.id"
                            class="hover:bg-muted/30"
                        >
                            <td class="px-5 py-4">
                                <p class="font-medium">
                                    {{ call.caller_number ?? "Caller number not supplied" }}
                                </p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    Call #{{ call.id }} · {{ call.correlation_key }}
                                </p>
                            </td>
                            <td class="whitespace-nowrap px-5 py-4">
                                {{ formatClock(call.started_at) }}
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{ call.inbound_did ?? "Line not supplied" }}
                                </p>
                            </td>
                            <td class="px-5 py-4">
                                <span
                                    class="inline-block whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium"
                                    :class="stageClasses(call.stage)"
                                    >{{ stageLabel(call.stage) }}</span
                                >
                                <p
                                    v-if="call.exceptions"
                                    class="mt-1 text-xs text-amber-700 dark:text-amber-400"
                                >
                                    {{ call.exceptions }} evidence issue(s)
                                </p>
                            </td>
                            <td class="whitespace-nowrap px-5 py-4">
                                <span v-if="call.case_id" class="font-medium"
                                    >#{{ call.case_id }}</span
                                ><span v-else class="text-muted-foreground">Not started</span>
                            </td>
                            <td class="px-5 py-4 text-right">
                                <Link
                                    v-if="call.case_id"
                                    :href="show(call.case_id)"
                                    class="inline-flex min-h-10 items-center gap-2 whitespace-nowrap rounded-lg bg-primary px-3 py-2 font-medium text-primary-foreground"
                                    ><ClipboardPenLine class="size-4" />Update case</Link
                                ><button
                                    v-else
                                    type="button"
                                    :disabled="!canOpenCase || form.processing"
                                    class="inline-flex min-h-10 items-center gap-2 whitespace-nowrap rounded-lg border px-3 py-2 font-medium disabled:opacity-50"
                                    @click="selectCall(call)"
                                >
                                    <ClipboardPenLine class="size-4" />Add case details
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!filteredRecords.length">
                            <td colspan="5" class="p-10 text-center text-muted-foreground">
                                {{
                                    query
                                        ? "No calls match this search."
                                        : "No recorded option-3 calls. Check Jitsi or refresh the list."
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section id="cases" class="overflow-hidden rounded-2xl border bg-card shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-4 border-b p-5">
                <div>
                    <h2 class="text-lg font-semibold">Case records</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Continue the same record as information becomes available.
                    </p>
                </div>
                <Link v-if="canProtocols" :href="protocols()" class="text-sm font-medium underline"
                    >Manage questionnaire</Link
                >
            </div>
            <nav aria-label="Case views" class="flex flex-wrap gap-2 px-5 pt-4 text-sm">
                <Link
                    :href="index()"
                    class="rounded-lg px-3 py-2"
                    :class="
                        !filters.status && !filters.mine
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-muted'
                    "
                    >All cases</Link
                ><Link
                    :href="index({ query: { mine: 1, status: 'open' } })"
                    class="rounded-lg bg-muted px-3 py-2"
                    >My open cases</Link
                ><Link
                    :href="index({ query: { status: 'clinical_review' } })"
                    class="rounded-lg px-3 py-2"
                    :class="
                        filters.status === 'clinical_review'
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-muted'
                    "
                    >Clinical review</Link
                ><Link
                    :href="index({ query: { status: 'closed' } })"
                    class="rounded-lg px-3 py-2"
                    :class="
                        filters.status === 'closed'
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-muted'
                    "
                    >Closed</Link
                >
            </nav>
            <form class="flex flex-wrap items-end gap-3 p-5" @submit.prevent="search">
                <label class="grid gap-1 text-xs text-muted-foreground"
                    >Status<select v-model="filters.status" :class="filterClass">
                        <option value="">All statuses</option>
                        <option value="open">Open</option>
                        <option value="clinical_review">Clinical review</option>
                        <option value="closed">Closed</option>
                    </select></label
                ><label class="grid gap-1 text-xs text-muted-foreground"
                    >Priority<select v-model="filters.priority" :class="filterClass">
                        <option value="">All priorities</option>
                        <option
                            v-for="priority in ['P1', 'P2', 'P3', 'S', 'Review']"
                            :key="priority"
                        >
                            {{ priority }}
                        </option>
                    </select></label
                ><label class="flex min-h-10 items-center gap-2 text-sm"
                    ><input v-model="filters.mine" type="checkbox" class="size-4" />My cases
                    only</label
                ><button :class="filterClass">Apply filters</button>
            </form>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-y bg-muted/50 text-xs text-muted-foreground uppercase">
                        <tr>
                            <th class="px-5 py-3">Case</th>
                            <th class="px-5 py-3">Call</th>
                            <th class="px-5 py-3">Priority</th>
                            <th class="px-5 py-3">Status</th>
                            <th class="px-5 py-3">Last updated</th>
                            <th class="px-5 py-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="row in cases.data" :key="row.id" class="hover:bg-muted/30">
                            <td class="px-5 py-4 font-medium">#{{ row.id }}</td>
                            <td class="px-5 py-4">#{{ row.interaction_id }}</td>
                            <td class="px-5 py-4">{{ row.priority ?? "Not screened" }}</td>
                            <td class="px-5 py-4">{{ row.status.replaceAll("_", " ") }}</td>
                            <td class="whitespace-nowrap px-5 py-4">
                                {{ formatClock(row.updated_at) }}
                            </td>
                            <td class="px-5 py-4 text-right">
                                <Link
                                    :href="show(row.id)"
                                    class="inline-flex min-h-10 items-center gap-2 whitespace-nowrap rounded-lg border px-3 py-2 font-medium"
                                    >{{
                                        canEdit && row.status !== "closed"
                                            ? "Update case"
                                            : "View case"
                                    }}<ArrowRight class="size-4"
                                /></Link>
                            </td>
                        </tr>
                        <tr v-if="!cases.data.length">
                            <td colspan="6" class="p-10 text-center text-muted-foreground">
                                No cases match these filters.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <nav aria-label="Case pagination" class="flex gap-4 border-t p-4 text-sm">
                <Link
                    v-if="cases.prev_page_url"
                    :href="cases.prev_page_url"
                    class="rounded-lg border px-3 py-2"
                    >Previous</Link
                ><Link
                    v-if="cases.next_page_url"
                    :href="cases.next_page_url"
                    class="rounded-lg border px-3 py-2"
                    >Next</Link
                >
            </nav>
        </section>
        <Dialog v-model:open="dialogOpen"
            ><DialogContent
                ><DialogHeader
                    ><DialogTitle>Add details to call #{{ selected?.id }}</DialogTitle
                    ><DialogDescription
                        >Confirm the call you are documenting. Its case will be reused if one
                        already exists.</DialogDescription
                    ></DialogHeader
                >
                <form class="space-y-4" @submit.prevent="form.post(store.url())">
                    <p class="rounded-lg bg-muted p-3 text-sm">
                        {{ selected?.caller_number ?? "Caller number not supplied" }}<br />{{
                            selected?.correlation_key
                        }}
                        · {{ formatClock(selected?.started_at ?? null) }}
                    </p>
                    <fieldset :disabled="form.processing" class="space-y-4">
                        <label class="flex items-start gap-3 text-sm"
                            ><input
                                v-model="form.association_confirmed"
                                type="checkbox"
                                required
                                class="mt-0.5 size-4"
                            />This is the call I am documenting.</label
                        ><template v-if="selected?.ambiguity.requires_confirmation"
                            ><ul
                                class="space-y-1 rounded-lg bg-amber-50 p-3 text-sm text-amber-950 dark:bg-amber-950 dark:text-amber-100"
                            >
                                <li v-for="reason in selected.ambiguity.reasons" :key="reason">
                                    {{ ambiguityLabels[reason] ?? reason }}
                                </li>
                            </ul>
                            <label class="flex items-start gap-3 text-sm"
                                ><input
                                    v-model="form.ambiguity_acknowledged"
                                    type="checkbox"
                                    required
                                    class="mt-0.5 size-4"
                                />I checked the call reference and arrival time.</label
                            ></template
                        >
                        <p
                            v-for="(error, key) in form.errors"
                            :key="key"
                            role="alert"
                            class="text-sm text-red-600"
                        >
                            {{ error }}
                        </p>
                        <button
                            class="min-h-11 w-full rounded-lg bg-primary px-4 py-2 font-medium text-primary-foreground"
                        >
                            {{ form.processing ? "Opening…" : "Open case form" }}
                        </button>
                    </fieldset>
                </form>
            </DialogContent></Dialog
        >
    </div>
</template>
