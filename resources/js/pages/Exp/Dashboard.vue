<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import { useLiveSnapshot } from '@/composables/useLiveSnapshot';
import {
    formatClock,
    formatDuration,
    formatSeconds,
    freshnessClasses,
    severityClasses,
    stageClasses,
    stageLabel,
} from '@/lib/live-operations';
import { preferences } from '@/routes/exp';
import {
    activeCalls as activeCallsPage,
    alerts as alertsPage,
    transfers as transfersPage,
} from '@/routes/live-operations';
import { acknowledge as acknowledgeAlert } from '@/routes/live-operations/alerts';
import { show as showJourney } from '@/routes/calls';
import type { LiveFilters } from '@/types/live-operations';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    refreshSeconds: number;
    refreshBounds: { min: number; max: number };
    filters: LiveFilters;
    canAcknowledge: boolean;
    canSavePreferences: boolean;
    canOpenLiveDetails: boolean;
}>();

const filters = ref<LiveFilters>({ ...props.filters });
const refreshSeconds = ref(props.refreshSeconds);
const paused = ref(false);
const saving = ref(false);

const { snapshot, error, loading, refresh } = useLiveSnapshot(
    filters,
    refreshSeconds,
    paused,
);

const kpis = computed(() => [
    { label: 'Inbound', value: snapshot.value?.kpis.inbound ?? 0 },
    { label: 'Answered by agent', value: snapshot.value?.kpis.answered ?? 0 },
    { label: 'Abandoned', value: snapshot.value?.kpis.abandoned ?? 0 },
    { label: 'Active journeys', value: snapshot.value?.kpis.active ?? 0 },
    { label: 'Dentist ringing', value: snapshot.value?.kpis.dentist_ringing ?? 0 },
    {
        label: 'Private handovers',
        value: snapshot.value?.kpis.private_handovers_active ?? 0,
    },
    {
        label: 'Consultations',
        value: snapshot.value?.kpis.consultations_active ?? 0,
    },
    { label: 'Completed', value: snapshot.value?.kpis.completed ?? 0 },
]);

const presenceLabel = computed(() => {
    const state = snapshot.value?.agent_presence.state;

    return state === 'on_call'
        ? 'Agent on a call'
        : state === 'recently_active'
          ? 'Agent recently active'
          : 'Agent presence unknown';
});

function applyFilters() {
    void refresh();
}

function clearFilters() {
    filters.value = {
        from: '',
        to: '',
        agent: '',
        destination: '',
        state: '',
    };
    void refresh();
}

function saveView() {
    if (!props.canSavePreferences) {
        return;
    }

    saving.value = true;
    router.patch(
        preferences.url(),
        { filters: filters.value, refresh_seconds: refreshSeconds.value },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                saving.value = false;
            },
        },
    );
}

function acknowledge(id: number) {
    router.post(acknowledgeAlert.url(id), {}, { preserveScroll: true });
}
</script>

<template>
    <div>
        <Head title="EXP Operations" />
        <div class="space-y-6 p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading
                    title="EXP Operations Console"
                    description="Live visibility of inbound enquiries, private handovers, dentist ringing and consultations. Network answer does not confirm a human dentist accepted the call."
                />
                <div
                    class="rounded-xl border bg-white px-4 py-3 shadow-sm dark:bg-slate-900"
                >
                    <span class="text-sm text-slate-500">VitalPBX AMI</span>
                    <span
                        class="ml-3 rounded-full px-3 py-1 text-xs font-semibold uppercase"
                        :class="
                            freshnessClasses(
                                snapshot?.freshness.state ?? 'offline',
                            )
                        "
                    >
                        {{ snapshot?.freshness.state ?? 'loading' }}
                    </span>
                    <p class="mt-2 text-xs text-slate-500">
                        Heartbeat:
                        {{
                            formatClock(
                                snapshot?.connection?.last_heartbeat_at ?? null,
                            )
                        }}
                        · Stale after
                        {{ snapshot?.freshness.stale_after_seconds ?? '—' }}s
                    </p>
                    <p
                        v-if="snapshot?.connection?.last_error"
                        class="mt-1 max-w-xs text-xs text-rose-600"
                    >
                        {{ snapshot.connection.last_error }}
                    </p>
                </div>
            </div>

            <div
                v-if="error"
                class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"
            >
                {{ error }}
            </div>

            <div
                v-if="snapshot && snapshot.freshness.state !== 'live'"
                class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
            >
                PBX data is {{ snapshot.freshness.state }} — heartbeat
                {{ formatSeconds(snapshot.freshness.heartbeat_age_seconds) }}
                old, last event
                {{ formatSeconds(snapshot.freshness.last_event_age_seconds) }}
                old. Values below may be incomplete.
            </div>

            <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div
                    v-for="kpi in kpis"
                    :key="kpi.label"
                    class="rounded-xl border bg-white p-4 shadow-sm dark:bg-slate-900"
                >
                    <p class="text-xs font-medium uppercase text-slate-500">
                        {{ kpi.label }}
                    </p>
                    <p
                        class="mt-1 text-2xl font-semibold text-slate-900 dark:text-white"
                    >
                        {{ kpi.value }}
                    </p>
                </div>
            </section>

            <section
                class="space-y-4 rounded-xl border bg-white p-4 shadow-sm dark:bg-slate-900"
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="font-semibold">Live filters</h2>
                    <div class="flex flex-wrap items-center gap-3 text-sm">
                        <label class="flex items-center gap-2 text-slate-600 dark:text-slate-300">
                            Refresh
                            <select
                                v-model.number="refreshSeconds"
                                class="rounded border bg-background p-1.5"
                            >
                                <option
                                    v-for="seconds in [2, 3, 4, 5]"
                                    :key="seconds"
                                    :value="seconds"
                                    :disabled="
                                        seconds < refreshBounds.min ||
                                        seconds > refreshBounds.max
                                    "
                                >
                                    {{ seconds }}s
                                </option>
                            </select>
                        </label>
                        <label class="flex items-center gap-2 text-slate-600 dark:text-slate-300">
                            <input v-model="paused" type="checkbox" />
                            Pause
                        </label>
                        <button
                            type="button"
                            class="rounded border px-3 py-1.5"
                            :disabled="loading"
                            @click="refresh"
                        >
                            {{ loading ? 'Refreshing…' : 'Refresh now' }}
                        </button>
                        <button
                            v-if="canSavePreferences"
                            type="button"
                            class="rounded bg-emerald-700 px-3 py-1.5 font-semibold text-white"
                            :disabled="saving"
                            @click="saveView"
                        >
                            {{ saving ? 'Saving…' : 'Save view' }}
                        </button>
                    </div>
                </div>

                <form
                    class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
                    @submit.prevent="applyFilters"
                >
                    <label class="grid gap-1.5 text-xs font-medium text-slate-600 dark:text-slate-300">
                        From
                        <input
                            v-model="filters.from"
                            type="datetime-local"
                            class="h-10 rounded border bg-background p-2 text-sm"
                        />
                    </label>
                    <label class="grid gap-1.5 text-xs font-medium text-slate-600 dark:text-slate-300">
                        To
                        <input
                            v-model="filters.to"
                            type="datetime-local"
                            class="h-10 rounded border bg-background p-2 text-sm"
                        />
                    </label>
                    <label class="grid gap-1.5 text-xs font-medium text-slate-600 dark:text-slate-300">
                        Agent extension
                        <select
                            v-model="filters.agent"
                            class="h-10 rounded border bg-background p-2 text-sm"
                        >
                            <option value="">All agents</option>
                            <option
                                v-for="agent in snapshot?.options.agents ?? []"
                                :key="agent"
                                :value="agent"
                            >
                                {{ agent }}
                            </option>
                        </select>
                    </label>
                    <label class="grid gap-1.5 text-xs font-medium text-slate-600 dark:text-slate-300">
                        Destination
                        <select
                            v-model="filters.destination"
                            class="h-10 rounded border bg-background p-2 text-sm"
                        >
                            <option value="">All destinations</option>
                            <option
                                v-for="destination in snapshot?.options.destinations ?? []"
                                :key="destination"
                                :value="destination"
                            >
                                {{ destination }}
                            </option>
                        </select>
                    </label>
                    <label class="grid gap-1.5 text-xs font-medium text-slate-600 dark:text-slate-300">
                        Journey state
                        <select
                            v-model="filters.state"
                            class="h-10 rounded border bg-background p-2 text-sm"
                        >
                            <option value="">All states</option>
                            <option value="active">Active</option>
                            <option value="completed">Completed</option>
                            <option value="abandoned">Abandoned</option>
                        </select>
                    </label>
                    <div class="flex items-end gap-2">
                        <button
                            type="submit"
                            class="h-10 rounded bg-slate-900 px-4 text-sm font-semibold text-white dark:bg-slate-100 dark:text-slate-900"
                        >
                            Apply filters
                        </button>
                        <button
                            type="button"
                            class="h-10 rounded border px-4 text-sm"
                            @click="clearFilters"
                        >
                            Clear
                        </button>
                    </div>
                </form>
                <p class="text-xs text-slate-500">
                    Window {{ formatClock(snapshot?.window.from_iso) }} —
                    {{ formatClock(snapshot?.window.to_iso) }} · generated
                    {{ formatClock(snapshot?.generated_at) }}
                </p>
            </section>


            <div class="grid gap-6 lg:grid-cols-3">
                <section
                    class="space-y-3 rounded-xl border bg-white p-4 shadow-sm lg:col-span-2 dark:bg-slate-900"
                >
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="font-semibold">Active calls</h2>
                        <Link
                            v-if="canOpenLiveDetails"
                            :href="activeCallsPage.url({ query: filters })"
                            class="text-sm text-emerald-700"
                            >Open active calls →</Link
                        >
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-xs uppercase text-slate-500">
                                <tr>
                                    <th class="py-2 pr-3">Journey</th>
                                    <th class="py-2 pr-3">Stage</th>
                                    <th class="py-2 pr-3">Agent</th>
                                    <th class="py-2 pr-3">Destination</th>
                                    <th class="py-2 pr-3">Duration</th>
                                    <th class="py-2 pr-3">Flags</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="call in snapshot?.active_calls ?? []"
                                    :key="call.id"
                                    class="border-t"
                                >
                                    <td class="py-2 pr-3">
                                        <Link
                                            :href="showJourney(call.id)"
                                            class="font-medium text-emerald-700"
                                            >#{{ call.id }}</Link
                                        >
                                    </td>
                                    <td class="py-2 pr-3">
                                        <span
                                            class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                            :class="stageClasses(call.stage)"
                                            >{{ stageLabel(call.stage) }}</span
                                        >
                                    </td>
                                    <td class="py-2 pr-3 font-mono text-xs">
                                        {{ call.agent_channel ?? '—' }}
                                    </td>
                                    <td class="py-2 pr-3 font-mono text-xs">
                                        {{ call.destination_channel ?? '—' }}
                                    </td>
                                    <td class="py-2 pr-3">
                                        {{ formatDuration(call.duration_seconds) }}
                                    </td>
                                    <td class="py-2 pr-3">
                                        {{ call.exceptions }}
                                    </td>
                                </tr>
                                <tr v-if="(snapshot?.active_calls ?? []).length === 0">
                                    <td
                                        colspan="6"
                                        class="py-6 text-center text-slate-500"
                                    >
                                        No active calls in this window.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>


                <section
                    class="space-y-3 rounded-xl border bg-white p-4 shadow-sm dark:bg-slate-900"
                >
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="font-semibold">Alerts</h2>
                        <Link
                            v-if="canOpenLiveDetails"
                            :href="alertsPage.url()"
                            class="text-sm text-emerald-700"
                            >All alerts →</Link
                        >
                    </div>
                    <p class="text-xs text-slate-500">
                        {{ snapshot?.alert_counts.open ?? 0 }} open ·
                        {{ snapshot?.alert_counts.acknowledged ?? 0 }} acknowledged
                    </p>
                    <ul class="space-y-3">
                        <li
                            v-for="alert in snapshot?.alerts ?? []"
                            :key="alert.id"
                            class="rounded-lg border p-3"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <p class="text-sm font-medium">
                                        {{ alert.name ?? alert.rule ?? 'Alert' }}
                                    </p>
                                    <p class="text-xs text-slate-500">
                                        {{ alert.subject ?? 'PBX' }} · seen
                                        {{ formatClock(alert.last_seen_at) }}
                                    </p>
                                </div>
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                    :class="severityClasses(alert.severity)"
                                    >{{ alert.severity }}</span
                                >
                            </div>
                            <div class="mt-2 flex items-center justify-between gap-2">
                                <span class="text-xs uppercase text-slate-500">{{
                                    alert.status
                                }}</span>
                                <button
                                    v-if="canAcknowledge && alert.status === 'open'"
                                    type="button"
                                    class="rounded border px-2 py-1 text-xs"
                                    @click="acknowledge(alert.id)"
                                >
                                    Acknowledge
                                </button>
                            </div>
                        </li>
                        <li
                            v-if="(snapshot?.alerts ?? []).length === 0"
                            class="text-sm text-slate-500"
                        >
                            No open alerts.
                        </li>
                    </ul>
                </section>
            </div>


            <section
                class="space-y-3 rounded-xl border bg-white p-4 shadow-sm dark:bg-slate-900"
            >
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h2 class="font-semibold">Transfer status</h2>
                        <p class="text-xs text-slate-500">
                            Mobile (dentist) legs for journeys in this window ·
                            ringing and answer timings in seconds.
                        </p>
                    </div>
                    <Link
                        v-if="canOpenLiveDetails"
                        :href="transfersPage.url({ query: filters })"
                        class="text-sm text-emerald-700"
                        >Open transfers →</Link
                    >
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase text-slate-500">
                            <tr>
                                <th class="py-2 pr-3">Journey</th>
                                <th class="py-2 pr-3">Channel</th>
                                <th class="py-2 pr-3">Dial status</th>
                                <th class="py-2 pr-3">Ringing</th>
                                <th class="py-2 pr-3">Answered</th>
                                <th class="py-2 pr-3">Ring time</th>
                                <th class="py-2 pr-3">Dial → answer</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="transfer in snapshot?.transfers ?? []"
                                :key="transfer.id"
                                class="border-t"
                            >
                                <td class="py-2 pr-3">
                                    <Link
                                        :href="showJourney(transfer.interaction_id)"
                                        class="font-medium text-emerald-700"
                                        >#{{ transfer.interaction_id }}</Link
                                    >
                                </td>
                                <td class="py-2 pr-3 font-mono text-xs">
                                    {{ transfer.channel ?? '—' }}
                                </td>
                                <td class="py-2 pr-3">
                                    {{ transfer.dial_status ?? '—' }}
                                </td>
                                <td class="py-2 pr-3">
                                    {{ formatClock(transfer.ringing_at) }}
                                </td>
                                <td class="py-2 pr-3">
                                    {{ formatClock(transfer.answered_at) }}
                                </td>
                                <td class="py-2 pr-3">
                                    {{ formatSeconds(transfer.ringing_for_seconds) }}
                                </td>
                                <td class="py-2 pr-3">
                                    {{ formatSeconds(transfer.dial_to_answer_seconds) }}
                                </td>
                            </tr>
                            <tr v-if="(snapshot?.transfers ?? []).length === 0">
                                <td colspan="7" class="py-6 text-center text-slate-500">
                                    No transfers recorded in this window.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="grid gap-6 lg:grid-cols-2">
                <div
                    class="space-y-2 rounded-xl border bg-white p-4 shadow-sm dark:bg-slate-900"
                >
                    <h2 class="font-semibold">Agent presence</h2>
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        {{ presenceLabel }} · extension
                        {{ snapshot?.agent_presence.extension ?? '—' }}
                    </p>
                    <p class="text-xs text-slate-500">
                        Inferred from screening agent call legs; no desk-phone
                        presence feed is ingested yet (as of
                        {{ formatClock(snapshot?.agent_presence.as_of) }}).
                    </p>
                </div>
                <div
                    class="space-y-2 rounded-xl border bg-white p-4 shadow-sm dark:bg-slate-900"
                >
                    <h2 class="font-semibold">Data contract</h2>
                    <ul class="list-disc space-y-1 pl-5 text-xs text-slate-500">
                        <li>Phone numbers are masked; no recordings or audio are available.</li>
                        <li>Network answer does not confirm a human dentist accepted the call.</li>
                        <li>Missing hangups appear as active journeys; timing basis is shown per call.</li>
                        <li>Alerts are evaluated every minute and on dashboard refresh.</li>
                    </ul>
                </div>
            </section>
        </div>
    </div>
</template>

