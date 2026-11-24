<script setup lang="ts">
import { computed, watch } from "vue";
import { Head, Link, useForm, usePoll } from "@inertiajs/vue3";
import AppLayout from "@/layouts/AppLayout.vue";
import { draft as draftRoute, index, update } from "@/routes/workspace";
import { formatClock, freshnessClasses } from "@/lib/live-operations";
import { screeningSummary, summaryQuestions } from "@/lib/live-screening";
import { draftStorageKey } from "@/lib/screening-draft";
import { useScreeningDraft, type ScreeningDraft } from "@/composables/useScreeningDraft";

defineOptions({ layout: AppLayout });
type Question = {
    id: number;
    prompt: string;
    type: string;
    options: string[] | null;
    required: boolean;
    help_text: string | null;
    safety_rule: string | null;
    conditional_question_id: number | null;
    conditional_answer: string | null;
    positive_priority: string;
    position: number;
};
type SelectableDentist = {
    id: number;
    name: string;
    availability_status: "available" | "busy" | "unavailable";
    availability_until: string | null;
    numbers: {
        id: number;
        masked: string;
        label: string | null;
        is_primary: boolean;
    }[];
};
type Protocol = {
    id: number;
    title: string;
    version: string;
    privacy_notice: string;
    allow_guardian: boolean;
    guidance: Record<string, string>;
    faq_answer: string;
    questions: Question[];
};
type Answer = {
    screening_question_id: number;
    question_prompt?: string | null;
    answer: string;
    answer_status?: string;
    question_version?: string | null;
    saved_at?: string | null;
    recorded_by?: number | null;
};
type Screening = {
    id: number;
    screening_protocol_id: number;
    recorded_by: number;
    priority: string;
    complete: boolean;
    completed_at: string;
    answers: Answer[];
};
type CallContext = {
    generated_at: string;
    agent_extension: string;
    connection: {
        id: number;
        name: string;
        status: string;
        last_event_at: string | null;
        last_heartbeat_at: string | null;
        last_error: string | null;
    } | null;
    freshness: {
        state: string;
        heartbeat_age_seconds: number | null;
        last_event_age_seconds: number | null;
        stale_after_seconds: number;
    };
    interaction: {
        id: number;
        correlation_key: string;
        status: string;
        is_exp: boolean;
        started_at: string | null;
        ended_at: string | null;
        answered_at: string | null;
        ringing_at: string | null;
        duration_seconds: number | null;
        ringing_for_seconds: number | null;
        waiting_for_seconds: number | null;
        stage: string;
        caller_number: string | null;
        inbound_did: string | null;
        agent_extension: string;
        agent_channel: string | null;
        timing_quality: string;
        exceptions: number;
        case_id: number | null;
        has_case: boolean;
        ambiguity: { requires_confirmation: boolean; reasons: string[] };
    } | null;
};
type Case = {
    id: number;
    interaction_id: number;
    status: string;
    priority: string | null;
    revision: number;
    preferred_name: string | null;
    callback_number: string | null;
    age_band: string;
    guardian_name: string | null;
    guardian_relationship: string | null;
    guardian_confirmed: boolean;
    concern_type: string;
    contact_kind: string;
    contact_kind_confirmed_at: string | null;
    concern: string | null;
    onset: string | null;
    impact: string | null;
    next_step: string | null;
    disposition: string | null;
    association_verified_at: string;
    association_verified_by: number;
    assigned_to: number;
};
type Lifecycle = {
    arrived_at: string | null;
    selected_agent_at: string | null;
    agent_answered_at: string | null;
    ended_at: string | null;
    status: string;
    disposition: string | null;
    attempts: {
        id: number; sequence: number; dentist: string; destination: string | null;
        dialled_at: string | null; ringing_at: string | null; answered_at: string | null;
        human_accepted_at: string | null; transferred_at: string | null; ended_at: string | null;
        status: string; outcome: string; dial_status: string | null; failure_reason: string | null;
    }[];
};
const props = defineProps<{
    callerCase: Case;
    interaction: { correlation_key: string; status: string } | null;
    callContext: CallContext;
    lifecycle: Lifecycle;
    protocol: Protocol | null;
    historyProtocols: Protocol[];
    draft: ScreeningDraft | null;
    screenings: Screening[];
    notes: {
        id: number;
        body: string;
        recorded_by: number;
        created_at: string;
    }[];
    dispositions: { code: string; label: string; priorities: string[] }[];
    contactKinds: Record<string, string>;
    canEdit: boolean;
    canReclassify: boolean;
    canReview: boolean;
    canControl: boolean;
    selectableDentists: {
        as_of: string;
        dentists: SelectableDentist[];
    } | null;
}>();
const profile = useForm({
    preferred_name: props.callerCase.preferred_name ?? "",
    callback_number: props.callerCase.callback_number ?? "",
    age_band: props.callerCase.age_band,
    guardian_name: props.callerCase.guardian_name ?? "",
    guardian_relationship: props.callerCase.guardian_relationship ?? "",
    guardian_confirmed: props.callerCase.guardian_confirmed,
    concern_type: props.callerCase.concern_type,
    concern: props.callerCase.concern ?? "",
    onset: props.callerCase.onset ?? "",
    impact: props.callerCase.impact ?? "",
});
function storedAnswer(questionId: number): string {
    const draftAnswers =
        props.draft?.screening_protocol_id === props.protocol?.id
            ? (props.draft?.answers ?? [])
            : [];
    const fromDraft = draftAnswers.find((a) => a.question_id === questionId);

    if (fromDraft) {
        return fromDraft.answer;
    }

    const recorded = props.screenings.find(
        (screening) => screening.screening_protocol_id === props.protocol?.id,
    );

    return (
        recorded?.answers.find((answer) => answer.screening_question_id === questionId)?.answer ??
        ""
    );
}
const safety = useForm({
    priority: props.draft?.priority ?? props.callerCase.priority ?? "P3",
    answers: (props.protocol?.questions ?? []).map((q) => ({
        question_id: q.id,
        answer: storedAnswer(q.id),
    })),
    review_reason: "",
});
watch(
    () => props.protocol?.id,
    () => {
        safety.answers = (props.protocol?.questions ?? []).map((question) => ({
            question_id: question.id,
            answer: "",
        }));
        safety.clearErrors();
    },
);
const classification = useForm({
    contact_kind: props.callerCase.contact_kind_confirmed_at ? props.callerCase.contact_kind : "",
    note: "",
});
const isClassified = computed(() => props.callerCase.contact_kind_confirmed_at !== null);
const isGenuine = computed(
    () => isClassified.value && props.callerCase.contact_kind === "genuine",
);
const showsQuestionnaire = computed(() => classification.contact_kind === "genuine");
const endsCall = computed(
    () => classification.contact_kind !== "" && classification.contact_kind !== "genuine",
);
const submitLabel = computed(() => {
    if (endsCall.value) {
        return "End call and close case";
    }

    return props.callerCase.status === "closed" ? "Reopen as genuine call" : "Save call type";
});
function classifyCall() {
    if (!classification.contact_kind) {
        classification.setError("contact_kind", "Choose whether this is a genuine call.");

        return;
    }

    classification
        .transform((data) => ({
            ...data,
            revision: props.callerCase.revision,
            operation: "contact_kind",
        }))
        .patch(update.url(props.callerCase.id), {
            preserveScroll: true,
            onSuccess: () => classification.reset("note"),
        });
}
const draftKey = computed(() =>
    props.protocol
        ? draftStorageKey(props.callerCase.id, props.protocol.id)
        : `exp:screening-draft:${props.callerCase.id}`,
);
const {
    label: draftLabel,
    tone: draftTone,
    message: draftMessage,
    canRetry: draftCanRetry,
    recovered: draftRecovered,
    conflict: draftConflict,
    retry: retryDraft,
    markRecorded,
    discardLocal,
} = useScreeningDraft({
    url: draftRoute.url(props.callerCase.id),
    storageKey: draftKey.value,
    enabled: () => props.canEdit && props.protocol !== null && isGenuine.value,
    payload: () => ({
        protocol_id: props.protocol?.id ?? 0,
        priority: safety.priority,
        answers: safety.answers.map((answer) => ({
            question_id: answer.question_id,
            answer: answer.answer,
        })),
    }),
    revision: () => props.draft?.revision ?? null,
    savedAt: () => props.draft?.draft_saved_at ?? null,
    savedBy: () => props.draft?.recorded_by ?? null,
    onRestore: (buffer) => {
        safety.priority = buffer.priority;
        buffer.answers.forEach((saved) => {
            const row = safety.answers.find((answer) => answer.question_id === saved.question_id);

            if (row) {
                row.answer = saved.answer;
            }
        });
    },
});
const inputClass =
    "min-h-11 w-full rounded-lg border bg-background px-3 py-2 text-base focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary";
const askedQuestions = computed(() => (props.protocol?.questions ?? []).filter(isAsked));
const answeredCount = computed(
    () => askedQuestions.value.filter((question) => answerFor(question.id).trim() !== "").length,
);
const hasSummaryQuestion = (field: keyof typeof summaryQuestions) =>
    props.protocol?.questions.some((question) => question.prompt === summaryQuestions[field]) ??
    false;
function choices(question: Question): { value: string; label: string }[] {
    if (question.type === "choice")
        return (question.options ?? []).map((value) => ({ value, label: value }));
    return [
        { value: "yes", label: "Yes" },
        { value: "no", label: "No" },
        ...(question.type === "yes_no_unknown" ? [{ value: "unknown", label: "Unknown" }] : []),
    ];
}

usePoll(5000, { only: ["callContext", "lifecycle"] }, { autoStart: props.canEdit });
function discardAndReload() {
    discardLocal();
    window.location.reload();
}
const closure = useForm({
    disposition: props.callerCase.disposition ?? "",
    next_step: props.callerCase.next_step ?? "",
    emergency_action_confirmed: false,
    supervisor_notified: false,
    faq_confirmed: false,
});
const livePriority = computed(() => {
    const positive =
        props.protocol?.questions.filter(
            (q) => isAsked(q) && answerFor(q.id).toLowerCase().startsWith("yes"),
        ) ?? [];
    if (positive.some((q) => q.positive_priority === "P1")) return "P1";
    if (positive.some((q) => q.positive_priority === "P2")) return "P2";
    return props.callerCase.priority ?? safety.priority;
});
function answerFor(questionId: number): string {
    return safety.answers.find((a) => a.question_id === questionId)?.answer ?? "";
}
function isAsked(question: Question): boolean {
    if (question.conditional_question_id === null) {
        return true;
    }

    return (
        answerFor(question.conditional_question_id).toLowerCase() ===
        (question.conditional_answer ?? "").toLowerCase()
    );
}
function saveProfile(andScreen = false) {
    profile
        .transform((data) => ({
            ...data,
            ...screeningSummary(props.protocol?.questions ?? [], safety.answers, data),
            revision: props.callerCase.revision,
            operation: "profile",
        }))
        .patch(update.url(props.callerCase.id), {
            preserveScroll: true,
            onSuccess: () => {
                if (andScreen) saveScreen();
            },
        });
}
function saveConversation() {
    safety.clearErrors();
    if (!safety.answers.some((answer) => answer.answer.trim() !== "")) {
        safety.setError("answers", "Enter the caller’s answers before saving the questionnaire.");
        return;
    }
    if (livePriority.value === "P1") {
        saveScreen();
        return;
    }
    saveProfile(true);
}
function saveScreen() {
    safety
        .transform((data) => ({
            ...data,
            answers: data.answers.filter((answer) => {
                const question = props.protocol?.questions.find(
                    (row) => row.id === answer.question_id,
                );
                const asked = question === undefined ? false : isAsked(question);

                return asked && answer.answer !== "";
            }),
            revision: props.callerCase.revision,
            operation: "screening",
            protocol_id: props.protocol?.id,
        }))
        .patch(update.url(props.callerCase.id), {
            preserveScroll: true,
            onSuccess: () => markRecorded(),
        });
}
function closeCase() {
    closure
        .transform((data) => ({
            ...data,
            revision: props.callerCase.revision,
            operation: "close",
        }))
        .patch(update.url(props.callerCase.id), { preserveScroll: true });
}
</script>

<template>
    <div class="mx-auto w-full max-w-5xl space-y-5 p-4 pb-12 md:p-6">
        <Head :title="`Case #${callerCase.id}`" />
        <header class="sticky top-0 z-20 space-y-3 border-b bg-background/95 py-3 backdrop-blur">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <Link :href="index()" class="text-sm text-muted-foreground hover:underline"
                        >← Back to workspace</Link
                    >
                    <h1 class="text-2xl font-semibold">
                        Call <span class="text-muted-foreground">#{{ callerCase.id }}</span>
                    </h1>
                </div>
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <span class="rounded-full bg-muted px-3 py-1">{{ callerCase.status }}</span>
                    <span
                        class="rounded-full px-3 py-1"
                        :class="freshnessClasses(callContext.freshness.state)"
                        >PBX {{ callContext.freshness.state }}</span
                    >
                    <span class="font-medium"
                        >{{ answeredCount }}/{{ askedQuestions.length }} answers</span
                    >
                </div>
            </div>
            <nav
                aria-label="Call form sections"
                class="flex gap-4 overflow-x-auto text-sm font-medium"
            >
                <a href="#journey" class="whitespace-nowrap py-1">Call journey</a>
                <a href="#caller" class="whitespace-nowrap py-1">1. Caller</a>
                <a href="#call-type" class="whitespace-nowrap py-1">2. Call type</a>
                <a href="#safety" class="whitespace-nowrap py-1">3. Questions</a>
                <a v-if="showsQuestionnaire" href="#handover" class="whitespace-nowrap py-1"
                    >4. Wrap up</a
                >
            </nav>
        </header>

        <p
            v-if="
                callContext.freshness.state !== 'live' ||
                (callContext.interaction?.exceptions ?? 0) > 0
            "
            role="alert"
            class="rounded-xl border border-amber-400 bg-amber-50 p-3 text-sm text-amber-950 dark:bg-amber-950 dark:text-amber-100"
        >
            Call evidence needs checking. Confirm the caller in Jitsi; the displayed call state may
            be incomplete.
        </p>
        <aside
            v-if="protocol && showsQuestionnaire && ['P1', 'P2'].includes(livePriority)"
            role="alert"
            class="sticky top-32 z-10 space-y-2 rounded-xl border-2 border-red-600 bg-red-50 p-4 text-red-950 dark:bg-red-950 dark:text-red-100"
        >
            <h2 class="font-bold">
                {{
                    livePriority === "P1"
                        ? "Emergency — act now"
                        : "Urgent — follow the priority guidance"
                }}
            </h2>
            <p class="whitespace-pre-wrap">{{ protocol.guidance[livePriority] }}</p>
            <p v-if="livePriority === 'P1'" class="font-semibold">
                Do not wait to finish this form or for a dentist to become available.
            </p>
        </aside>
        <p v-if="callerCase.status === 'closed'" class="rounded-xl border p-4">
            This case is closed. Saved answers are read-only.
        </p>
        <p v-if="!protocol" role="alert" class="rounded-xl border border-amber-500 p-4">
            No questionnaire is available. Contact an administrator before recording a screening.
        </p>

        <section id="journey" class="scroll-mt-36 space-y-4 rounded-xl border bg-card p-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-lg font-semibold">Call journey</h2>
                <span class="text-sm text-muted-foreground">PBX state: {{ lifecycle.status }}</span>
            </div>
            <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-lg bg-muted p-3"><dt class="text-xs text-muted-foreground">Call arrived</dt><dd class="mt-1 text-sm font-medium">{{ lifecycle.arrived_at ? formatClock(lifecycle.arrived_at) : 'Not recorded' }}</dd></div>
                <div class="rounded-lg bg-muted p-3"><dt class="text-xs text-muted-foreground">Caller selected option 3</dt><dd class="mt-1 text-sm font-medium">{{ lifecycle.selected_agent_at ? formatClock(lifecycle.selected_agent_at) : 'Not recorded' }}</dd></div>
                <div class="rounded-lg bg-muted p-3"><dt class="text-xs text-muted-foreground">Agent answered</dt><dd class="mt-1 text-sm font-medium">{{ lifecycle.agent_answered_at ? formatClock(lifecycle.agent_answered_at) : 'Not recorded' }}</dd></div>
                <div class="rounded-lg bg-muted p-3"><dt class="text-xs text-muted-foreground">Final call end</dt><dd class="mt-1 text-sm font-medium">{{ lifecycle.ended_at ? formatClock(lifecycle.ended_at) : 'Not recorded' }}</dd></div>
            </dl>
            <p class="text-xs text-muted-foreground">Times come from retained PBX evidence. A network answer does not confirm human dentist acceptance. Case closure and the final phone-call end are recorded separately.</p>
            <div v-if="lifecycle.attempts.length" class="overflow-x-auto">
                <table class="w-full text-left text-sm"><thead class="border-b text-xs text-muted-foreground"><tr><th class="p-2">Dentist / attempt</th><th class="p-2">Dialled / ringing</th><th class="p-2">Answer</th><th class="p-2">Transferred</th><th class="p-2">Outcome / line end</th></tr></thead><tbody class="divide-y">
                    <tr v-for="attempt in lifecycle.attempts" :key="attempt.id">
                        <td class="p-2 align-top"><p class="font-medium">{{ attempt.dentist }}</p><p class="text-xs text-muted-foreground">Attempt {{ attempt.sequence }} · {{ attempt.destination ?? 'Number not recorded' }}</p></td>
                        <td class="p-2 align-top"><p>{{ attempt.dialled_at ? formatClock(attempt.dialled_at) : 'Not recorded' }}</p><p v-if="attempt.ringing_at" class="text-xs text-muted-foreground">Ringing {{ formatClock(attempt.ringing_at) }}</p></td>
                        <td class="p-2 align-top"><p>{{ attempt.answered_at ? formatClock(attempt.answered_at) : 'No answer time recorded' }}</p><p v-if="attempt.human_accepted_at" class="text-xs text-muted-foreground">Dentist accepted {{ formatClock(attempt.human_accepted_at) }}</p></td>
                        <td class="p-2 align-top">{{ attempt.transferred_at ? formatClock(attempt.transferred_at) : 'Not recorded' }}</td>
                        <td class="p-2 align-top"><p class="font-medium">{{ attempt.outcome }}</p><p v-if="attempt.dial_status" class="text-xs text-muted-foreground">PBX: {{ attempt.dial_status }}</p><p class="text-xs text-muted-foreground">Line end: {{ attempt.ended_at ? formatClock(attempt.ended_at) : 'Not recorded' }}</p></td>
                    </tr>
                </tbody></table>
            </div>
            <p v-else class="text-sm text-muted-foreground">No dentist transfer attempt recorded.</p>
            <p class="border-t pt-3 text-sm"><span class="font-medium">Case disposition:</span> {{ lifecycle.disposition ?? 'Not recorded yet' }}</p>
            <p v-if="isClassified" class="text-sm">
                <span class="font-medium">Call type:</span>
                {{ contactKinds[callerCase.contact_kind] }} · confirmed
                {{ formatClock(callerCase.contact_kind_confirmed_at) }}
            </p>
        </section>
        <section id="caller" class="scroll-mt-36 space-y-4 rounded-xl border bg-card p-5">
            <h2 class="text-lg font-semibold">1. Who is calling?</h2>
            <form @submit.prevent="saveProfile()">
                <fieldset
                    :disabled="!canEdit || !protocol || profile.processing || safety.processing"
                    class="grid gap-4 sm:grid-cols-2"
                >
                    <label class="grid gap-1.5 text-sm font-medium"
                        >Preferred name
                        <span class="font-normal text-muted-foreground">Optional</span
                        ><input
                            v-model="profile.preferred_name"
                            autocomplete="off"
                            maxlength="100"
                            :class="inputClass"
                    /></label>
                    <label class="grid gap-1.5 text-sm font-medium"
                        >Age group<select v-model="profile.age_band" :class="inputClass">
                            <option value="unknown">Not recorded</option>
                            <option value="adult">Adult</option>
                            <option value="child" :disabled="!protocol?.allow_guardian">
                                Child
                            </option>
                        </select></label
                    >
                    <label class="grid gap-1.5 text-sm font-medium"
                        >Callback number
                        <span class="font-normal text-muted-foreground"
                            >Optional · confirm with caller</span
                        ><input
                            v-model="profile.callback_number"
                            type="tel"
                            autocomplete="off"
                            maxlength="40"
                            :class="inputClass"
                    /></label>
                    <label class="grid gap-1.5 text-sm font-medium"
                        >Reason for calling<select
                            v-model="profile.concern_type"
                            :class="inputClass"
                        >
                            <option value="clinical">Dental concern</option>
                            <option value="service_information">Service information</option>
                        </select></label
                    >
                    <template v-if="profile.age_band === 'child'">
                        <label class="grid gap-1.5 text-sm font-medium"
                            >Guardian name<input
                                v-model="profile.guardian_name"
                                maxlength="100"
                                :class="inputClass"
                        /></label>
                        <label class="grid gap-1.5 text-sm font-medium"
                            >Relationship<input
                                v-model="profile.guardian_relationship"
                                maxlength="100"
                                :class="inputClass"
                        /></label>
                        <label class="flex items-center gap-3 sm:col-span-2"
                            ><input
                                v-model="profile.guardian_confirmed"
                                type="checkbox"
                                class="size-5"
                            />Guardian relationship confirmed using the approved process</label
                        >
                    </template>
                    <template v-for="field in ['concern', 'onset', 'impact'] as const" :key="field">
                        <label
                            v-if="!hasSummaryQuestion(field)"
                            class="grid gap-1.5 text-sm font-medium sm:col-span-2"
                            >{{
                                field === "concern"
                                    ? "Caller’s concern"
                                    : field === "onset"
                                      ? "When it started"
                                      : "Impact on daily activities"
                            }}<textarea
                                v-model="profile[field]"
                                rows="2"
                                :maxlength="
                                    field === 'onset' ? 500 : field === 'impact' ? 1000 : 2000
                                "
                                :class="inputClass"
                            ></textarea>
                        </label>
                    </template>
                    <div class="space-y-2 sm:col-span-2">
                        <p
                            v-for="(error, key) in profile.errors"
                            :key="key"
                            role="alert"
                            class="text-sm text-red-600"
                        >
                            {{ error }}
                        </p>
                        <div class="flex items-center gap-3">
                            <button class="min-h-11 rounded-lg border px-4 py-2 font-medium">
                                {{ profile.processing ? "Saving…" : "Save caller details" }}</button
                            ><span
                                v-if="profile.recentlySuccessful"
                                role="status"
                                class="text-sm text-green-700 dark:text-green-400"
                                >Caller details saved</span
                            >
                        </div>
                    </div>
                </fieldset>
            </form>
        </section>

        <section id="call-type" class="scroll-mt-36 space-y-4 rounded-xl border bg-card p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">2. Genuine or nuisance call?</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Decide this after the consent conversation. A genuine call continues to the
                        questions form; a nuisance or prank call ends here and the questions are
                        never asked.
                    </p>
                </div>
                <span v-if="isClassified" class="rounded-full bg-muted px-3 py-1 text-sm">
                    {{ contactKinds[callerCase.contact_kind] }}
                </span>
            </div>
            <template v-if="isClassified && !isGenuine">
                <p
                    role="status"
                    class="rounded-lg border border-amber-400 bg-amber-50 p-3 text-sm text-amber-950 dark:bg-amber-950 dark:text-amber-100"
                >
                    This call was classified as “{{ contactKinds[callerCase.contact_kind] }}” and the
                    case was closed as contact ended.
                    <template v-if="!screenings.length"
                        >No screening questionnaire was opened.</template
                    >
                </p>
                <p
                    v-if="callerCase.next_step"
                    class="rounded-lg bg-muted p-3 text-sm whitespace-pre-wrap"
                >
                    {{ callerCase.next_step }}
                </p>
            </template>
            <form v-if="canReclassify" @submit.prevent="classifyCall">
                <fieldset :disabled="classification.processing" class="space-y-4">
                    <div role="radiogroup" aria-label="Call type" class="grid gap-3 sm:grid-cols-2">
                        <label class="cursor-pointer">
                            <input
                                v-model="classification.contact_kind"
                                type="radio"
                                name="call-type"
                                value="genuine"
                                class="peer sr-only"
                            />
                            <span
                                class="block h-full rounded-lg border p-4 peer-checked:border-primary peer-checked:bg-primary/5 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-primary peer-disabled:cursor-not-allowed peer-disabled:opacity-60"
                            >
                                <span class="block font-medium">Genuine call</span>
                                <span class="mt-1 block text-sm text-muted-foreground"
                                    >Continue and ask the screening questions.</span
                                >
                            </span>
                        </label>
                        <label class="cursor-pointer">
                            <input
                                v-model="classification.contact_kind"
                                type="radio"
                                name="call-type"
                                value="nuisance"
                                class="peer sr-only"
                            />
                            <span
                                class="block h-full rounded-lg border p-4 peer-checked:border-red-600 peer-checked:bg-red-50 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-red-600 peer-disabled:cursor-not-allowed peer-disabled:opacity-60 dark:peer-checked:bg-red-950"
                            >
                                <span class="block font-medium">Nuisance or prank call</span>
                                <span class="mt-1 block text-sm text-muted-foreground"
                                    >End the call now. The questions form does not open.</span
                                >
                            </span>
                        </label>
                    </div>
                    <details class="rounded-lg border p-3">
                        <summary class="cursor-pointer text-sm font-medium">
                            Other non-genuine contact types
                        </summary>
                        <div
                            role="radiogroup"
                            aria-label="Other contact types"
                            class="mt-3 flex flex-wrap gap-2"
                        >
                            <label
                                v-for="kind in [
                                    'unclear',
                                    'abandoned',
                                    'silent',
                                    'ultra_short',
                                ]"
                                :key="kind"
                                class="cursor-pointer"
                            >
                                <input
                                    v-model="classification.contact_kind"
                                    type="radio"
                                    name="call-type"
                                    :value="kind"
                                    class="peer sr-only"
                                />
                                <span
                                    class="flex min-h-11 items-center rounded-lg border px-4 py-2 text-sm peer-checked:border-primary peer-checked:bg-primary peer-checked:text-primary-foreground peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-primary peer-disabled:cursor-not-allowed peer-disabled:opacity-60"
                                    >{{ contactKinds[kind] }}</span
                                >
                            </label>
                        </div>
                    </details>
                    <label v-if="endsCall" class="grid gap-1.5 text-sm font-medium">
                        Why did the call end?
                        <span class="font-normal text-muted-foreground"
                            >Optional · saved with the case record</span
                        >
                        <textarea
                            v-model="classification.note"
                            rows="2"
                            maxlength="500"
                            :class="inputClass"
                        ></textarea>
                    </label>
                    <p
                        v-for="(error, key) in classification.errors"
                        :key="key"
                        role="alert"
                        class="text-sm text-red-600"
                    >
                        {{ error }}
                    </p>
                    <div class="flex flex-wrap items-center gap-3">
                        <button
                            type="submit"
                            class="min-h-11 rounded-lg bg-primary px-5 py-2 font-medium text-primary-foreground"
                            :disabled="
                                !classification.contact_kind ||
                                (isGenuine &&
                                    classification.contact_kind === 'genuine' &&
                                    callerCase.status !== 'closed')
                            "
                        >
                            {{
                                classification.processing ? "Saving…" : submitLabel
                            }}
                        </button>
                        <span
                            v-if="classification.recentlySuccessful"
                            role="status"
                            class="text-sm text-green-700 dark:text-green-400"
                            >Call type saved</span
                        >
                    </div>
                </fieldset>
            </form>
            <p v-else class="text-sm text-muted-foreground">
                This case is closed. The call type can no longer be changed.
            </p>
        </section>

        <section
            v-if="showsQuestionnaire"
            id="safety"
            class="scroll-mt-36 space-y-4 rounded-xl border bg-card p-5"
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">3. Ask and record</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Ask in order. Record what the caller says; use Unknown when offered.
                    </p>
                </div>
                <p role="status" aria-live="polite" class="text-sm font-medium" :class="draftTone">
                    {{ draftLabel }}
                </p>
            </div>
            <p
                v-if="!isGenuine"
                role="status"
                class="rounded-lg border border-amber-400 bg-amber-50 p-3 text-sm text-amber-950 dark:bg-amber-950 dark:text-amber-100"
            >
                Save the call type above as genuine to unlock the questions.
            </p>
            <p class="text-sm text-muted-foreground">
                Answers save as a draft while you type. Use “Save questionnaire” to record the
                screen and set the priority.
            </p>
            <p v-if="draftRecovered" role="status" class="rounded-lg bg-muted p-3 text-sm">
                Recovered this browser’s draft. Check the answers against what the caller said.
            </p>
            <p
                v-if="draftMessage"
                role="alert"
                class="rounded-lg border border-red-500 p-3 text-sm text-red-600"
            >
                {{ draftMessage }}
            </p>
            <button
                v-if="draftConflict"
                type="button"
                class="min-h-11 rounded-lg border px-4"
                @click="discardAndReload"
            >
                Discard this window’s draft and reload
            </button>
            <button
                v-if="draftCanRetry"
                type="button"
                class="min-h-11 rounded-lg border px-4"
                @click="retryDraft"
            >
                Retry saving draft
            </button>
            <form @submit.prevent="saveConversation">
                <fieldset
                    :disabled="!canEdit || !protocol || !isGenuine || safety.processing || profile.processing"
                    class="space-y-5"
                >
                    <div
                        v-for="(question, position) in protocol?.questions ?? []"
                        v-show="isAsked(question)"
                        :key="question.id"
                        class="space-y-3 border-b pb-5 last:border-0"
                    >
                        <label
                            :id="`question-label-${question.id}`"
                            :for="`question-${question.id}`"
                            class="flex gap-3 text-base font-medium leading-relaxed"
                            ><span
                                class="flex size-7 shrink-0 items-center justify-center rounded-full bg-muted text-sm"
                                >{{ position + 1 }}</span
                            ><span
                                >{{ question.prompt
                                }}<span v-if="question.required" class="text-muted-foreground">
                                    *</span
                                ></span
                            ></label
                        >
                        <div
                            v-if="
                                safety.answers[position] &&
                                ['choice', 'yes_no', 'yes_no_unknown'].includes(question.type)
                            "
                            role="radiogroup"
                            :aria-labelledby="`question-label-${question.id}`"
                            class="flex flex-wrap gap-2 sm:pl-10"
                        >
                            <label
                                v-for="choice in choices(question)"
                                :key="choice.value"
                                class="cursor-pointer"
                            >
                                <input
                                    v-model="safety.answers[position].answer"
                                    type="radio"
                                    :name="`question-${question.id}`"
                                    :value="choice.value"
                                    class="peer sr-only"
                                />
                                <span
                                    class="flex min-h-11 min-w-20 items-center justify-center rounded-lg border px-4 py-2 text-sm peer-checked:border-primary peer-checked:bg-primary peer-checked:text-primary-foreground peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-primary peer-disabled:cursor-not-allowed peer-disabled:opacity-60"
                                    >{{ choice.label }}</span
                                >
                            </label>
                            <button
                                v-if="answerFor(question.id)"
                                type="button"
                                class="min-h-11 px-3 text-sm text-muted-foreground underline"
                                @click="safety.answers[position].answer = ''"
                            >
                                Clear
                            </button>
                        </div>
                        <div v-else-if="safety.answers[position]" class="sm:pl-10">
                            <textarea
                                v-if="question.type === 'paragraph'"
                                :id="`question-${question.id}`"
                                v-model="safety.answers[position].answer"
                                rows="3"
                                maxlength="2000"
                                placeholder="Caller’s own words…"
                                :class="inputClass"
                            ></textarea>
                            <input
                                v-else
                                :id="`question-${question.id}`"
                                v-model="safety.answers[position].answer"
                                maxlength="2000"
                                autocomplete="off"
                                placeholder="Caller’s answer…"
                                :class="inputClass"
                            />
                        </div>
                        <p v-if="question.help_text" class="text-sm text-muted-foreground sm:pl-10">
                            {{ question.help_text }}
                        </p>
                    </div>
                    <div class="grid gap-3 rounded-lg bg-muted p-4 sm:grid-cols-2">
                        <label class="grid gap-1.5 text-sm font-medium"
                            >Priority<select v-model="safety.priority" :class="inputClass">
                                <option
                                    v-for="priority in ['P1', 'P2', 'P3', 'S', 'Review']"
                                    :key="priority"
                                >
                                    {{ priority }}
                                </option>
                            </select></label
                        >
                        <p class="self-center text-sm text-muted-foreground">
                            Safety answers can raise the priority automatically. An unknown or
                            incomplete screen needs review.
                        </p>
                        <label
                            v-if="
                                canReview &&
                                callerCase.priority &&
                                safety.priority !== callerCase.priority
                            "
                            class="grid gap-1.5 text-sm font-medium sm:col-span-2"
                            >Reason for clinical reassessment<textarea
                                v-model="safety.review_reason"
                                maxlength="2000"
                                :class="inputClass"
                            ></textarea>
                        </label>
                    </div>
                    <p
                        v-for="(error, key) in safety.errors"
                        :key="key"
                        role="alert"
                        class="text-sm text-red-600"
                    >
                        {{ error }}
                    </p>
                    <p
                        v-if="Object.keys(profile.errors).length"
                        role="alert"
                        class="text-sm text-red-600"
                    >
                        Check the caller details above before saving the questionnaire.
                    </p>
                    <div class="flex flex-wrap items-center gap-3">
                        <button
                            class="min-h-11 rounded-lg bg-primary px-5 py-2 font-medium text-primary-foreground"
                        >
                            {{
                                safety.processing || profile.processing
                                    ? "Saving…"
                                    : "Save questionnaire"
                            }}</button
                        ><span
                            v-if="safety.recentlySuccessful"
                            role="status"
                            class="text-sm text-green-700 dark:text-green-400"
                            >Screen recorded</span
                        >
                    </div>
                </fieldset>
            </form>
        </section>

        <section
            v-else
            id="safety"
            class="scroll-mt-36 space-y-3 rounded-xl border border-dashed bg-card p-5"
        >
            <h2 class="text-lg font-semibold">3. Ask and record</h2>
            <p
                v-if="!isClassified && !classification.contact_kind && canReclassify"
                class="text-sm text-muted-foreground"
            >
                Choose the call type above. The questions form opens only for a genuine call.
            </p>
            <p
                v-else-if="!isClassified && !classification.contact_kind"
                class="text-sm text-muted-foreground"
            >
                This case is closed and no call type was recorded, so no questions can be asked.
            </p>
            <p v-else class="text-sm text-muted-foreground">
                This call is set to “{{ contactKinds[classification.contact_kind] }}”. The questions
                form opens only for a genuine call.
            </p>
        </section>

        <section
            v-if="showsQuestionnaire"
            id="handover"
            class="scroll-mt-36 space-y-4 rounded-xl border bg-card p-5"
        >
            <h2 class="text-lg font-semibold">4. Handover and finish</h2>
            <p v-if="!screenings.length" class="text-sm text-muted-foreground">
                Save the questionnaire before recording an outcome.
            </p>
            <p
                v-if="protocol && callerCase.priority"
                class="rounded-lg bg-muted p-3 text-sm whitespace-pre-wrap"
            >
                {{ protocol.guidance[callerCase.priority] }}
            </p>
            <blockquote v-if="protocol && callerCase.priority === 'S'" class="border-l-4 pl-4">
                {{ protocol.faq_answer }}
            </blockquote>
            <form @submit.prevent="closeCase">
                <fieldset :disabled="!canEdit || !protocol || closure.processing" class="space-y-4">
                    <label class="grid gap-1.5 text-sm font-medium"
                        >Call outcome<select
                            v-model="closure.disposition"
                            required
                            :class="inputClass"
                        >
                            <option value="">Choose outcome</option>
                            <option
                                v-for="item in dispositions.filter((item) =>
                                    item.priorities.includes(callerCase.priority ?? ''),
                                )"
                                :key="item.code"
                                :value="item.code"
                            >
                                {{ item.label }}
                            </option>
                        </select></label
                    >
                    <label class="grid gap-1.5 text-sm font-medium"
                        >Action taken / next step<textarea
                            v-model="closure.next_step"
                            required
                            rows="3"
                            maxlength="2000"
                            placeholder="What happened, and what happens next?"
                            :class="inputClass"
                        ></textarea>
                    </label>
                    <template v-if="callerCase.priority === 'P1'"
                        ><label class="flex items-center gap-3"
                            ><input
                                v-model="closure.emergency_action_confirmed"
                                type="checkbox"
                                class="size-5"
                            />Approved emergency instructions/action completed</label
                        ><label class="flex items-center gap-3"
                            ><input
                                v-model="closure.supervisor_notified"
                                type="checkbox"
                                class="size-5"
                            />Supervisor / Lead Dentist notified</label
                        ></template
                    >
                    <label v-if="callerCase.priority === 'S'" class="flex items-center gap-3"
                        ><input v-model="closure.faq_confirmed" type="checkbox" class="size-5" />The
                        service FAQ resolved the request</label
                    >
                    <p
                        v-for="(error, key) in closure.errors"
                        :key="key"
                        role="alert"
                        class="text-sm text-red-600"
                    >
                        {{ error }}
                    </p>
                    <button
                        class="min-h-11 rounded-lg bg-primary px-5 py-2 font-medium text-primary-foreground"
                    >
                        {{
                            closure.processing
                                ? "Saving…"
                                : callerCase.priority === "Review"
                                  ? "Save for clinical review"
                                  : "Save outcome and close"
                        }}
                    </button>
                </fieldset>
            </form>
        </section>
    </div>
</template>
