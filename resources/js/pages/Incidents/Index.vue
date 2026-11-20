<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import AdminTable from '@/components/AdminTable.vue';
import { index, store, escalate, evidence as logEvidence, resolve, close } from '@/routes/incidents';
import { store as storeAction, complete, verify } from '@/routes/incidents/actions';

defineOptions({ layout: AppLayout });

type Paginator<T> = { data: T[]; prev_page_url: string | null; next_page_url: string | null };
type IncidentDetail = {
    description: string | null; immediate_action: string | null;
    escalation_note: string | null; resolution_summary: string | null;
};
type IncidentRow = {
    id: number; reference: string; category: string; severity: string; status: string; summary: string;
    case_id: number | null; reporter: string | null; assigned_to: string | null;
    occurred_at: string | null; escalated_at: string | null; escalation_due_at: string | null;
    overdue: boolean; resolved_at: string | null; closed_at: string | null; revision: number;
    detail: IncidentDetail | null;
};
type ActionRow = {
    id: number; incident_id: number; incident: string | null; incident_status: string | null;
    owner: string | null; description: string | null; due_at: string | null; overdue: boolean;
    status: string; completed_at: string | null; verified_by: string | null; verified_at: string | null;
};
type EvidenceRow = { id: number; incident: string | null; user: string | null; purpose: string; accessed_at: string | null };

const props = defineProps<{
    incidents: Paginator<IncidentRow>;
    actions: Paginator<ActionRow>;
    evidence: Paginator<EvidenceRow> | null;
    counts: Record<string, number>;
    tabs: string[];
    categories: string[];
    severities: string[];
    statuses: string[];
    slaHours: Record<string, number>;
    owners: { id: number; name: string }[];
    filters: { tab: string; status: string | null; severity: string | null; q: string };
    canManage: boolean;
    canClose: boolean;
    canReport: boolean;
    asOf: string;
}>();

const search = ref(props.filters.q);
const status = ref(props.filters.status ?? '');
const severity = ref(props.filters.severity ?? '');
const showForm = ref(false);
const activeIncident = ref<IncidentRow | null>(null);
const incidentAction = ref<'escalate' | 'evidence' | 'action' | 'resolve' | null>(null);
const activeAction = ref<ActionRow | null>(null);
const actionMode = ref<'complete' | 'verify' | null>(null);
const closing = ref<IncidentRow | null>(null);
const now = new Date().toISOString().slice(0, 16);

const tabLabels: Record<string, string> = {
    all: 'All incidents', safety: 'Safety', privacy: 'Privacy', complaints: 'Complaints', safeguarding: 'Safeguarding',
};
const incidentForm = useForm({
    reference: '',
    category: 'safety',
    severity: 'high',
    case_id: '',
    occurred_at: now,
    summary: '',
    description: '',
    immediate_action: '',
});
const escalateForm = useForm({ revision: 1, severity: 'high', assigned_to: '', note: '' });
const evidenceForm = useForm({ purpose: '', attested: false });
const actionForm = useForm({ description: '', owner_id: '', due_at: '' });
const completeForm = useForm({ completion_note: '' });
const verifyForm = useForm({ verification_note: '' });
const resolveForm = useForm({ resolution_summary: '' });
const closeForm = useForm({ attested: false });

function applyFilters() {
    router.get(
        index.url(),
        {
            tab: props.filters.tab,
            status: status.value || undefined,
            severity: severity.value || undefined,
            q: search.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function startCreate() {
    incidentForm.reset();
    incidentForm.clearErrors();
    incidentForm.occurred_at = now;
    showForm.value = true;
}

function submitIncident() {
    incidentForm.post(store.url(), {
        preserveScroll: true,
        onSuccess: () => {
            showForm.value = false;
            incidentForm.reset();
        },
    });
}

function startEscalate(row: Record<string, unknown>) {
    const incident = row as unknown as IncidentRow;
    escalateForm.reset();
    escalateForm.clearErrors();
    activeIncident.value = incident;
    incidentAction.value = 'escalate';
    escalateForm.revision = incident.revision;
    escalateForm.severity = incident.severity;
}

function submitEscalate() {
    if (activeIncident.value === null) return;
    escalateForm.post(escalate.url(activeIncident.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            activeIncident.value = null;
            incidentAction.value = null;
            escalateForm.reset();
        },
    });
}

function startEvidence(row: Record<string, unknown>) {
    evidenceForm.reset();
    evidenceForm.clearErrors();
    activeIncident.value = row as unknown as IncidentRow;
    incidentAction.value = 'evidence';
}

function submitEvidence() {
    if (activeIncident.value === null) return;
    evidenceForm.post(logEvidence.url(activeIncident.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            activeIncident.value = null;
            incidentAction.value = null;
            evidenceForm.reset();
        },
    });
}

function startAction(row: Record<string, unknown>) {
    actionForm.reset();
    actionForm.clearErrors();
    activeIncident.value = row as unknown as IncidentRow;
    incidentAction.value = 'action';
}

function submitAction() {
    if (activeIncident.value === null) return;
    actionForm.post(storeAction.url(activeIncident.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            activeIncident.value = null;
            incidentAction.value = null;
            actionForm.reset();
        },
    });
}

function startResolve(row: Record<string, unknown>) {
    resolveForm.reset();
    resolveForm.clearErrors();
    activeIncident.value = row as unknown as IncidentRow;
    incidentAction.value = 'resolve';
}

function submitResolve() {
    if (activeIncident.value === null) return;
    resolveForm.post(resolve.url(activeIncident.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            activeIncident.value = null;
            incidentAction.value = null;
            resolveForm.reset();
        },
    });
}

function startComplete(row: Record<string, unknown>) {
    completeForm.reset();
    completeForm.clearErrors();
    activeAction.value = row as unknown as ActionRow;
    actionMode.value = 'complete';
}

function submitComplete() {
    if (activeAction.value === null) return;
    completeForm.patch(complete.url(activeAction.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            activeAction.value = null;
            actionMode.value = null;
            completeForm.reset();
        },
    });
}

function startVerify(row: Record<string, unknown>) {
    verifyForm.reset();
    verifyForm.clearErrors();
    activeAction.value = row as unknown as ActionRow;
    actionMode.value = 'verify';
}

function submitVerify() {
    if (activeAction.value === null) return;
    verifyForm.post(verify.url(activeAction.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            activeAction.value = null;
            actionMode.value = null;
            verifyForm.reset();
        },
    });
}

function startClose(row: Record<string, unknown>) {
    closeForm.reset();
    closeForm.clearErrors();
    closing.value = row as unknown as IncidentRow;
}

function submitClose() {
    if (closing.value === null) return;
    closeForm.post(close.url(closing.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            closing.value = null;
            closeForm.reset();
        },
    });
}

function label(value: string) {
    return value.replaceAll('_', ' ');
}

function incidentDetail(row: Record<string, unknown>): IncidentDetail | null {
    return (row as unknown as IncidentRow).detail;
}
</script>

<template>
    <div class="space-y-6 p-6">
        <Head title="Incidents" />
        <Heading
            title="Safety, privacy, complaint and safeguarding incidents"
            description="Every incident is recorded with a category, severity and SLA. Escalation names an owner, evidence access is logged, corrective actions need independent verification, and resolution is only possible once the actions are complete."
        />
        <p role="note" class="rounded-xl border border-amber-500 bg-amber-50 p-4 text-amber-950 dark:bg-amber-950 dark:text-amber-100">
            Escalation SLAs: critical {{ slaHours.critical }}h, high {{ slaHours.high }}h, medium {{ slaHours.medium }}h,
            low {{ slaHours.low }}h. Critical QA failures create an escalated incident automatically. Only managers can
            read incident evidence; every read is recorded.
        </p>
        <nav class="flex flex-wrap gap-4 text-sm">
            <Link
                v-for="item in tabs"
                :key="item"
                :href="index({ query: { tab: item, status: status || undefined, severity: severity || undefined, q: search || undefined } })"
                :class="{ 'font-bold underline': filters.tab === item }"
            >
                {{ tabLabels[item] }}
                <span v-if="item !== 'all'" class="text-muted-foreground">({{ counts[item === 'complaints' ? 'complaint' : item] ?? 0 }})</span>
            </Link>
        </nav>

        <form class="flex flex-wrap items-end gap-4 text-sm" @submit.prevent="applyFilters">
            <label class="grid gap-2">
                Search reference or summary
                <input v-model="search" maxlength="100" class="w-64 rounded border bg-background p-2" />
            </label>
            <label class="grid gap-2">
                Status
                <select v-model="status" class="rounded border bg-background p-2">
                    <option value="">Any status</option>
                    <option v-for="item in statuses" :key="item" :value="item">{{ label(item) }}</option>
                </select>
            </label>
            <label class="grid gap-2">
                Severity
                <select v-model="severity" class="rounded border bg-background p-2">
                    <option value="">Any severity</option>
                    <option v-for="item in severities" :key="item" :value="item">{{ item }}</option>
                </select>
            </label>
            <button class="rounded border px-3 py-2">Apply</button>
        </form>

        <section v-if="canReport" class="space-y-4 rounded-xl border bg-card p-5">
            <button type="button" class="rounded bg-primary px-4 py-2 text-primary-foreground" @click="startCreate">
                Report an incident
            </button>
            <form v-if="showForm" class="grid gap-4 md:grid-cols-2" @submit.prevent="submitIncident">
                <h2 class="text-lg font-semibold md:col-span-2">New incident</h2>
                <label class="grid gap-2">Reference<input v-model="incidentForm.reference" required maxlength="80" class="rounded border bg-background p-2" /></label>
                <label class="grid gap-2">
                    Category
                    <select v-model="incidentForm.category" class="rounded border bg-background p-2">
                        <option v-for="item in categories" :key="item" :value="item">{{ label(item) }}</option>
                    </select>
                </label>
                <label class="grid gap-2">
                    Severity
                    <select v-model="incidentForm.severity" class="rounded border bg-background p-2">
                        <option v-for="item in severities" :key="item" :value="item">{{ item }}</option>
                    </select>
                </label>
                <label class="grid gap-2">Occurred at<input v-model="incidentForm.occurred_at" type="datetime-local" required class="rounded border bg-background p-2" /></label>
                <label class="grid gap-2 md:col-span-2">Summary (non-identifying)<input v-model="incidentForm.summary" required maxlength="255" class="rounded border bg-background p-2" /></label>
                <label class="grid gap-2 md:col-span-2">
                    What happened
                    <textarea v-model="incidentForm.description" rows="4" required maxlength="5000" class="rounded border bg-background p-2"></textarea>
                </label>
                <label class="grid gap-2 md:col-span-2">
                    Immediate action taken
                    <textarea v-model="incidentForm.immediate_action" rows="2" maxlength="5000" class="rounded border bg-background p-2"></textarea>
                </label>
                <p v-for="(error, key) in incidentForm.errors" :key="key" role="alert" class="text-sm text-red-600 md:col-span-2">{{ error }}</p>
                <div class="flex gap-3 md:col-span-2">
                    <button :disabled="incidentForm.processing" class="rounded bg-primary px-4 py-2 text-primary-foreground">Record incident</button>
                    <button type="button" class="rounded border px-4 py-2" @click="showForm = false">Cancel</button>
                </div>
            </form>
        </section>


        <section class="space-y-3">
            <h2 class="text-lg font-semibold">Incident register</h2>
            <AdminTable
                :rows="incidents.data"
                :columns="[
                    { key: 'reference', label: 'Reference' },
                    { key: 'category', label: 'Category' },
                    { key: 'severity', label: 'Severity' },
                    { key: 'status', label: 'Status' },
                    { key: 'summary', label: 'Summary' },
                    { key: 'assigned_to', label: 'Owner' },
                    { key: 'escalation_due_at', label: 'SLA due' },
                ]"
                :previous="incidents.prev_page_url"
                :next="incidents.next_page_url"
            >
                <template #actions="{ row }">
                    <div class="flex flex-col gap-2 text-sm">
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-if="canManage && row.status !== 'resolved' && row.status !== 'closed'"
                                class="rounded border px-2 py-1"
                                @click="startEscalate(row)"
                            >
                                Escalate
                            </button>
                            <button v-if="canManage" class="rounded border px-2 py-1" @click="startEvidence(row)">Evidence access</button>
                            <button v-if="canManage && row.status !== 'closed'" class="rounded border px-2 py-1" @click="startAction(row)">
                                Assign action
                            </button>
                            <button
                                v-if="canManage && (row.status === 'open' || row.status === 'investigating')"
                                class="rounded border px-2 py-1"
                                @click="startResolve(row)"
                            >
                                Resolve
                            </button>
                            <button v-if="canClose && row.status === 'resolved'" class="rounded border px-2 py-1" @click="startClose(row)">
                                Close
                            </button>
                        </div>
                        <details v-if="incidentDetail(row)">
                            <summary class="cursor-pointer text-muted-foreground">Evidence detail (logged access)</summary>
                            <p class="mt-2 whitespace-pre-line">{{ incidentDetail(row)?.description }}</p>
                            <p v-if="incidentDetail(row)?.immediate_action" class="mt-2"><strong>Immediate action:</strong> {{ incidentDetail(row)?.immediate_action }}</p>
                            <p v-if="incidentDetail(row)?.escalation_note" class="mt-2"><strong>Escalation:</strong> {{ incidentDetail(row)?.escalation_note }}</p>
                            <p v-if="incidentDetail(row)?.resolution_summary" class="mt-2"><strong>Resolution:</strong> {{ incidentDetail(row)?.resolution_summary }}</p>
                        </details>
                        <p v-if="row.overdue" class="font-medium text-red-600">SLA overdue</p>
                    </div>
                </template>
            </AdminTable>
        </section>


        <form v-if="incidentAction === 'escalate' && activeIncident !== null" class="grid gap-3 rounded-xl border bg-card p-5" @submit.prevent="submitEscalate">
            <h3 class="font-semibold">Escalate {{ activeIncident.reference }}</h3>
            <label class="grid gap-2 text-sm">
                Severity
                <select v-model="escalateForm.severity" class="rounded border bg-background p-2">
                    <option v-for="item in severities" :key="item" :value="item">{{ item }}</option>
                </select>
            </label>
            <label class="grid gap-2 text-sm">
                Named owner
                <select v-model="escalateForm.assigned_to" required class="rounded border bg-background p-2">
                    <option value="" disabled>Select an owner</option>
                    <option v-for="owner in owners" :key="owner.id" :value="String(owner.id)">{{ owner.name }}</option>
                </select>
            </label>
            <label class="grid gap-2 text-sm">
                Escalation note
                <textarea v-model="escalateForm.note" rows="2" maxlength="2000" class="rounded border bg-background p-2"></textarea>
            </label>
            <p v-for="(error, key) in escalateForm.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
            <div class="flex gap-3">
                <button :disabled="escalateForm.processing" class="rounded bg-primary px-4 py-2 text-primary-foreground">Escalate incident</button>
                <button type="button" class="rounded border px-4 py-2" @click="incidentAction = null">Cancel</button>
            </div>
        </form>

        <form v-if="incidentAction === 'evidence' && activeIncident !== null" class="grid gap-3 rounded-xl border bg-card p-5" @submit.prevent="submitEvidence">
            <h3 class="font-semibold">Record evidence access · {{ activeIncident.reference }}</h3>
            <label class="grid gap-2 text-sm">
                Purpose of access
                <textarea v-model="evidenceForm.purpose" rows="2" maxlength="255" required class="rounded border bg-background p-2"></textarea>
            </label>
            <label class="flex items-start gap-2 text-sm">
                <input v-model="evidenceForm.attested" type="checkbox" required class="mt-1" />
                I confirm this access is necessary for investigation and is now recorded in the audit trail.
            </label>
            <p v-for="(error, key) in evidenceForm.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
            <div class="flex gap-3">
                <button :disabled="evidenceForm.processing" class="rounded bg-primary px-4 py-2 text-primary-foreground">Record access</button>
                <button type="button" class="rounded border px-4 py-2" @click="incidentAction = null">Cancel</button>
            </div>
        </form>

        <form v-if="incidentAction === 'action' && activeIncident !== null" class="grid gap-3 rounded-xl border bg-card p-5" @submit.prevent="submitAction">
            <h3 class="font-semibold">Assign corrective action · {{ activeIncident.reference }}</h3>
            <label class="grid gap-2 text-sm">
                Action
                <textarea v-model="actionForm.description" rows="3" maxlength="3000" required class="rounded border bg-background p-2"></textarea>
            </label>
            <label class="grid gap-2 text-sm">
                Owner
                <select v-model="actionForm.owner_id" required class="rounded border bg-background p-2">
                    <option value="" disabled>Select an owner</option>
                    <option v-for="owner in owners" :key="owner.id" :value="String(owner.id)">{{ owner.name }}</option>
                </select>
            </label>
            <label class="grid gap-2 text-sm">
                Due at
                <input v-model="actionForm.due_at" type="datetime-local" required class="rounded border bg-background p-2" />
            </label>
            <p v-for="(error, key) in actionForm.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
            <div class="flex gap-3">
                <button :disabled="actionForm.processing" class="rounded bg-primary px-4 py-2 text-primary-foreground">Assign action</button>
                <button type="button" class="rounded border px-4 py-2" @click="incidentAction = null">Cancel</button>
            </div>
        </form>

        <form v-if="incidentAction === 'resolve' && activeIncident !== null" class="grid gap-3 rounded-xl border bg-card p-5" @submit.prevent="submitResolve">
            <h3 class="font-semibold">Resolve {{ activeIncident.reference }}</h3>
            <p class="text-sm text-muted-foreground">
                Resolution requires every corrective action to be completed, and safety or privacy incidents need at least
                one independently verified action.
            </p>
            <label class="grid gap-2 text-sm">
                Resolution summary and evidence
                <textarea v-model="resolveForm.resolution_summary" rows="4" maxlength="5000" required class="rounded border bg-background p-2"></textarea>
            </label>
            <p v-for="(error, key) in resolveForm.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
            <div class="flex gap-3">
                <button :disabled="resolveForm.processing" class="rounded bg-primary px-4 py-2 text-primary-foreground">Resolve incident</button>
                <button type="button" class="rounded border px-4 py-2" @click="incidentAction = null">Cancel</button>
            </div>
        </form>


        <section class="space-y-3">
            <h2 class="text-lg font-semibold">Corrective actions</h2>
            <AdminTable
                :rows="actions.data"
                :columns="[
                    { key: 'incident', label: 'Incident' },
                    { key: 'owner', label: 'Owner' },
                    { key: 'description', label: 'Action' },
                    { key: 'due_at', label: 'Due' },
                    { key: 'status', label: 'Status' },
                    { key: 'verified_by', label: 'Verified by' },
                ]"
                :previous="actions.prev_page_url"
                :next="actions.next_page_url"
            >
                <template #actions="{ row }">
                    <div class="flex flex-wrap gap-2 text-sm">
                        <button v-if="row.status === 'open'" class="rounded border px-2 py-1" @click="startComplete(row)">Complete</button>
                        <button v-if="canManage && row.status === 'completed'" class="rounded border px-2 py-1" @click="startVerify(row)">Verify</button>
                        <span v-if="row.overdue" class="font-medium text-red-600">Overdue</span>
                    </div>
                </template>
            </AdminTable>

            <form v-if="actionMode === 'complete' && activeAction !== null" class="grid gap-3 rounded-xl border bg-card p-5" @submit.prevent="submitComplete">
                <h3 class="font-semibold">Complete action for {{ activeAction.incident }}</h3>
                <label class="grid gap-2 text-sm">
                    What was done
                    <textarea v-model="completeForm.completion_note" rows="3" maxlength="2000" required class="rounded border bg-background p-2"></textarea>
                </label>
                <p v-for="(error, key) in completeForm.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
                <div class="flex gap-3">
                    <button :disabled="completeForm.processing" class="rounded bg-primary px-4 py-2 text-primary-foreground">Complete action</button>
                    <button type="button" class="rounded border px-4 py-2" @click="actionMode = null">Cancel</button>
                </div>
            </form>

            <form v-if="actionMode === 'verify' && activeAction !== null" class="grid gap-3 rounded-xl border bg-card p-5" @submit.prevent="submitVerify">
                <h3 class="font-semibold">Verify action for {{ activeAction.incident }}</h3>
                <p class="text-sm text-muted-foreground">The verifier must differ from the action owner.</p>
                <label class="grid gap-2 text-sm">
                    Evidence that the action is effective
                    <textarea v-model="verifyForm.verification_note" rows="3" maxlength="2000" required class="rounded border bg-background p-2"></textarea>
                </label>
                <p v-for="(error, key) in verifyForm.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
                <div class="flex gap-3">
                    <button :disabled="verifyForm.processing" class="rounded bg-primary px-4 py-2 text-primary-foreground">Verify action</button>
                    <button type="button" class="rounded border px-4 py-2" @click="actionMode = null">Cancel</button>
                </div>
            </form>
        </section>

        <section v-if="evidence" class="space-y-3">
            <h2 class="text-lg font-semibold">Evidence access audit</h2>
            <AdminTable
                :rows="evidence.data"
                :columns="[
                    { key: 'incident', label: 'Incident' },
                    { key: 'user', label: 'Accessed by' },
                    { key: 'purpose', label: 'Purpose' },
                    { key: 'accessed_at', label: 'Accessed at' },
                ]"
                :previous="evidence.prev_page_url"
                :next="evidence.next_page_url"
            />
        </section>

        <form v-if="closing !== null" class="grid gap-3 rounded-xl border bg-card p-5" @submit.prevent="submitClose">
            <h3 class="font-semibold">Close {{ closing.reference }}</h3>
            <label class="flex items-start gap-2 text-sm">
                <input v-model="closeForm.attested" type="checkbox" required class="mt-1" />
                I confirm the incident is resolved, corrective actions are evidenced and the audit record is complete.
            </label>
            <p v-for="(error, key) in closeForm.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
            <div class="flex gap-3">
                <button :disabled="closeForm.processing" class="rounded bg-primary px-4 py-2 text-primary-foreground">Close incident</button>
                <button type="button" class="rounded border px-4 py-2" @click="closing = null">Cancel</button>
            </div>
        </form>

        <p class="text-sm text-muted-foreground">Snapshot as of {{ asOf }}</p>
    </div>
</template>

