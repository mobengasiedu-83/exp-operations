<script setup lang="ts">
import { Head, Link, router, usePage, usePoll } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import {
    Activity,
    ArrowLeft,
    ClipboardList,
    Info,
    Link2,
    Pause,
    PhoneOutgoing,
    Play,
    Timer,
    TriangleAlert,
} from '@lucide/vue';
import AdminTable from '@/components/AdminTable.vue';
import PageHero from '@/components/exp/PageHero.vue';
import StatTile from '@/components/exp/StatTile.vue';
import { Button } from '@/components/ui/button';
import { index, reconcile } from '@/routes/calls';

type Page = {
    data: Record<string, unknown>[];
    prev_page_url: string | null;
    next_page_url: string | null;
};
type Answer = {
    screening_question_id?: number;
    question_id?: number;
    question_prompt?: string | null;
    answer: string | null;
    answer_status: string;
    question_version: number | string | null;
    saved_at: string | null;
    recorded_by: number | null;
};
type Attempt = {
    id: number;
    sequence: number | null;
    dentist: string | null;
    destination_masked: string | null;
    status: string;
    status_label: string;
    human_outcome: string | null;
    human_outcome_label: string | null;
    acceptance_confidence: string | null;
    connected: boolean;
    unverified: boolean;
    retry_of_id: number | null;
    dial_started_at: string | null;
    ringing_at: string | null;
    answered_at: string | null;
    human_accepted_at: string | null;
    transfer_completed_at: string | null;
    ended_at: string | null;
    failure_reason: string | null;
};
type CaseRecord = {
    id: number;
    status: string;
    priority: string | null;
    disposition: string | null;
    concern: string | null;
    next_step: string | null;
    screenings: {
        id: number;
        screening_protocol_id: number;
        priority: string | null;
        recorded_by: number;
        completed_at: string | null;
        answers: Answer[];
    }[];
    screening_drafts: {
        id: number;
        screening_protocol_id: number;
        answers: Answer[];
    }[];
    consents: {
        status: string;
        screening_protocol_id: number;
        callback_allowed: boolean;
        recorded_by: number;
        recorded_at: string | null;
    }[];
    notes: { body: string; recorded_by: number; created_at: string | null }[];
};

const props = defineProps<{
    interaction: {
        live_status?: string;
        id: number;
        correlation_key: string;
        status: string;
        timing_quality: string;
        reconciliation_status: string;
        started_at: string | null;
        ended_at: string | null;
        caller_number: string | null;
        inbound_line: string | null;
        dentist_number: string | null;
    };
    record: {
        caller_name: string | null;
        agent: string | null;
        connected_dentist: string;
        connected_destination: string | null;
        connection_state: 'verified' | 'unverified' | 'none';
        connection_label: string;
        unverified_dentist: string | null;
        attempted_dentists: string[];
        attempts_count: number;
        outcome: string;
        outcome_label: string;
        transfer_completed_at: string | null;
        duration_seconds: number | null;
        answered_questions: number;
        question_count: number;
        case_record: CaseRecord | null;
    };
    durations: Record<string, number | null>;
    authors: Record<string, string>;
    audit: {
        description: string;
        log_name: string;
        causer: string | null;
        at: string | null;
    }[];
    filters: Record<string, string>;
    protocols: {
        id: number;
        version: string;
        title: string;
        questions: { id: number; prompt: string }[];
    }[];
    attempts: Attempt[];
    timeline: {
        at: string | null;
        stage: string;
        label: string;
        detail: string | null;
        source: string;
        confidence: string;
        confidence_label: string;
    }[];
    telemetry: {
        freshness: { state: string; heartbeat_age_seconds: number | null };
        gaps: Record<string, unknown>[];
        unprocessed_events: number;
    };
    legs: Page;
    events: Page;
    bridges: Page;
    metrics: Page;
    exceptions: Page;
}>();

const page = usePage();
const polling = ref(true);
const pollFailed = ref(false);
const { start, stop } = usePoll(3000, {
    only: [
        'interaction',
        'record',
        'attempts',
        'timeline',
        'durations',
        'audit',
        'legs',
        'events',
        'bridges',
        'metrics',
        'exceptions',
        'telemetry',
    ],
    onSuccess: () => {
        pollFailed.value = false;
    },
    onError: () => {
        pollFailed.value = true;
    },
    onNetworkError: () => {
        pollFailed.value = true;
    },
});
function togglePolling() {
    polling.value = !polling.value;
    if (polling.value) start();
    else stop();
}
const durationFields = [
    { key: 'inbound_wait_seconds', label: 'Inbound wait' },
    { key: 'agent_talk_seconds', label: 'Agent talk' },
    { key: 'hold_seconds', label: 'Hold / transfer' },
    { key: 'dial_to_answer_seconds', label: 'Dial to answer' },
    { key: 'ringing_seconds', label: 'Dentist ringing' },
    { key: 'private_handover_seconds', label: 'Private briefing' },
    { key: 'dentist_connected_seconds', label: 'Dentist connected' },
    { key: 'verified_bridge_seconds', label: 'Verified caller–dentist bridge' },
    { key: 'total_seconds', label: 'Total call' },
] as const;
const latestConsent = computed(() => {
    const consents = [...(props.record.case_record?.consents ?? [])];
    return (
        consents
            .sort((left, right) =>
                String(left.recorded_at).localeCompare(
                    String(right.recorded_at),
                ),
            )
            .at(-1) ?? null
    );
});
function question(protocolId: number, answer: Answer) {
    if (
        typeof answer.question_prompt === 'string' &&
        answer.question_prompt.trim() !== ''
    ) {
        return answer.question_prompt;
    }
    return (
        props.protocols
            .find((p) => p.id === protocolId)
            ?.questions.find(
                (q) =>
                    q.id ===
                    (answer.screening_question_id ?? answer.question_id),
            )?.prompt ?? 'Question text unavailable'
    );
}
function protocolTitle(id: number) {
    const protocol = props.protocols.find((p) => p.id === id);
    return protocol
        ? `${protocol.title} (${protocol.version})`
        : 'Version unavailable';
}
function authorName(id: number | null) {
    if (id === null) return 'Unknown';
    return props.authors[String(id)] ?? `User #${id}`;
}
function formatDuration(seconds: number | null | undefined) {
    if (seconds === null || seconds === undefined) return 'Not available';
    const total = Math.round(seconds);
    return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
}
function formatTimestamp(value: string | null) {
    return value === null ? '—' : value.replace('T', ' ').slice(0, 19);
}
function answerLabel(answer: Answer) {
    return answer.answer_status === 'answered' && answer.answer
        ? answer.answer
        : answer.answer_status.replaceAll('_', ' ');
}
const connectionClasses: Record<string, string> = {
    verified:
        'bg-emerald-500/10 text-emerald-700 ring-emerald-500/25 dark:text-emerald-300',
    unverified:
        'bg-amber-500/10 text-amber-700 ring-amber-500/25 dark:text-amber-300',
    none: 'bg-slate-500/10 text-slate-600 ring-slate-500/20 dark:text-slate-300',
};

const stateClasses: Record<string, string> = {
    completed:
        'bg-emerald-500/10 text-emerald-700 ring-emerald-500/25 dark:text-emerald-300',
    active: 'bg-sky-500/10 text-sky-700 ring-sky-500/25 dark:text-sky-300',
    ringing: 'bg-sky-500/10 text-sky-700 ring-sky-500/25 dark:text-sky-300',
    answered: 'bg-sky-500/10 text-sky-700 ring-sky-500/25 dark:text-sky-300',
    failed: 'bg-rose-500/10 text-rose-700 ring-rose-500/25 dark:text-rose-300',
    abandoned:
        'bg-amber-500/10 text-amber-700 ring-amber-500/25 dark:text-amber-300',
};

const confidenceClasses: Record<string, { dot: string; chip: string }> = {
    observed: {
        dot: 'bg-emerald-500',
        chip: 'bg-emerald-500/10 text-emerald-700 ring-emerald-500/25 dark:text-emerald-300',
    },
    operator_confirmed: {
        dot: 'bg-sky-500',
        chip: 'bg-sky-500/10 text-sky-700 ring-sky-500/25 dark:text-sky-300',
    },
    network_only: {
        dot: 'bg-amber-500',
        chip: 'bg-amber-500/10 text-amber-700 ring-amber-500/25 dark:text-amber-300',
    },
    unknown: {
        dot: 'bg-rose-500',
        chip: 'bg-rose-500/10 text-rose-700 ring-rose-500/25 dark:text-rose-300',
    },
};

function connectionTone(state: string): string {
    return connectionClasses[state] ?? connectionClasses.none;
}

function stateTone(status: string): string {
    return stateClasses[status.toLowerCase()] ?? connectionClasses.none;
}

function confidenceOf(confidence: string): { dot: string; chip: string } {
    return confidenceClasses[confidence] ?? confidenceClasses.unknown;
}

const connectedDentistLabel = computed(() =>
    props.record.connection_state === 'verified'
        ? props.record.connected_dentist
        : (props.record.unverified_dentist ?? 'No connected dentist'),
);

const freshnessTone = computed(() => {
    if (pollFailed.value) {
        return 'bg-rose-500/10 text-rose-700 ring-rose-500/25 dark:text-rose-300';
    }

    if (!polling.value) {
        return 'bg-slate-500/10 text-slate-600 ring-slate-500/20 dark:text-slate-300';
    }

    return props.telemetry.freshness.state === 'live'
        ? 'bg-emerald-500/10 text-emerald-700 ring-emerald-500/25 dark:text-emerald-300'
        : 'bg-amber-500/10 text-amber-700 ring-amber-500/25 dark:text-amber-300';
});

const freshnessDot = computed(() => {
    if (pollFailed.value) {
        return 'bg-rose-500';
    }

    if (!polling.value) {
        return 'bg-slate-400';
    }

    return props.telemetry.freshness.state === 'live'
        ? 'bg-emerald-500'
        : 'bg-amber-500';
});

const freshnessLabel = computed(() => {
    if (pollFailed.value) {
        return 'refresh failed';
    }

    if (!polling.value) {
        return 'paused';
    }

    return props.telemetry.freshness.state;
});

const sections = [
    {
        key: 'legs' as const,
        title: 'Call legs',
        columns: [
            'id',
            'uniqueid',
            'channel',
            'role',
            'entry_kind',
            'caller_masked',
            'inbound_masked',
            'destination_masked',
            'dial_status',
            'started_at',
            'dial_started_at',
            'ringing_at',
            'answered_at',
            'ended_at',
        ],
    },
    {
        key: 'metrics' as const,
        title: 'Mobile attempt timings (seconds)',
        columns: [
            'call_leg_id',
            'dial_to_answer_seconds',
            'ringing_seconds',
            'mobile_connected_seconds',
            'private_handover_seconds',
            'consultation_seconds',
            'acceptance_status',
            'outbound_setup_seconds',
        ],
    },
    {
        key: 'bridges' as const,
        title: 'Bridge intervals',
        columns: [
            'bridge_uniqueid',
            'stage',
            'participants',
            'started_at',
            'ended_at',
            'duration_seconds',
        ],
    },
    {
        key: 'events' as const,
        title: 'Event timeline',
        columns: [
            'event_name',
            'occurred_at',
            'timing_quality',
            'ami_event_id',
            'data',
        ],
    },
    {
        key: 'exceptions' as const,
        title: 'Diagnostics',
        columns: ['category', 'code', 'subject', 'details'],
    },
];
function runReconciliation() {
    router.post(reconcile.url(props.interaction.id));
}
</script>
<template>
    <Head :title="`Call ${interaction.id}`" />

    <div class="min-h-full bg-slate-50/80 p-4 sm:p-6 lg:p-8 dark:bg-slate-950">
        <div class="mx-auto max-w-7xl space-y-6">
            <PageHero
                eyebrow="Call detail · reconciled evidence"
                :title="`Call #${interaction.id}`"
                :description="`${record.connection_label} · ${record.outcome_label}`"
                accent="emerald"
            >
                <template #actions>
                    <Link
                        :href="index({ query: filters })"
                        class="inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-emerald-900 shadow-sm transition hover:bg-emerald-50"
                    >
                        <ArrowLeft class="size-4" /> Back to call history
                    </Link>
                </template>
                <template #stats>
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <StatTile
                            label="Connection"
                            :value="record.connection_label"
                            :hint="connectedDentistLabel"
                            tone="onDark"
                            :icon="Link2"
                            :delay="1"
                        />
                        <StatTile
                            label="Dentist attempts"
                            :value="record.attempts_count"
                            hint="Every dial attempt is listed below"
                            tone="onDark"
                            :icon="PhoneOutgoing"
                            :delay="2"
                        />
                        <StatTile
                            label="Screening answers"
                            :value="`${record.answered_questions}/${record.question_count}`"
                            hint="Answered out of questions asked"
                            tone="onDark"
                            :icon="ClipboardList"
                            :delay="3"
                        />
                        <StatTile
                            label="Total call"
                            :value="formatDuration(record.duration_seconds)"
                            hint="Arrived to hangup"
                            tone="onDark"
                            :icon="Timer"
                            :delay="4"
                        />
                    </div>
                </template>
            </PageHero>

            <p class="flex items-start gap-2 text-xs text-muted-foreground">
                <Info class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                All timestamps are UTC. A dash or “Not available” means the PBX
                did not supply that measurement.
            </p>

            <section class="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
                <article
                    class="exp-rise exp-rise-1 overflow-hidden rounded-2xl border bg-card shadow-sm"
                >
                    <header
                        class="flex items-center gap-3 border-b bg-gradient-to-r from-emerald-50/80 to-card px-5 py-3 dark:from-emerald-950/40"
                    >
                        <span
                            class="flex size-8 shrink-0 items-center justify-center rounded-xl bg-emerald-500/10 text-sm font-semibold text-emerald-700 ring-1 ring-emerald-500/20 dark:text-emerald-300"
                            >A</span
                        >
                        <div class="min-w-0">
                            <h2 class="text-sm font-semibold">
                                Caller &amp; call
                            </h2>
                            <p class="truncate text-xs text-muted-foreground">
                                Who called, on which line, and when
                            </p>
                        </div>
                    </header>
                    <dl class="divide-y divide-dashed divide-border/70 px-5">
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-2 py-2.5 text-sm"
                        >
                            <dt class="text-muted-foreground">Call ID</dt>
                            <dd class="font-semibold tabular-nums">
                                #{{ interaction.id }}
                            </dd>
                        </div>
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-2 py-2.5 text-sm"
                        >
                            <dt class="text-muted-foreground">Caller number</dt>
                            <dd class="font-medium tabular-nums">
                                {{ interaction.caller_number ?? 'Unknown' }}
                            </dd>
                        </div>
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-2 py-2.5 text-sm"
                        >
                            <dt class="text-muted-foreground">Caller name</dt>
                            <dd class="font-medium">
                                {{
                                    record.caller_name ??
                                    'Restricted / not given'
                                }}
                            </dd>
                        </div>
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-2 py-2.5 text-sm"
                        >
                            <dt class="text-muted-foreground">Inbound line</dt>
                            <dd class="font-medium tabular-nums">
                                {{ interaction.inbound_line ?? 'Unknown' }}
                            </dd>
                        </div>
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-2 py-2.5 text-sm"
                        >
                            <dt class="text-muted-foreground">Agent</dt>
                            <dd class="font-medium">
                                {{ record.agent ?? 'Restricted' }}
                            </dd>
                        </div>
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-2 py-2.5 text-sm"
                        >
                            <dt class="text-muted-foreground">Arrived</dt>
                            <dd class="tabular-nums">
                                {{ formatTimestamp(interaction.started_at) }}
                            </dd>
                        </div>
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-2 py-2.5 text-sm"
                        >
                            <dt class="text-muted-foreground">Ended</dt>
                            <dd class="tabular-nums">
                                {{ formatTimestamp(interaction.ended_at) }}
                            </dd>
                        </div>
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-2 py-2.5 text-sm"
                        >
                            <dt class="text-muted-foreground">
                                Total duration
                            </dt>
                            <dd class="font-semibold tabular-nums">
                                {{ formatDuration(record.duration_seconds) }}
                            </dd>
                        </div>
                        <div
                            class="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm"
                        >
                            <dt class="text-muted-foreground">Call state</dt>
                            <dd>
                                <span
                                    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold tracking-wide uppercase ring-1"
                                    :class="stateTone(interaction.status)"
                                    >{{ interaction.live_status ?? interaction.status }}</span
                                >
                            </dd>
                        </div>
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-2 py-2.5 text-sm"
                        >
                            <dt class="text-muted-foreground">Consent</dt>
                            <dd
                                class="max-w-[60%] text-right font-medium break-words"
                            >
                                {{
                                    latestConsent
                                        ? `${latestConsent.status} · ${protocolTitle(latestConsent.screening_protocol_id)}`
                                        : 'Not recorded'
                                }}
                            </dd>
                        </div>
                    </dl>
                </article>
                <article
                    class="exp-rise exp-rise-2 overflow-hidden rounded-2xl border bg-card shadow-sm"
                >
                    <header
                        class="flex items-center gap-3 border-b bg-gradient-to-r from-sky-50/80 to-card px-5 py-3 dark:from-sky-950/40"
                    >
                        <span
                            class="flex size-8 shrink-0 items-center justify-center rounded-xl bg-sky-500/10 text-sm font-semibold text-sky-700 ring-1 ring-sky-500/20 dark:text-sky-300"
                            >B</span
                        >
                        <div class="min-w-0">
                            <h2 class="text-sm font-semibold">
                                Dentist &amp; transfer
                            </h2>
                            <p class="truncate text-xs text-muted-foreground">
                                Attribution and the transfer outcome
                            </p>
                        </div>
                    </header>
                    <dl class="divide-y divide-dashed divide-border/70 px-5">
                        <div
                            class="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm"
                        >
                            <dt class="text-muted-foreground">Connection</dt>
                            <dd>
                                <span
                                    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1"
                                    :class="
                                        connectionTone(record.connection_state)
                                    "
                                    >{{ record.connection_label }}</span
                                >
                            </dd>
                        </div>
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-2 py-2.5 text-sm"
                        >
                            <dt class="text-muted-foreground">
                                Final verified dentist
                            </dt>
                            <dd
                                class="max-w-[60%] text-right font-medium break-words"
                            >
                                {{ connectedDentistLabel }}
                            </dd>
                        </div>
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-2 py-2.5 text-sm"
                        >
                            <dt class="text-muted-foreground">
                                Destination (masked)
                            </dt>
                            <dd class="font-medium tabular-nums">
                                {{
                                    record.connected_destination ??
                                    interaction.dentist_number ??
                                    '—'
                                }}
                            </dd>
                        </div>
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-2 py-2.5 text-sm"
                        >
                            <dt class="text-muted-foreground">Attempts</dt>
                            <dd class="font-semibold tabular-nums">
                                {{ record.attempts_count }}
                            </dd>
                        </div>
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-2 py-2.5 text-sm"
                        >
                            <dt class="text-muted-foreground">
                                Attempted dentists
                            </dt>
                            <dd
                                class="max-w-[60%] text-right font-medium break-words"
                            >
                                {{
                                    record.attempted_dentists.join(', ') ||
                                    'None recorded'
                                }}
                            </dd>
                        </div>
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-2 py-2.5 text-sm"
                        >
                            <dt class="text-muted-foreground">Transferred</dt>
                            <dd class="tabular-nums">
                                {{
                                    formatTimestamp(
                                        record.transfer_completed_at,
                                    )
                                }}
                            </dd>
                        </div>
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-2 py-2.5 text-sm"
                        >
                            <dt class="text-muted-foreground">Outcome</dt>
                            <dd
                                class="max-w-[60%] text-right font-medium break-words"
                            >
                                {{ record.outcome_label }}
                            </dd>
                        </div>
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-2 py-2.5 text-sm"
                        >
                            <dt class="text-muted-foreground">Answered</dt>
                            <dd class="font-medium tabular-nums">
                                {{ record.answered_questions }}/{{
                                    record.question_count
                                }}
                                questions
                            </dd>
                        </div>
                    </dl>
                </article>
                <article
                    class="exp-rise exp-rise-3 overflow-hidden rounded-2xl border bg-card shadow-sm lg:col-span-2 xl:col-span-1"
                >
                    <header
                        class="flex items-center gap-3 border-b bg-gradient-to-r from-violet-50/80 to-card px-5 py-3 dark:from-violet-950/40"
                    >
                        <span
                            class="flex size-8 shrink-0 items-center justify-center rounded-xl bg-violet-500/10 text-sm font-semibold text-violet-700 ring-1 ring-violet-500/20 dark:text-violet-300"
                            >F</span
                        >
                        <div class="min-w-0">
                            <h2 class="text-sm font-semibold">Durations</h2>
                            <p class="truncate text-xs text-muted-foreground">
                                Measured from retained PBX evidence
                            </p>
                        </div>
                    </header>
                    <dl class="divide-y divide-dashed divide-border/70 px-5">
                        <div
                            v-for="field in durationFields"
                            :key="field.key"
                            class="flex items-baseline justify-between gap-3 py-2.5 text-sm"
                            :class="
                                field.key === 'total_seconds'
                                    ? 'font-semibold'
                                    : ''
                            "
                        >
                            <dt
                                :class="
                                    field.key === 'total_seconds'
                                        ? ''
                                        : 'text-muted-foreground'
                                "
                            >
                                {{ field.label }}
                            </dt>
                            <dd class="tabular-nums">
                                {{ formatDuration(durations[field.key]) }}
                            </dd>
                        </div>
                    </dl>
                </article>
            </section>

            <section
                v-if="record.case_record"
                class="overflow-hidden rounded-2xl border bg-card shadow-sm"
            >
                <header
                    class="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4"
                >
                    <div class="flex items-center gap-3">
                        <span
                            class="flex size-8 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-sm font-semibold text-primary ring-1 ring-primary/20"
                            >C</span
                        >
                        <div>
                            <h2 class="text-sm font-semibold">
                                Questionnaire &amp; answers
                            </h2>
                            <p class="text-xs text-muted-foreground">
                                {{ record.answered_questions }}/{{
                                    record.question_count
                                }}
                                questions answered with saved evidence
                            </p>
                        </div>
                    </div>
                    <span
                        v-if="latestConsent"
                        class="inline-flex items-center rounded-full bg-slate-500/10 px-2.5 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-500/20 dark:text-slate-300"
                    >
                        Consent {{ latestConsent.status }} · callback
                        {{
                            latestConsent.callback_allowed
                                ? 'allowed'
                                : 'not allowed'
                        }}
                    </span>
                </header>
                <div class="space-y-4 p-5">
                    <p
                        v-if="latestConsent"
                        class="rounded-xl bg-muted/60 px-4 py-3 text-xs text-muted-foreground"
                    >
                        Consent recorded
                        {{ formatTimestamp(latestConsent.recorded_at) }} by
                        {{ authorName(latestConsent.recorded_by) }}.
                    </p>
                    <template
                        v-for="screening in record.case_record.screenings"
                        :key="screening.id"
                    >
                        <article
                            v-if="screening.answers.length > 0"
                            class="overflow-hidden rounded-2xl border bg-background"
                        >
                            <header
                                class="flex flex-wrap items-baseline justify-between gap-2 border-b bg-muted/40 px-4 py-3"
                            >
                                <h3 class="text-sm font-semibold">
                                    {{
                                        protocolTitle(
                                            screening.screening_protocol_id,
                                        )
                                    }}
                                </h3>
                                <p class="text-xs text-muted-foreground">
                                    Priority {{ screening.priority ?? '—' }} ·
                                    {{ authorName(screening.recorded_by) }} ·
                                    completed
                                    {{
                                        formatTimestamp(screening.completed_at)
                                    }}
                                </p>
                            </header>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-sm">
                                    <thead
                                        class="bg-muted/20 text-xs tracking-wide text-muted-foreground uppercase"
                                    >
                                        <tr>
                                            <th class="px-3 py-2 font-semibold">
                                                #
                                            </th>
                                            <th class="px-3 py-2 font-semibold">
                                                Question as asked
                                            </th>
                                            <th class="px-3 py-2 font-semibold">
                                                Saved answer
                                            </th>
                                            <th class="px-3 py-2 font-semibold">
                                                Status
                                            </th>
                                            <th class="px-3 py-2 font-semibold">
                                                Version
                                            </th>
                                            <th class="px-3 py-2 font-semibold">
                                                Saved at
                                            </th>
                                            <th class="px-3 py-2 font-semibold">
                                                Author
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y">
                                        <tr
                                            v-for="(
                                                answer, index
                                            ) in screening.answers"
                                            :key="index"
                                            class="align-top"
                                        >
                                            <td
                                                class="px-3 py-2 text-muted-foreground tabular-nums"
                                            >
                                                {{ index + 1 }}
                                            </td>
                                            <td class="px-3 py-2 font-medium">
                                                {{
                                                    question(
                                                        screening.screening_protocol_id,
                                                        answer,
                                                    )
                                                }}
                                            </td>
                                            <td class="px-3 py-2">
                                                {{ answerLabel(answer) }}
                                            </td>
                                            <td
                                                class="px-3 py-2 text-muted-foreground"
                                            >
                                                {{ answer.answer_status }}
                                            </td>
                                            <td
                                                class="px-3 py-2 text-muted-foreground tabular-nums"
                                            >
                                                {{
                                                    answer.question_version ??
                                                    '—'
                                                }}
                                            </td>
                                            <td
                                                class="px-3 py-2 text-muted-foreground tabular-nums"
                                            >
                                                {{
                                                    formatTimestamp(
                                                        answer.saved_at,
                                                    )
                                                }}
                                            </td>
                                            <td
                                                class="px-3 py-2 text-muted-foreground"
                                            >
                                                {{
                                                    authorName(
                                                        answer.recorded_by,
                                                    )
                                                }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </article>
                    </template>
                    <article
                        v-for="draft in record.case_record.screening_drafts"
                        :key="`draft-${draft.id}`"
                        class="rounded-2xl border border-dashed bg-amber-50/40 p-4 dark:bg-amber-950/20"
                    >
                        <h3
                            class="flex items-center gap-2 text-sm font-semibold text-amber-900 dark:text-amber-200"
                        >
                            <TriangleAlert class="size-4" /> Unconfirmed draft ·
                            {{ protocolTitle(draft.screening_protocol_id) }}
                        </h3>
                        <p
                            class="mt-1 text-xs text-amber-800/80 dark:text-amber-200/80"
                        >
                            A draft was never submitted, so it is not part of
                            the call record.
                        </p>
                        <ul class="mt-2 space-y-1 text-sm">
                            <li
                                v-for="(answer, index) in draft.answers"
                                :key="index"
                                class="text-muted-foreground"
                            >
                                <span class="font-medium text-foreground">{{
                                    question(
                                        draft.screening_protocol_id,
                                        answer,
                                    )
                                }}</span
                                >: {{ answerLabel(answer) }} ·
                                {{ answer.answer_status }} ·
                                {{ formatTimestamp(answer.saved_at) }} ·
                                {{ authorName(answer.recorded_by) }}
                            </li>
                        </ul>
                    </article>
                </div>
            </section>
            <section
                v-else
                class="rounded-2xl border border-dashed bg-card p-6 text-sm text-muted-foreground"
            >
                No case record available for your permissions.
            </section>

            <section
                class="overflow-hidden rounded-2xl border bg-card shadow-sm"
                aria-live="polite"
            >
                <header
                    class="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4"
                >
                    <div class="flex items-center gap-3">
                        <span
                            class="flex size-8 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary ring-1 ring-primary/20"
                        >
                            <Activity class="size-4" />
                        </span>
                        <div>
                            <h2 class="text-sm font-semibold">
                                Evidence freshness
                            </h2>
                            <p class="text-xs text-muted-foreground">
                                Heartbeat age:
                                {{
                                    telemetry.freshness.heartbeat_age_seconds ??
                                    'Unknown'
                                }}
                                seconds
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold capitalize ring-1"
                            :class="freshnessTone"
                        >
                            <span
                                class="size-1.5 rounded-full"
                                :class="[
                                    freshnessDot,
                                    polling && !pollFailed
                                        ? 'animate-pulse'
                                        : '',
                                ]"
                            ></span>
                            AMI evidence {{ freshnessLabel }}
                        </span>
                        <Button
                            variant="outline"
                            size="sm"
                            @click="togglePolling"
                        >
                            <Pause v-if="polling" class="size-3.5" />
                            <Play v-else class="size-3.5" />
                            {{ polling ? 'Pause' : 'Resume' }} refresh
                        </Button>
                    </div>
                </header>
                <div class="space-y-3 p-5">
                    <div
                        v-if="
                            telemetry.freshness.state !== 'live' ||
                            !polling ||
                            pollFailed
                        "
                        class="flex items-start gap-2 rounded-xl bg-amber-500/10 px-4 py-3 text-xs font-medium text-amber-800 ring-1 ring-amber-500/20 dark:text-amber-200"
                    >
                        <TriangleAlert class="mt-0.5 size-4 shrink-0" />
                        <p>
                            Live state is unverified.
                            {{
                                pollFailed
                                    ? 'Refresh failed; showing retained evidence.'
                                    : polling
                                      ? 'Telemetry is stale or offline.'
                                      : 'Automatic refresh is paused.'
                            }}
                        </p>
                    </div>
                    <div
                        v-if="telemetry.unprocessed_events > 0"
                        class="rounded-xl bg-sky-500/10 px-4 py-3 text-xs font-medium text-sky-800 ring-1 ring-sky-500/20 dark:text-sky-200"
                    >
                        {{ telemetry.unprocessed_events }} source events require
                        correlation review or replay. Connection evidence is
                        incomplete.
                    </div>
                    <div v-if="telemetry.gaps.length > 0" class="space-y-3">
                        <p class="text-xs text-muted-foreground">
                            This call overlaps a listener outage. Observed
                            timestamps are retained; uninterrupted durations
                            cannot be verified.
                        </p>
                        <AdminTable
                            :rows="telemetry.gaps"
                            :columns="
                                ['started_at', 'ended_at', 'reason'].map(
                                    (key) => ({
                                        key,
                                        label: key.replaceAll('_', ' '),
                                    }),
                                )
                            "
                        />
                    </div>
                    <p
                        v-if="
                            telemetry.freshness.state === 'live' &&
                            polling &&
                            !pollFailed &&
                            telemetry.unprocessed_events === 0 &&
                            telemetry.gaps.length === 0
                        "
                        class="text-xs text-muted-foreground"
                    >
                        AMI telemetry is live and fully correlated for this
                        call.
                    </p>
                </div>
            </section>

            <section
                class="overflow-hidden rounded-2xl border bg-card shadow-sm"
            >
                <header
                    class="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4"
                >
                    <div class="flex items-center gap-3">
                        <span
                            class="flex size-8 shrink-0 items-center justify-center rounded-xl bg-amber-500/10 text-sm font-semibold text-amber-700 ring-1 ring-amber-500/20 dark:text-amber-300"
                            >D</span
                        >
                        <div>
                            <h2 class="text-sm font-semibold">
                                Transfer attempts
                            </h2>
                            <p class="text-xs text-muted-foreground">
                                Every dentist dialled, in order
                            </p>
                        </div>
                    </div>
                    <span
                        class="rounded-full bg-muted px-2.5 py-1 text-xs font-semibold text-muted-foreground tabular-nums"
                        >{{ attempts.length }} attempt{{
                            attempts.length === 1 ? '' : 's'
                        }}</span
                    >
                </header>
                <p
                    class="border-b bg-muted/30 px-5 py-2.5 text-xs text-muted-foreground"
                >
                    A verified connection needs an explicit acceptance and a
                    confirmed bridge; a network answer alone is never shown as a
                    consultation.
                </p>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead
                            class="bg-muted/20 text-xs tracking-wide text-muted-foreground uppercase"
                        >
                            <tr>
                                <th class="px-3 py-2 font-semibold">Order</th>
                                <th class="px-3 py-2 font-semibold">Dentist</th>
                                <th class="px-3 py-2 font-semibold">
                                    Destination
                                </th>
                                <th class="px-3 py-2 font-semibold">Result</th>
                                <th class="px-3 py-2 font-semibold">
                                    Operator outcome
                                </th>
                                <th class="px-3 py-2 font-semibold">Dialled</th>
                                <th class="px-3 py-2 font-semibold">
                                    Answered
                                </th>
                                <th class="px-3 py-2 font-semibold">
                                    Accepted
                                </th>
                                <th class="px-3 py-2 font-semibold">Bridged</th>
                                <th class="px-3 py-2 font-semibold">
                                    Failure reason
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="attempt in attempts"
                                :key="attempt.id"
                                class="align-top"
                            >
                                <td
                                    class="px-3 py-3 text-muted-foreground tabular-nums"
                                >
                                    {{ attempt.sequence ?? '—' }}
                                </td>
                                <td class="px-3 py-3">
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <span class="font-medium">{{
                                            attempt.dentist ??
                                            'Unmatched number'
                                        }}</span>
                                        <span
                                            v-if="attempt.connected"
                                            class="inline-flex items-center rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-500/25 dark:text-emerald-300"
                                            >Connected</span
                                        >
                                        <span
                                            v-else-if="attempt.unverified"
                                            class="inline-flex items-center rounded-full bg-amber-500/10 px-2 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-amber-500/25 dark:text-amber-300"
                                            >Unverified</span
                                        >
                                    </div>
                                    <p
                                        v-if="attempt.retry_of_id"
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        Retry of attempt #{{
                                            attempt.retry_of_id
                                        }}
                                    </p>
                                </td>
                                <td
                                    class="px-3 py-3 text-muted-foreground tabular-nums"
                                >
                                    {{ attempt.destination_masked ?? '—' }}
                                </td>
                                <td class="px-3 py-3">
                                    <p class="font-medium">
                                        {{ attempt.status_label }}
                                    </p>
                                    <p
                                        class="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground"
                                    >
                                        <span
                                            class="size-1.5 rounded-full"
                                            :class="
                                                confidenceOf(
                                                    attempt.acceptance_confidence ??
                                                        'unknown',
                                                ).dot
                                            "
                                        ></span>
                                        confidence
                                        {{
                                            attempt.acceptance_confidence ??
                                            'unknown'
                                        }}
                                    </p>
                                </td>
                                <td class="px-3 py-3 text-muted-foreground">
                                    {{
                                        attempt.human_outcome_label ??
                                        'Not recorded'
                                    }}
                                </td>
                                <td
                                    class="px-3 py-3 text-muted-foreground tabular-nums"
                                >
                                    {{
                                        formatTimestamp(attempt.dial_started_at)
                                    }}
                                </td>
                                <td
                                    class="px-3 py-3 text-muted-foreground tabular-nums"
                                >
                                    {{ formatTimestamp(attempt.answered_at) }}
                                </td>
                                <td
                                    class="px-3 py-3 text-muted-foreground tabular-nums"
                                >
                                    {{
                                        formatTimestamp(
                                            attempt.human_accepted_at,
                                        )
                                    }}
                                </td>
                                <td
                                    class="px-3 py-3 text-muted-foreground tabular-nums"
                                >
                                    {{
                                        formatTimestamp(
                                            attempt.transfer_completed_at,
                                        )
                                    }}
                                </td>
                                <td class="px-3 py-3 text-muted-foreground">
                                    {{ attempt.failure_reason ?? '—' }}
                                </td>
                            </tr>
                            <tr v-if="attempts.length === 0">
                                <td
                                    colspan="10"
                                    class="px-3 py-10 text-center text-muted-foreground"
                                >
                                    No dentist was dialled for this call.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section
                class="overflow-hidden rounded-2xl border bg-card shadow-sm"
            >
                <header
                    class="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4"
                >
                    <div class="flex items-center gap-3">
                        <span
                            class="flex size-8 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-sm font-semibold text-primary ring-1 ring-primary/20"
                            >E</span
                        >
                        <div>
                            <h2 class="text-sm font-semibold">Call timeline</h2>
                            <p class="text-xs text-muted-foreground">
                                Derived from retained PBX evidence
                            </p>
                        </div>
                    </div>
                    <span
                        class="rounded-full bg-muted px-2.5 py-1 text-xs font-semibold text-muted-foreground tabular-nums"
                        >{{ timeline.length }} event{{
                            timeline.length === 1 ? '' : 's'
                        }}</span
                    >
                </header>
                <p
                    class="border-b bg-muted/30 px-5 py-2.5 text-xs text-muted-foreground"
                >
                    Each step keeps its source and confidence: a network answer
                    is not human acceptance, and evidence that was never
                    observed is left out rather than inferred.
                </p>
                <ol
                    v-if="timeline.length > 0"
                    class="divide-y divide-border/70"
                >
                    <li
                        v-for="(entry, index) in timeline"
                        :key="index"
                        class="flex gap-3 px-5 py-3 sm:gap-4"
                    >
                        <div class="w-24 shrink-0 pt-0.5 text-right sm:w-32">
                            <p
                                class="text-xs font-semibold text-foreground/80 tabular-nums"
                            >
                                {{ formatTimestamp(entry.at) }}
                            </p>
                            <p
                                class="mt-0.5 truncate text-[11px] tracking-wide text-muted-foreground uppercase"
                                :title="entry.stage"
                            >
                                {{ entry.stage.replaceAll('_', ' ') }}
                            </p>
                        </div>
                        <div class="flex flex-col items-center">
                            <span
                                class="mt-1.5 size-2.5 shrink-0 rounded-full ring-4 ring-card"
                                :class="confidenceOf(entry.confidence).dot"
                            ></span>
                            <span
                                v-if="index < timeline.length - 1"
                                class="mt-1 w-px flex-1 bg-border"
                            ></span>
                        </div>
                        <div class="min-w-0 flex-1 pb-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm font-medium">
                                    {{ entry.label }}
                                </p>
                                <span
                                    class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1"
                                    :class="confidenceOf(entry.confidence).chip"
                                    >{{ entry.confidence_label }}</span
                                >
                            </div>
                            <p
                                v-if="entry.detail"
                                class="mt-1 text-xs text-muted-foreground"
                            >
                                {{ entry.detail }}
                            </p>
                            <p
                                class="mt-1 text-[11px] tracking-wide text-muted-foreground uppercase"
                            >
                                source · {{ entry.source }}
                            </p>
                        </div>
                    </li>
                </ol>
                <p
                    v-else
                    class="px-5 py-10 text-center text-sm text-muted-foreground"
                >
                    No ordered evidence is available for this call.
                </p>
            </section>

            <section
                v-if="record.case_record"
                class="overflow-hidden rounded-2xl border bg-card shadow-sm"
            >
                <header
                    class="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4"
                >
                    <div class="flex items-center gap-3">
                        <span
                            class="flex size-8 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-sm font-semibold text-primary ring-1 ring-primary/20"
                            >G</span
                        >
                        <div>
                            <h2 class="text-sm font-semibold">
                                Notes &amp; audit
                            </h2>
                            <p class="text-xs text-muted-foreground">
                                Operator entries and traceable changes on this
                                case
                            </p>
                        </div>
                    </div>
                    <span
                        class="rounded-full bg-muted px-2.5 py-1 text-xs font-semibold text-muted-foreground tabular-nums"
                        >{{ record.case_record.notes.length }} note{{
                            record.case_record.notes.length === 1 ? '' : 's'
                        }}</span
                    >
                </header>
                <div class="space-y-5 p-5">
                    <dl class="grid gap-3 sm:grid-cols-3">
                        <div class="rounded-xl bg-muted/50 px-4 py-3">
                            <dt
                                class="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                Disposition
                            </dt>
                            <dd class="mt-1 text-sm font-medium break-words">
                                {{
                                    record.case_record.disposition ?? 'Unknown'
                                }}
                            </dd>
                        </div>
                        <div class="rounded-xl bg-muted/50 px-4 py-3">
                            <dt
                                class="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                Concern
                            </dt>
                            <dd class="mt-1 text-sm font-medium break-words">
                                {{ record.case_record.concern ?? '—' }}
                            </dd>
                        </div>
                        <div class="rounded-xl bg-muted/50 px-4 py-3">
                            <dt
                                class="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                Next step
                            </dt>
                            <dd class="mt-1 text-sm font-medium break-words">
                                {{ record.case_record.next_step ?? '—' }}
                            </dd>
                        </div>
                    </dl>
                    <div class="space-y-2">
                        <h3 class="text-sm font-semibold">Factual notes</h3>
                        <ul
                            v-if="record.case_record.notes.length > 0"
                            class="space-y-2"
                        >
                            <li
                                v-for="(note, index) in record.case_record
                                    .notes"
                                :key="index"
                                class="rounded-xl border-l-2 border-primary/40 bg-muted/40 px-4 py-3"
                            >
                                <p class="text-sm">{{ note.body }}</p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{ authorName(note.recorded_by) }} ·
                                    {{ formatTimestamp(note.created_at) }}
                                </p>
                            </li>
                        </ul>
                        <p
                            v-else
                            class="rounded-xl border border-dashed px-4 py-6 text-center text-sm text-muted-foreground"
                        >
                            No notes were recorded.
                        </p>
                    </div>
                    <div class="space-y-2">
                        <h3 class="text-sm font-semibold">Consent history</h3>
                        <AdminTable
                            v-if="record.case_record.consents.length > 0"
                            :rows="record.case_record.consents"
                            :columns="[
                                { key: 'status', label: 'Status' },
                                {
                                    key: 'screening_protocol_id',
                                    label: 'Notice version',
                                },
                                {
                                    key: 'callback_allowed',
                                    label: 'Callback allowed',
                                },
                                { key: 'recorded_at', label: 'Recorded at' },
                                { key: 'recorded_by', label: 'Recorded by' },
                            ]"
                        />
                        <p v-else class="text-sm text-muted-foreground">
                            No consent record exists for this case.
                        </p>
                    </div>
                    <div class="space-y-2">
                        <h3 class="text-sm font-semibold">
                            Access and change audit
                        </h3>
                        <AdminTable
                            v-if="audit.length > 0"
                            :rows="audit"
                            :columns="[
                                { key: 'at', label: 'At (UTC)' },
                                { key: 'description', label: 'Activity' },
                                { key: 'log_name', label: 'Log' },
                                { key: 'causer', label: 'By' },
                            ]"
                        />
                        <p v-else class="text-sm text-muted-foreground">
                            No audit entries are visible for this record.
                        </p>
                    </div>
                </div>
            </section>
            <section
                v-else
                class="rounded-2xl border border-dashed bg-card p-6 text-sm text-muted-foreground"
            >
                Notes, consent and audit are unavailable without case permission
                on this record.
            </section>

            <details
                class="overflow-hidden rounded-2xl border bg-card shadow-sm"
            >
                <summary
                    class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-3 px-5 py-4"
                >
                    <div class="flex items-center gap-3">
                        <span
                            class="flex size-8 shrink-0 items-center justify-center rounded-xl bg-slate-500/10 text-slate-600 ring-1 ring-slate-500/20 dark:text-slate-300"
                        >
                            <Info class="size-4" />
                        </span>
                        <div>
                            <h2 class="text-sm font-semibold">
                                Raw correlation evidence
                            </h2>
                            <p class="text-xs text-muted-foreground">
                                Legs, events, bridges, metrics and exceptions
                                behind this journey
                            </p>
                        </div>
                    </div>
                    <span
                        class="rounded-full bg-muted px-2.5 py-1 text-xs font-semibold text-muted-foreground"
                        >Expand</span
                    >
                </summary>
                <div class="space-y-4 border-t px-5 py-4">
                    <div class="flex flex-wrap gap-2 text-xs">
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full bg-muted px-2.5 py-1"
                        >
                            <span class="text-muted-foreground">State</span>
                            <span class="font-semibold">{{
                                interaction.status
                            }}</span>
                        </span>
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full bg-muted px-2.5 py-1"
                        >
                            <span class="text-muted-foreground"
                                >Time basis</span
                            >
                            <span class="font-semibold">{{
                                interaction.timing_quality
                            }}</span>
                        </span>
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full bg-muted px-2.5 py-1"
                        >
                            <span class="text-muted-foreground"
                                >Reconciliation</span
                            >
                            <span class="font-semibold">{{
                                interaction.reconciliation_status
                            }}</span>
                        </span>
                        <span
                            class="inline-flex min-w-0 items-center gap-1.5 rounded-full bg-muted px-2.5 py-1"
                        >
                            <span class="text-muted-foreground"
                                >Correlation ID</span
                            >
                            <span
                                class="truncate font-mono text-[11px] font-semibold"
                                >{{ interaction.correlation_key }}</span
                            >
                        </span>
                    </div>
                    <Button
                        v-if="
                            page.props.auth.permissions.includes(
                                'edit settings',
                            ) &&
                            page.props.auth.permissions.includes('view pbx')
                        "
                        variant="outline"
                        size="sm"
                        @click="runReconciliation"
                    >
                        Reconcile CDR/CEL
                    </Button>
                    <section
                        v-for="section in sections"
                        :id="section.key"
                        :key="section.key"
                        class="space-y-2"
                    >
                        <h3 class="text-sm font-semibold">
                            {{ section.title }}
                        </h3>
                        <AdminTable
                            :rows="props[section.key].data"
                            :columns="
                                section.columns.map((key) => ({
                                    key,
                                    label: key.replaceAll('_', ' '),
                                }))
                            "
                            :previous="props[section.key].prev_page_url"
                            :next="props[section.key].next_page_url"
                        />
                    </section>
                </div>
            </details>
        </div>
    </div>
</template>
