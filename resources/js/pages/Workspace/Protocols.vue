<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import { index as workspace } from '@/routes/workspace';
import { store, activate, retire } from '@/routes/screening-protocols';

defineOptions({ layout: AppLayout });
type ProtocolQuestion = {
    id: number;
    prompt: string;
    type: string;
    options: string[] | null;
    required: boolean;
    help_text: string | null;
    safety_rule: string | null;
    conditional_question_id: number | null;
    conditional_question?: { id: number; prompt: string; position: number } | null;
    conditional_answer: string | null;
    positive_priority: string;
    position: number;
};
type FormQuestion = {
    prompt: string;
    type: string;
    options: string[];
    required: boolean;
    help_text: string;
    safety_rule: string;
    conditional_on_index: number | null;
    conditional_answer: string;
    positive_priority: string;
};
type Protocol = {
    id: number;
    title: string;
    version: string;
    status: string;
    is_active: boolean;
    privacy_notice: string;
    allow_guardian: boolean;
    guidance: Record<string, string>;
    faq_answer: string;
    questions: ProtocolQuestion[];
};
defineProps<{
    protocols: {
        data: Protocol[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    canManage: boolean;
    dispositions: Record<string, { label: string; priorities: string[] }>;
    questionTypes: Record<string, string>;
    safetyRules: string[];
}>();
const visible = ref(false);
function blankQuestion(): FormQuestion {
    return {
        prompt: '',
        type: 'yes_no_unknown',
        options: [],
        required: true,
        help_text: '',
        safety_rule: '',
        conditional_on_index: null,
        conditional_answer: '',
        positive_priority: 'P1',
    };
}
const form = useForm({
    version: '',
    title: '',
    privacy_notice: '',
    allow_guardian: false,
    guidance: { P1: '', P2: '', P3: '', S: '', Review: '' } as Record<
        string,
        string
    >,
    faq_answer: '',
    questions: [blankQuestion()] as FormQuestion[],
});
function clone(protocol: Protocol) {
    form.reset();
    form.clearErrors();
    form.title = protocol.title;
    form.privacy_notice = protocol.privacy_notice;
    form.allow_guardian = protocol.allow_guardian;
    form.guidance = { ...protocol.guidance };
    form.faq_answer = protocol.faq_answer;
    form.questions = protocol.questions.map((question) => ({
        prompt: question.prompt,
        type: question.type,
        options: question.options ?? [],
        required: question.required,
        help_text: question.help_text ?? '',
        safety_rule: question.safety_rule ?? '',
        conditional_on_index: conditionalIndex(protocol, question),
        conditional_answer: question.conditional_answer ?? '',
        positive_priority: question.positive_priority,
    }));
    visible.value = true;
}
function conditionalIndex(
    protocol: Protocol,
    question: ProtocolQuestion,
): number | null {
    if (question.conditional_question_id === null) {
        return null;
    }

    const index = protocol.questions.findIndex(
        (candidate) => candidate.id === question.conditional_question_id,
    );

    return index >= 0 ? index : null;
}
function create() {
    form.reset();
    form.clearErrors();
    visible.value = true;
}
function addQuestion() {
    form.questions.push(blankQuestion());
}
function removeQuestion(position: number) {
    form.questions.splice(position, 1);
    form.questions.forEach((question) => {
        if (question.conditional_on_index === null) return;
        if (question.conditional_on_index === position) {
            question.conditional_on_index = null;
            question.conditional_answer = '';
        } else if (question.conditional_on_index > position) {
            question.conditional_on_index -= 1;
        }
    });
}
function optionsText(question: FormQuestion): string {
    return question.options.join(', ');
}
function setOptions(question: FormQuestion, value: string) {
    question.options = value
        .split(',')
        .map((option) => option.trim())
        .filter((option) => option !== '');
}
function save() {
    form.transform((data) => ({
        ...data,
        questions: data.questions.map((question) => ({
            ...question,
            help_text: question.help_text === '' ? null : question.help_text,
            safety_rule: question.safety_rule === '' ? null : question.safety_rule,
            conditional_answer:
                question.conditional_on_index === null
                    ? null
                    : question.conditional_answer,
        })),
    }));
    form.post(store.url(), {
        preserveScroll: true,
        onSuccess: () => {
            visible.value = false;
            form.reset();
        },
    });
}
function activateVersion(protocol: Protocol) {
    if (!protocol.is_active) {
        router.post(activate.url(protocol.id), {}, { preserveScroll: true });
    }
}
function retireVersion(protocol: Protocol) {
    if (
        window.confirm(
            `Retire ${protocol.version}? It will no longer be available for new screening, consent or closure.`,
        )
    )
        router.delete(retire.url(protocol.id), { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-6 p-6">
        <Head title="Screening questionnaires" />
        <Heading
            title="Screening questionnaires"
            description="One immutable version holds the safety questions, routing guidance, service FAQ and privacy notice. Exactly one version is active at a time and new calls use it immediately; earlier versions stay readable for historical records."
        />
        <Link :href="workspace()" class="text-sm">Return to workspace</Link>
        <p
            role="note"
            class="rounded-xl border border-amber-500 bg-amber-50 p-4 text-amber-950 dark:bg-amber-950 dark:text-amber-100"
        >
            Activating a version makes it the questionnaire every new call
            uses. Check the wording, referral contacts, service promises and
            child-data handling before activating, and create a new version
            instead of editing a published one.
        </p>
        <button
            v-if="canManage"
            type="button"
            class="rounded bg-primary px-4 py-2 text-primary-foreground"
            @click="create"
        >
            Create a version
        </button>
        <form
            v-if="visible && canManage"
            class="space-y-4 rounded-xl border bg-card p-5"
            @submit.prevent="save"
        >
            <h2 class="text-lg font-semibold">New questionnaire version</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <label class="grid gap-2"
                    >Unique version reference<input
                        v-model="form.version"
                        required
                        maxlength="100"
                        class="rounded border bg-background p-2" /></label
                ><label class="grid gap-2"
                    >Title<input
                        v-model="form.title"
                        required
                        maxlength="255"
                        class="rounded border bg-background p-2"
                /></label>
            </div>
            <label class="grid gap-2"
                >Privacy notice read to the caller<textarea
                    v-model="form.privacy_notice"
                    required
                    maxlength="5000"
                    rows="4"
                    class="rounded border bg-background p-2"
                ></textarea>
            </label>
            <label class="flex items-center gap-2"
                ><input v-model="form.allow_guardian" type="checkbox" />Enable
                the reviewed child and guardian capture process</label
            >
            <h3 class="font-semibold">Safety questions</h3>
            <fieldset
                v-for="(question, position) in form.questions"
                :key="position"
                class="grid gap-3 rounded border p-3"
            >
                <label class="grid gap-2"
                    >Question {{ position + 1
                    }}<textarea
                        v-model="question.prompt"
                        required
                        maxlength="1000"
                        rows="2"
                        class="rounded border bg-background p-2"
                    ></textarea>
                </label>
                <div class="grid gap-3 md:grid-cols-3">
                    <label class="grid gap-2"
                        >Answer type<select
                            v-model="question.type"
                            class="rounded border bg-background p-2"
                        >
                            <option
                                v-for="(label, value) in questionTypes"
                                :key="value"
                                :value="value"
                            >
                                {{ label }}
                            </option>
                        </select></label
                    ><label class="grid gap-2"
                        >Positive answer routes to<select
                            v-model="question.positive_priority"
                            class="rounded border bg-background p-2"
                        >
                            <option value="P1">P1 emergency</option>
                            <option value="P2">P2 urgent</option>
                        </select></label
                    ><label class="grid gap-2"
                        >Automatic safety rule<select
                            v-model="question.safety_rule"
                            class="rounded border bg-background p-2"
                        >
                            <option value="">No automatic rule</option>
                            <option
                                v-for="rule in safetyRules"
                                :key="rule"
                                :value="rule"
                            >
                                {{ rule }}
                            </option>
                        </select></label
                    >
                </div>
                <label v-if="question.type === 'choice'" class="grid gap-2"
                    >Answer options, comma separated<input
                        :value="optionsText(question)"
                        required
                        maxlength="1000"
                        class="rounded border bg-background p-2"
                        @input="
                            setOptions(
                                question,
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                </label>
                <label class="grid gap-2"
                    >Help text read to the caller (optional)<input
                        v-model="question.help_text"
                        maxlength="1000"
                        class="rounded border bg-background p-2"
                    />
                </label>
                <div class="grid gap-3 md:grid-cols-2">
                    <label class="grid gap-2"
                        >Ask only after an earlier answer<select
                            v-model="question.conditional_on_index"
                            class="rounded border bg-background p-2"
                        >
                            <option :value="null">Always ask</option>
                            <option
                                v-for="(earlier, earlierIndex) in form.questions.slice(
                                    0,
                                    position,
                                )"
                                :key="earlierIndex"
                                :value="earlierIndex"
                            >
                                Q{{ earlierIndex + 1 }}:
                                {{
                                    earlier.prompt.slice(0, 60) || 'untitled'
                                }}
                            </option>
                        </select></label
                    ><label
                        v-if="question.conditional_on_index !== null"
                        class="grid gap-2"
                        >Triggering answer<input
                            v-model="question.conditional_answer"
                            required
                            maxlength="100"
                            class="rounded border bg-background p-2"
                    /></label>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <label class="flex items-center gap-2"
                        ><input
                            v-model="question.required"
                            type="checkbox"
                        />Answer must be recorded</label
                    ><button
                        type="button"
                        class="rounded border px-3 py-2"
                        :disabled="form.questions.length === 1"
                        @click="removeQuestion(position)"
                    >
                        Remove question
                    </button>
                </div>
            </fieldset>
            <button
                type="button"
                :disabled="form.questions.length >= 50"
                class="rounded border px-3 py-2"
                @click="addQuestion"
            >
                Add question
            </button>
            <label
                v-for="priority in ['P1', 'P2', 'P3', 'S', 'Review']"
                :key="priority"
                class="grid gap-2"
                >{{ priority }} routing instructions and local contacts<textarea
                    v-model="form.guidance[priority]"
                    required
                    maxlength="5000"
                    rows="3"
                    class="rounded border bg-background p-2"
                ></textarea>
            </label>
            <label class="grid gap-2"
                >Service information FAQ (no individual clinical
                advice)<textarea
                    v-model="form.faq_answer"
                    required
                    maxlength="5000"
                    rows="3"
                    class="rounded border bg-background p-2"
                ></textarea>
            </label>
            <p
                v-for="(error, key) in form.errors"
                :key="key"
                role="alert"
                class="text-sm text-red-600"
            >
                {{ error }}
            </p>
            <div class="flex gap-3">
                <button
                    :disabled="form.processing"
                    class="rounded bg-primary px-4 py-2 text-primary-foreground"
                >
                    Save and activate</button
                ><button
                    type="button"
                    class="rounded border px-4 py-2"
                    @click="visible = false"
                >
                    Cancel
                </button>
            </div>
        </form>
        <section
            v-for="protocol in protocols.data"
            :key="protocol.id"
            class="space-y-4 rounded-xl border bg-card p-5"
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold">
                    {{ protocol.title }} · {{ protocol.version }}
                </h2>
                <span
                    class="rounded border px-3 py-1 text-sm"
                    :class="
                        protocol.is_active
                            ? 'border-emerald-600 text-emerald-700 dark:text-emerald-400'
                            : ''
                    "
                    >{{ protocol.is_active ? 'Active' : protocol.status }}</span
                >
            </div>
            <p class="text-sm text-muted-foreground">
                {{
                    protocol.is_active
                        ? 'New calls use this version.'
                        : 'Historical version. It stays available on calls that already recorded consent against it.'
                }}
            </p>
            <details class="space-y-3">
                <summary class="cursor-pointer font-medium">
                    Review all content
                </summary>
                <h3 class="pt-3 font-semibold">Privacy notice</h3>
                <p class="whitespace-pre-wrap">{{ protocol.privacy_notice }}</p>
                <p>
                    Guardian capture
                    {{ protocol.allow_guardian ? 'enabled' : 'disabled' }}
                </p>
                <ul class="grid gap-2">
                    <li
                        v-for="(question, position) in protocol.questions"
                        :key="position"
                    >
                        {{ position + 1 }}. {{ question.prompt }}
                        <span class="text-sm text-muted-foreground">
                            · {{ questionTypes[question.type] ?? question.type }}
                            · {{ question.required ? 'required' : 'optional' }}
                            <template v-if="question.conditional_question">
                                · asked only after Q{{
                                    (question.conditional_question.position ??
                                        0) + 1
                                }}
                                when the answer is "{{ question.conditional_answer }}"
                            </template>
                            <template v-if="question.safety_rule">
                                · rule: {{ question.safety_rule }}
                            </template>
                        </span>
                        → {{ question.positive_priority }} when positive
                    </li>
                </ul>
                <p
                    v-for="(guidance, priority) in protocol.guidance"
                    :key="priority"
                    class="whitespace-pre-wrap"
                >
                    <strong>{{ priority }}:</strong> {{ guidance }}
                </p>
                <p class="whitespace-pre-wrap">
                    <strong>Service FAQ:</strong> {{ protocol.faq_answer }}
                </p>
                <h3 class="font-semibold">
                    Disposition paths covered by this version
                </h3>
                <ul class="grid gap-1 text-sm">
                    <li v-for="(item, code) in dispositions" :key="code">
                        {{ item.label }} · {{ item.priorities.join(', ') }}
                    </li>
                </ul>
            </details>
            <div class="flex flex-wrap gap-3">
                <button
                    v-if="canManage"
                    type="button"
                    class="rounded border px-3 py-2"
                    @click="clone(protocol)"
                >
                    Copy into a new version</button
                ><button
                    v-if="canManage && !protocol.is_active"
                    type="button"
                    class="rounded bg-primary px-3 py-2 text-primary-foreground"
                    @click="activateVersion(protocol)"
                >
                    Activate version</button
                ><button
                    v-if="
                        canManage &&
                        !protocol.is_active &&
                        protocol.status !== 'retired'
                    "
                    type="button"
                    class="rounded border px-3 py-2 text-red-600"
                    @click="retireVersion(protocol)"
                >
                    Retire version
                </button>
            </div>
        </section>
        <nav class="flex gap-4 text-sm">
            <Link v-if="protocols.prev_page_url" :href="protocols.prev_page_url"
                >Previous versions</Link
            ><Link
                v-if="protocols.next_page_url"
                :href="protocols.next_page_url"
                >More versions</Link
            >
        </nav>
    </div>
</template>
