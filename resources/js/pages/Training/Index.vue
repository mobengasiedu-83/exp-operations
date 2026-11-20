<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import AdminTable from '@/components/AdminTable.vue';
import { index } from '@/routes/training';
import { store as storeModule, update as updateModule, publish, retire } from '@/routes/training/modules';
import { store as storeAssessment } from '@/routes/training/assessments';
import { store as storeCertification, revoke } from '@/routes/training/certifications';

defineOptions({ layout: AppLayout });

type Paginator<T> = { data: T[]; prev_page_url: string | null; next_page_url: string | null };
type ModuleRow = {
    id: number; day: number; title: string; objectives: string; content: string;
    requires_role_play: boolean; pass_mark: number; status: string; author: string | null;
    published_at: string | null; revision: number;
};
type AssessmentRow = {
    id: number; day: number | null; module: string | null; candidate: string | null; kind: string;
    score: number; pass_mark: number; result: string; assessor: string | null; assessed_at: string | null;
};
type CertificationRow = {
    id: number; candidate: string | null; status: string; effective_status: string; issued_at: string | null;
    expires_at: string | null; authoriser: string | null; reference: string; revoked_at: string | null;
    revoked_by: string | null; revocation_reason: string | null; revision: number;
};
type ProgressRow = {
    day: number; module_id: number | null; title: string | null; status: string;
    knowledge: { score: number; result: string } | null;
    role_play: { score: number; result: string } | null;
    complete: boolean;
};

const props = defineProps<{
    modules: ModuleRow[];
    assessments: Paginator<AssessmentRow>;
    certifications: Paginator<CertificationRow>;
    progress: ProgressRow[];
    candidate: { id: number; name: string } | null;
    candidates: { id: number; name: string }[];
    authorisedForLiveCalls: boolean;
    myCertification: { id: number; reference: string; issued_at: string; expires_at: string } | null;
    days: number[];
    kinds: string[];
    statuses: string[];
    filters: { tab: string; user_id: number | null; q: string };
    canManage: boolean;
    canAuthorise: boolean;
    defaultPassMark: number;
    asOf: string;
}>();

const tabs: { key: string; label: string }[] = [
    { key: 'curriculum', label: 'Curriculum' },
    { key: 'assessment', label: 'Assessment' },
    { key: 'certification', label: 'Certification' },
];
const search = ref(props.filters.q);
const candidateId = ref(props.filters.user_id === null ? '' : String(props.filters.user_id));
const showModuleForm = ref(false);
const editingModule = ref<ModuleRow | null>(null);
const activeCertification = ref<CertificationRow | null>(null);

const moduleForm = useForm({
    day: props.days[0] ?? 1,
    title: '',
    objectives: '',
    content: '',
    requires_role_play: true,
    pass_mark: props.defaultPassMark,
    revision: 1,
});
const assessmentForm = useForm({
    training_module_id: '',
    user_id: '',
    kind: 'knowledge',
    score: 80,
    note: '',
});
const certificationForm = useForm({
    user_id: '',
    reference: '',
    issued_at: new Date().toISOString().slice(0, 10),
    expires_at: '',
    attested: false,
});
const revokeForm = useForm({ revocation_reason: '' });

function applyFilters() {
    router.get(
        index.url(),
        { tab: props.filters.tab, user_id: candidateId.value || undefined, q: search.value || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function startModuleCreate() {
    editingModule.value = null;
    moduleForm.reset();
    moduleForm.clearErrors();
    moduleForm.day = props.days[0] ?? 1;
    moduleForm.pass_mark = props.defaultPassMark;
    showModuleForm.value = true;
}

function startModuleEdit(row: Record<string, unknown>) {
    const module = row as unknown as ModuleRow;
    editingModule.value = module;
    moduleForm.clearErrors();
    moduleForm.day = module.day;
    moduleForm.title = module.title;
    moduleForm.objectives = module.objectives;
    moduleForm.content = module.content;
    moduleForm.requires_role_play = module.requires_role_play;
    moduleForm.pass_mark = module.pass_mark;
    moduleForm.revision = module.revision;
    showModuleForm.value = true;
}

function submitModule() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            showModuleForm.value = false;
            editingModule.value = null;
            moduleForm.reset();
        },
    };
    if (editingModule.value !== null) {
        moduleForm.patch(updateModule.url(editingModule.value.id), options);
        return;
    }
    moduleForm.post(storeModule.url(), options);
}

function publishModule(row: Record<string, unknown>) {
    if (!window.confirm(`Publish day ${String(row.day)}? Published modules become the assessed curriculum.`)) return;
    router.post(publish.url(Number(row.id)), {}, { preserveScroll: true });
}

function retireModule(row: Record<string, unknown>) {
    if (!window.confirm(`Retire day ${String(row.day)}? Existing assessment history is retained.`)) return;
    router.post(retire.url(Number(row.id)), {}, { preserveScroll: true });
}

function submitAssessment() {
    assessmentForm.post(storeAssessment.url(), {
        preserveScroll: true,
        onSuccess: () => {
            assessmentForm.reset();
            assessmentForm.kind = 'knowledge';
            assessmentForm.score = 80;
        },
    });
}

function submitCertification() {
    certificationForm.post(storeCertification.url(), {
        preserveScroll: true,
        onSuccess: () => {
            certificationForm.reset();
            certificationForm.issued_at = new Date().toISOString().slice(0, 10);
        },
    });
}

function startRevoke(row: Record<string, unknown>) {
    revokeForm.reset();
    revokeForm.clearErrors();
    activeCertification.value = row as unknown as CertificationRow;
}

function submitRevoke() {
    if (activeCertification.value === null) return;
    revokeForm.post(revoke.url(activeCertification.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            activeCertification.value = null;
            revokeForm.reset();
        },
    });
}

function label(value: string) {
    return value.replaceAll('_', ' ');
}
</script>

<template>
    <div class="space-y-6 p-6">
        <Head title="Training and certification" />
        <Heading
            title="Five-day curriculum, assessment and certification"
            description="Days 1–5 combine approved knowledge and role-play assessment. Certification requires a pass on every published day, an independent assessor and a named authoriser; only a current certification allows an agent to open a live case."
        />
        <p
            role="status"
            class="rounded-xl border p-4 text-sm"
            :class="
                authorisedForLiveCalls
                    ? 'border-emerald-500 bg-emerald-50 text-emerald-950 dark:bg-emerald-950 dark:text-emerald-100'
                    : 'border-amber-500 bg-amber-50 text-amber-950 dark:bg-amber-950 dark:text-amber-100'
            "
        >
            <template v-if="authorisedForLiveCalls">
                Live-call authorisation: {{ myCertification ? `${myCertification.reference} valid to ${myCertification.expires_at}` : 'granted by supervisor permission (no personal certification required).' }}
            </template>
            <template v-else>
                Live-call authorisation is not active for your account. New live cases are blocked until every curriculum
                day is passed and certification is issued.
            </template>
        </p>
        <nav class="flex flex-wrap gap-4 text-sm">
            <Link
                v-for="item in tabs"
                :key="item.key"
                :href="index({ query: { tab: item.key, user_id: candidateId || undefined, q: search || undefined } })"
                :class="{ 'font-bold underline': filters.tab === item.key }"
            >
                {{ item.label }}
            </Link>
        </nav>

        <section v-if="filters.tab === 'curriculum'" class="space-y-4">
            <div v-if="canManage" class="space-y-4 rounded-xl border bg-card p-5">
                <button type="button" class="rounded bg-primary px-4 py-2 text-primary-foreground" @click="startModuleCreate">
                    New curriculum module
                </button>
                <form v-if="showModuleForm" class="grid gap-4 md:grid-cols-2" @submit.prevent="submitModule">
                    <h2 class="text-lg font-semibold md:col-span-2">
                        {{ editingModule === null ? 'New curriculum day' : `Edit day ${editingModule.day}` }}
                    </h2>
                    <label class="grid gap-2">
                        Day
                        <select v-model.number="moduleForm.day" class="rounded border bg-background p-2">
                            <option v-for="day in days" :key="day" :value="day">Day {{ day }}</option>
                        </select>
                    </label>
                    <label class="grid gap-2">Title<input v-model="moduleForm.title" required maxlength="255" class="rounded border bg-background p-2" /></label>
                    <label class="grid gap-2 md:col-span-2">
                        Learning objectives
                        <textarea v-model="moduleForm.objectives" rows="3" required maxlength="3000" class="rounded border bg-background p-2"></textarea>
                    </label>
                    <label class="grid gap-2 md:col-span-2">
                        Curriculum content
                        <textarea v-model="moduleForm.content" rows="6" required maxlength="20000" class="rounded border bg-background p-2"></textarea>
                    </label>
                    <label class="grid gap-2">Pass mark (50–100)<input v-model.number="moduleForm.pass_mark" type="number" min="50" max="100" required class="rounded border bg-background p-2" /></label>
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="moduleForm.requires_role_play" type="checkbox" />
                        Role-play assessment required
                    </label>
                    <p v-for="(error, key) in moduleForm.errors" :key="key" role="alert" class="text-sm text-red-600 md:col-span-2">{{ error }}</p>
                    <div class="flex gap-3 md:col-span-2">
                        <button :disabled="moduleForm.processing" class="rounded bg-primary px-4 py-2 text-primary-foreground">
                            {{ editingModule === null ? 'Save draft module' : 'Save draft changes' }}
                        </button>
                        <button type="button" class="rounded border px-4 py-2" @click="showModuleForm = false">Cancel</button>
                    </div>
                </form>
            </div>
            <AdminTable
                :rows="modules"
                :columns="[
                    { key: 'day', label: 'Day' },
                    { key: 'title', label: 'Module' },
                    { key: 'status', label: 'Status' },
                    { key: 'pass_mark', label: 'Pass mark' },
                    { key: 'requires_role_play', label: 'Role play' },
                    { key: 'author', label: 'Author' },
                ]"
            >
                <template #actions="{ row }">
                    <div class="flex flex-wrap gap-2 text-sm">
                        <button v-if="canManage && row.status === 'draft'" class="rounded border px-2 py-1" @click="startModuleEdit(row)">Edit</button>
                        <button v-if="canManage && row.status === 'draft'" class="rounded border px-2 py-1" @click="publishModule(row)">Publish</button>
                        <button v-if="canManage && row.status !== 'retired'" class="rounded border px-2 py-1" @click="retireModule(row)">Retire</button>
                    </div>
                </template>
            </AdminTable>
        </section>


        <section v-if="filters.tab === 'assessment'" class="space-y-4">
            <form class="flex flex-wrap items-end gap-4 text-sm" @submit.prevent="applyFilters">
                <label class="grid gap-2">
                    Trainee progress
                    <select v-model="candidateId" class="rounded border bg-background p-2">
                        <option value="">My progress</option>
                        <option v-for="person in candidates" :key="person.id" :value="String(person.id)">{{ person.name }}</option>
                    </select>
                </label>
                <label class="grid gap-2">
                    Search assessed trainee
                    <input v-model="search" maxlength="100" class="w-56 rounded border bg-background p-2" />
                </label>
                <button class="rounded border px-3 py-2">Apply</button>
            </form>
            <div class="rounded-xl border bg-card p-5">
                <h2 class="font-semibold">Curriculum progress{{ candidate === null ? '' : ` · ${candidate.name}` }}</h2>
                <table class="mt-3 w-full text-left text-sm">
                    <thead>
                        <tr>
                            <th class="p-2">Day</th>
                            <th class="p-2">Module</th>
                            <th class="p-2">Knowledge</th>
                            <th class="p-2">Role play</th>
                            <th class="p-2">Complete</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in progress" :key="row.day" class="border-t">
                            <td class="p-2">Day {{ row.day }}</td>
                            <td class="p-2">{{ row.title ?? 'Not published' }}</td>
                            <td class="p-2">{{ row.knowledge ? `${row.knowledge.result} (${row.knowledge.score})` : '—' }}</td>
                            <td class="p-2">{{ row.role_play ? `${row.role_play.result} (${row.role_play.score})` : '—' }}</td>
                            <td class="p-2">{{ row.complete ? 'Yes' : 'No' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <form v-if="canManage" class="grid gap-3 rounded-xl border bg-card p-5 md:grid-cols-2" @submit.prevent="submitAssessment">
                <h2 class="text-lg font-semibold md:col-span-2">Record assessment</h2>
                <label class="grid gap-2">
                    Published module
                    <select v-model="assessmentForm.training_module_id" required class="rounded border bg-background p-2">
                        <option value="" disabled>Select a day</option>
                        <option v-for="module in modules.filter((item) => item.status === 'published')" :key="module.id" :value="String(module.id)">
                            Day {{ module.day }} · {{ module.title }}
                        </option>
                    </select>
                </label>
                <label class="grid gap-2">
                    Trainee
                    <select v-model="assessmentForm.user_id" required class="rounded border bg-background p-2">
                        <option value="" disabled>Select a trainee</option>
                        <option v-for="person in candidates" :key="person.id" :value="String(person.id)">{{ person.name }}</option>
                    </select>
                </label>
                <label class="grid gap-2">
                    Assessment type
                    <select v-model="assessmentForm.kind" class="rounded border bg-background p-2">
                        <option v-for="kind in kinds" :key="kind" :value="kind">{{ label(kind) }}</option>
                    </select>
                </label>
                <label class="grid gap-2">Score (0–100)<input v-model.number="assessmentForm.score" type="number" min="0" max="100" required class="rounded border bg-background p-2" /></label>
                <label class="grid gap-2 md:col-span-2">
                    Assessor note
                    <textarea v-model="assessmentForm.note" rows="2" maxlength="2000" class="rounded border bg-background p-2"></textarea>
                </label>
                <p v-for="(error, key) in assessmentForm.errors" :key="key" role="alert" class="text-sm text-red-600 md:col-span-2">{{ error }}</p>
                <div class="md:col-span-2">
                    <button :disabled="assessmentForm.processing" class="rounded bg-primary px-4 py-2 text-primary-foreground">Record result</button>
                </div>
            </form>

            <AdminTable
                :rows="assessments.data"
                :columns="[
                    { key: 'day', label: 'Day' },
                    { key: 'module', label: 'Module' },
                    { key: 'candidate', label: 'Trainee' },
                    { key: 'kind', label: 'Type' },
                    { key: 'score', label: 'Score' },
                    { key: 'result', label: 'Result' },
                    { key: 'assessor', label: 'Assessor' },
                    { key: 'assessed_at', label: 'Assessed at' },
                ]"
                :previous="assessments.prev_page_url"
                :next="assessments.next_page_url"
            />
        </section>


        <section v-if="filters.tab === 'certification'" class="space-y-4">
            <div v-if="canAuthorise" class="space-y-4 rounded-xl border bg-card p-5">
                <h2 class="text-lg font-semibold">Issue live-call certification</h2>
                <p class="text-sm text-muted-foreground">
                    Certification is only issued when every published curriculum day has a passing knowledge result and a
                    passing role-play result where the module requires it. The curriculum snapshot is retained.
                </p>
                <form class="grid gap-4 md:grid-cols-2" @submit.prevent="submitCertification">
                    <label class="grid gap-2">
                        Trainee
                        <select v-model="certificationForm.user_id" required class="rounded border bg-background p-2">
                            <option value="" disabled>Select an operator</option>
                            <option v-for="person in candidates" :key="person.id" :value="String(person.id)">{{ person.name }}</option>
                        </select>
                    </label>
                    <label class="grid gap-2">Formal reference<input v-model="certificationForm.reference" required maxlength="255" class="rounded border bg-background p-2" /></label>
                    <label class="grid gap-2">Issued on<input v-model="certificationForm.issued_at" type="date" class="rounded border bg-background p-2" /></label>
                    <label class="grid gap-2">Expires on (default 12 months)<input v-model="certificationForm.expires_at" type="date" class="rounded border bg-background p-2" /></label>
                    <label class="flex items-start gap-2 text-sm md:col-span-2">
                        <input v-model="certificationForm.attested" type="checkbox" required class="mt-1" />
                        I confirm the curriculum, assessments and independent verification are complete and authorise this
                        operator to take live calls.
                    </label>
                    <p v-for="(error, key) in certificationForm.errors" :key="key" role="alert" class="text-sm text-red-600 md:col-span-2">{{ error }}</p>
                    <div class="md:col-span-2">
                        <button :disabled="certificationForm.processing" class="rounded bg-primary px-4 py-2 text-primary-foreground">Issue certification</button>
                    </div>
                </form>
            </div>

            <AdminTable
                :rows="certifications.data"
                :columns="[
                    { key: 'candidate', label: 'Operator' },
                    { key: 'effective_status', label: 'Status' },
                    { key: 'reference', label: 'Reference' },
                    { key: 'issued_at', label: 'Issued' },
                    { key: 'expires_at', label: 'Expires' },
                    { key: 'authoriser', label: 'Authorised by' },
                ]"
                :previous="certifications.prev_page_url"
                :next="certifications.next_page_url"
            >
                <template #actions="{ row }">
                    <button
                        v-if="canAuthorise && row.effective_status === 'active'"
                        class="rounded border px-2 py-1 text-sm"
                        @click="startRevoke(row)"
                    >
                        Revoke
                    </button>
                </template>
            </AdminTable>

            <form v-if="activeCertification !== null" class="grid gap-3 rounded-xl border bg-card p-5" @submit.prevent="submitRevoke">
                <h3 class="font-semibold">Revoke {{ activeCertification.candidate }} · {{ activeCertification.reference }}</h3>
                <label class="grid gap-2 text-sm">
                    Reason for withdrawal
                    <textarea v-model="revokeForm.revocation_reason" rows="3" required maxlength="2000" class="rounded border bg-background p-2"></textarea>
                </label>
                <p v-for="(error, key) in revokeForm.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
                <div class="flex gap-3">
                    <button :disabled="revokeForm.processing" class="rounded bg-primary px-4 py-2 text-primary-foreground">Revoke certification</button>
                    <button type="button" class="rounded border px-4 py-2" @click="activeCertification = null">Cancel</button>
                </div>
            </form>
        </section>

        <p class="text-sm text-muted-foreground">Snapshot as of {{ asOf }}</p>
    </div>
</template>

