<script setup lang="ts">
import { reactive, ref } from 'vue';
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import AdminTable from '@/components/AdminTable.vue';
import { index, show } from '@/routes/transfers';

defineOptions({ layout: AppLayout });
type DentistSummary = Record<string, unknown> & { dentist_name: string; attempts: number; network_answers: number; human_acceptances: number; avg_dial_to_answer_seconds: number | null; avg_ringing_seconds: number | null; mobile_connected_seconds: number | null; consultation_seconds: number | null };
type FailureSummary = Record<string, unknown> & { status: string; failure_reason: string; attempts: number };
const props = defineProps<{
    attempts: { data: (Record<string, unknown> & { dentist: { name: string } | null })[]; prev_page_url: string | null; next_page_url: string | null };
    summary: Record<string, number | string | null>; filters: { view: string; days: number; status?: string; match?: string; interaction_id?: string }; statuses: string[]; matches: string[];
    analytics: { dentists: DentistSummary[]; failures: FailureSummary[] } | null; asOf: string;
}>();
const filters = reactive({ view: props.filters.view, days: props.filters.days, status: props.filters.status ?? '', match: props.filters.match ?? '', interaction_id: props.filters.interaction_id ?? '' });
const polling = ref(props.filters.view === 'live');
const poll = usePoll(3000, { only: ['attempts', 'summary', 'analytics', 'asOf'] }, { autoStart: polling.value });
function toggle() { polling.value = !polling.value; if (polling.value) poll.start(); else poll.stop(); }
function search() { router.get(index.url(), { ...filters }); }
function label(value: string) { return value.replaceAll('_', ' '); }
</script>

<template>
    <div class="space-y-6 p-6">
        <Head title="Transfer tracking" />
        <Heading title="Transfers and consultations" description="Observed mobile-leg evidence and operator-confirmed outcomes. Network answer and a caller bridge do not establish that a human dentist accepted the call." />
        <nav class="flex flex-wrap gap-4 text-sm"><Link v-for="view in ['live', 'attempts', 'consultations', 'failures', 'analytics']" :key="view" :href="index({ query: { view } })" :class="{ 'font-bold underline': filters.view === view }">{{ label(view) }}</Link></nav>
        <div class="flex flex-wrap items-center gap-4 text-sm"><button type="button" class="rounded border px-3 py-2" @click="toggle">{{ polling ? 'Pause updates' : 'Resume updates' }}</button><button type="button" class="rounded border px-3 py-2" @click="router.reload({ only: ['attempts', 'summary', 'analytics', 'asOf'] })">Refresh</button><span>As of {{ asOf }}</span></div>
        <form class="flex flex-wrap items-end gap-4" @submit.prevent="search">
            <label class="grid gap-2">Window (days)<input v-model="filters.days" type="number" required min="1" max="365" class="w-24 rounded border bg-background p-2" /></label>
            <label class="grid gap-2">Outcome<select v-model="filters.status" class="rounded border bg-background p-2"><option value="">All</option><option v-for="status in statuses" :key="status" :value="status">{{ label(status) }}</option></select></label>
            <label class="grid gap-2">Number match<select v-model="filters.match" class="rounded border bg-background p-2"><option value="">All</option><option v-for="match in matches" :key="match" :value="match">{{ label(match) }}</option></select></label>
            <label class="grid gap-2">Interaction<input v-model="filters.interaction_id" type="number" min="1" class="w-28 rounded border bg-background p-2" /></label>
            <button class="rounded border px-4 py-2">Apply</button>
        </form>
        <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"><article v-for="(value, key) in summary" :key="key" class="space-y-2 rounded-xl border bg-card p-4"><h2 class="text-xs uppercase text-muted-foreground">{{ label(String(key)) }}</h2><p class="text-xl font-semibold">{{ value === null ? 'Not observed' : typeof value === 'number' ? Math.round(value * 10) / 10 : value }}</p></article></section>
        <p class="text-sm text-muted-foreground">Averages exclude missing observations. Connected time includes private handover and other connected periods; consultation time counts only verified caller/mobile bridge intervals. Observed consultation totals include unconfirmed and voicemail bridges and must not be reported as confirmed human consultations.</p>
        <section v-if="filters.view === 'analytics' && analytics" class="space-y-5">
            <div class="space-y-3 rounded-xl border bg-card p-5">
                <h2 class="text-lg font-semibold">Dentist outcomes in this window</h2>
                <p class="text-sm text-muted-foreground">Grouped by the directory match observed on the attempt. Only an operator-confirmed acceptance counts as human acceptance; a network answer alone does not. Averages and totals exclude missing observations.</p>
                <p v-if="analytics.dentists.length === 0" class="text-sm text-muted-foreground">No transfer attempts were observed in this window.</p>
                <AdminTable v-else :rows="analytics.dentists" :columns="[{ key: 'dentist_name', label: 'Directory dentist' }, { key: 'attempts', label: 'Attempts' }, { key: 'network_answers', label: 'Network answers' }, { key: 'human_acceptances', label: 'Human acceptances' }, { key: 'avg_dial_to_answer_seconds', label: 'Avg dial → answer (s)' }, { key: 'avg_ringing_seconds', label: 'Avg observed ring (s)' }, { key: 'mobile_connected_seconds', label: 'Connected (s)' }, { key: 'consultation_seconds', label: 'Caller bridge (s)' }]" />
            </div>
            <div class="space-y-3 rounded-xl border bg-card p-5">
                <h2 class="text-lg font-semibold">Failed attempt reasons</h2>
                <p class="text-sm text-muted-foreground">Busy, unanswered, cancelled and failed outcomes come from PBX dial evidence. Voicemail and declined outcomes require operator confirmation. Missing PBX reasons remain unclassified rather than being inferred.</p>
                <p v-if="analytics.failures.length === 0" class="text-sm text-muted-foreground">No busy, unanswered, cancelled, failed, voicemail or declined attempts in this window.</p>
                <AdminTable v-else :rows="analytics.failures" :columns="[{ key: 'status', label: 'Outcome' }, { key: 'failure_reason', label: 'Failure evidence' }, { key: 'attempts', label: 'Attempts' }]" />
            </div>
        </section>
        <AdminTable :rows="attempts.data.map((row) => ({ ...row, dentist_name: row.dentist?.name ?? 'Unmatched' }))" :columns="[{ key: 'id', label: 'Attempt' }, { key: 'interaction_id', label: 'Interaction' }, { key: 'sequence', label: 'Try' }, { key: 'dentist_name', label: 'Directory dentist' }, { key: 'destination_masked', label: 'Destination' }, { key: 'match_status', label: 'Match' }, { key: 'status', label: 'Outcome' }, { key: 'acceptance_confidence', label: 'Human acceptance' }, { key: 'dial_to_answer_seconds', label: 'Dial → answer (s)' }, { key: 'ringing_seconds', label: 'Observed ring (s)' }, { key: 'mobile_connected_seconds', label: 'Connected (s)' }, { key: 'private_handover_seconds', label: 'Handover (s)' }, { key: 'consultation_seconds', label: 'Caller bridge (s)' }, { key: 'fallback_status', label: 'Fallback intent' }]" :previous="attempts.prev_page_url" :next="attempts.next_page_url"><template #actions="{ row }"><Link :href="show(Number(row.id))">Evidence and confirmation</Link></template></AdminTable>
    </div>
</template>
