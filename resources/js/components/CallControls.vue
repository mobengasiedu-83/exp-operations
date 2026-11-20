<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { show, store } from '@/routes/call-control';
import { readXsrfToken } from '@/lib/screening-draft';

type Dentist = { id: number; name: string; availability_status: string; numbers: { id: number; masked: string }[] };
type Snapshot = {
    available: boolean; reason: string | null; state: string; version: string | null;
    actions: string[]; attempt_outcome?: string | null;
    pending: { action: string; status: string } | null;
    commands?: { request_key: string; action: string; status: string; destination_masked: string | null }[];
};
const props = defineProps<{ caseId: number; allowed: boolean; closed: boolean; emergency: boolean; dentists: Dentist[] }>();
const snapshot = ref<Snapshot | null>(null);
const numberId = ref('');
const humanAccepted = ref(false);
const posting = ref(false);
const error = ref('');
const states: Record<string, string> = { incoming: 'Incoming call', agent_connected: 'Agent connected', caller_held: 'Caller on hold', dentist_dialling: 'Dialling dentist', dentist_ringing: 'Dentist ringing', dentist_connected: 'Dentist connected', private_briefing: 'Private briefing', caller_dentist_bridge: 'Caller and dentist connected', completed: 'Completed' };
const labels: Record<string, string> = {
    answer: 'Answer call', hold: 'Hold caller', resume: 'Resume caller', dial_dentist: 'Dial dentist',
    cancel_attempt: 'Cancel dentist attempt', begin_briefing: 'Begin private briefing',
    complete_transfer: 'Complete attended transfer', release_agent: 'Release agent',
};
let timer: ReturnType<typeof setInterval> | undefined;
let loading = false;
let intent: { fingerprint: string; key: string } | null = null;
const requests = new AbortController();
async function refresh() {
    if (!props.allowed || props.closed || loading || posting.value) return;
    loading = true;
    try {
        const response = await fetch(show.url(props.caseId), { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: requests.signal });
        if (!response.ok) throw new Error('Unable to verify controls. Use the existing Jitsi console.');
        snapshot.value = await response.json();
    } catch {
        snapshot.value = null;
        error.value = 'Control status is unavailable. Check the current call in Jitsi before trying again.';
    } finally { loading = false; }
}
async function command(action: string) {
    if (!snapshot.value?.available || posting.value || snapshot.value.pending) return;
    const data = {
        action, expected_version: snapshot.value.version, dentist_number_id: action === 'dial_dentist' ? Number(numberId.value) : undefined,
        human_accepted: action === 'complete_transfer' ? humanAccepted.value : undefined,
    };
    const fingerprint = JSON.stringify(data);
    if (intent?.fingerprint !== fingerprint) intent = { fingerprint, key: crypto.randomUUID() };
    posting.value = true;
    error.value = '';
    try {
        const response = await fetch(store.url(props.caseId), {
            method: 'POST', credentials: 'same-origin', signal: requests.signal,
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': readXsrfToken() },
            body: JSON.stringify({ ...data, request_key: intent!.key }),
        });
        const result = await response.json();
        if (!response.ok) throw new Error(response.status >= 500 ? 'Control service unavailable. Verify the current call before retrying.' : result.message ?? 'Command rejected. Refresh the call state.');
        snapshot.value = { ...snapshot.value!, actions: [], pending: { action, status: result.command.status } };
        intent = null;
        humanAccepted.value = false;
    } catch (failure) {
        snapshot.value = null;
        error.value = failure instanceof Error ? failure.message : 'Command result is unknown. Verify before retrying.';
    } finally { posting.value = false; await refresh(); }
}
onMounted(() => {
    void refresh();
    timer = setInterval(() => { if (!document.hidden) void refresh(); }, 3000);
});
onBeforeUnmount(() => { clearInterval(timer); requests.abort(); });
</script>

<template>
    <section class="space-y-4 rounded-xl border bg-card p-5" aria-live="polite">
        <h2 class="text-lg font-semibold">Call controls</h2>
        <p v-if="!allowed || closed">Continue using the existing Jitsi console. Browser controls require the assigned agent and call-control permission on an open case.</p>
        <template v-else>
            <p v-if="!snapshot?.available">{{ snapshot?.reason ?? 'Checking verified call state…' }}</p>
            <p>Jitsi remains the manual fallback. EXP continues recording call evidence.</p>
            <p v-if="error" role="alert" class="text-red-700 dark:text-red-300">{{ error }}</p>
            <template v-if="snapshot?.available">
                <p>Verified state: {{ states[snapshot.state] ?? snapshot.state }}</p>
                <p v-if="snapshot.attempt_outcome">Last dentist attempt: {{ snapshot.attempt_outcome.replaceAll('_', ' ') }}. Resume the caller or select another approved dentist.</p>
                <p v-if="snapshot.pending">{{ snapshot.pending.action.replaceAll('_', ' ') }}: {{ snapshot.pending.status }}. Waiting for verified PBX outcome; additional commands are blocked.</p>
                <label v-if="snapshot.actions.includes('dial_dentist')" class="grid gap-2">
                    Approved dentist destination
                    <select v-model="numberId" class="rounded border bg-background p-2" :disabled="posting || emergency">
                        <option value="">Select a dentist</option>
                        <optgroup v-for="dentist in dentists" :key="dentist.id" :label="`${dentist.name} · ${dentist.availability_status}`" :disabled="dentist.availability_status !== 'available'">
                            <option v-for="number in dentist.numbers" :key="number.id" :value="String(number.id)">{{ number.masked }}</option>
                        </optgroup>
                    </select>
                </label>
                <label v-if="snapshot.actions.includes('complete_transfer')" class="flex items-center gap-2">
                    <input v-model="humanAccepted" type="checkbox" /> I privately briefed a human dentist who accepted this caller.
                </label>
                <div class="flex flex-wrap gap-3">
                    <button v-for="action in snapshot.actions" :key="action" type="button" class="rounded border px-4 py-2 disabled:opacity-50"
                        :disabled="posting || (action === 'dial_dentist' && (!numberId || emergency)) || (action === 'complete_transfer' && (!humanAccepted || emergency))"
                        @click="command(action)">{{ labels[action] }}</button>
                </div>
                <ul v-if="snapshot.commands?.length" class="space-y-1 text-sm text-muted-foreground">
                    <li v-for="item in snapshot.commands" :key="item.request_key">{{ item.action.replaceAll('_', ' ') }} · {{ item.status }} {{ item.destination_masked ?? '' }}</li>
                </ul>
            </template>
            <button type="button" class="rounded border px-4 py-2" :disabled="posting" @click="refresh">Refresh controls</button>
        </template>
    </section>
</template>
