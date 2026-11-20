<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { dashboard } from '@/routes';
import { dashboard as expDashboard } from '@/routes/exp';
import { activeCalls as liveActiveCalls } from '@/routes/live-operations';
import { useLiveSnapshot } from '@/composables/useLiveSnapshot';
import {
    formatClock,
    formatDuration,
    freshnessClasses,
    stageClasses,
    stageLabel,
} from '@/lib/live-operations';
import type { LiveFilters } from '@/types/live-operations';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});

const filters = ref<LiveFilters>({
    from: '',
    to: '',
    agent: '',
    destination: '',
    state: '',
});
const refreshSeconds = ref(3);
const paused = ref(false);

/**
 * The live console is guarded by `view pbx`. A read-only role that lacks it
 * still lands on this dashboard, so the page reads the same permission
 * instead of firing requests it knows will be refused.
 */
const page = usePage();
const canViewLive = computed<boolean>(() => {
    const auth = page.props.auth as { permissions?: string[] } | undefined;

    return (auth?.permissions ?? []).includes('view pbx');
});

const { snapshot, error, loading } = useLiveSnapshot(
    filters,
    refreshSeconds,
    paused,
    canViewLive,
);

const kpis = computed(() => [
    { label: 'Inbound journeys', value: snapshot.value?.kpis.inbound ?? 0 },
    { label: 'Answered by agent', value: snapshot.value?.kpis.answered ?? 0 },
    { label: 'Dentist ringing', value: snapshot.value?.kpis.dentist_ringing ?? 0 },
    { label: 'Active now', value: snapshot.value?.kpis.active ?? 0 },
]);
</script>

<template>
    <div class="min-h-full bg-slate-50 p-5 dark:bg-slate-950 sm:p-8">
        <Head title="Dashboard" />

        <div class="mx-auto max-w-7xl space-y-7">
            <header class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p
                        class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700"
                    >
                        Pepsodent · Talk to a Dentist
                    </p>
                    <h1
                        class="mt-2 text-3xl font-semibold tracking-tight text-slate-950 dark:text-white"
                    >
                        Operations overview
                    </h1>
                    <p class="mt-2 text-sm text-slate-500">
                        Live enquiry, private handover and consultation activity.
                        Network answer does not confirm a human dentist accepted
                        the call.
                    </p>
                </div>
                <Link
                    v-if="canViewLive"
                    :href="expDashboard()"
                    class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-800"
                >
                    Open live console <span aria-hidden="true">→</span>
                </Link>
            </header>

            <div
                v-if="!canViewLive"
                role="status"
                class="rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-600 shadow-sm dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300"
            >
                You have read-only access. Live call activity is available to
                operational roles only.
            </div>

            <div
                v-if="canViewLive && error"
                role="status"
                class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200"
            >
                {{ error }}
            </div>
            <div
                v-if="canViewLive && snapshot?.connection?.last_error"
                role="status"
                class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100"
            >
                AMI listener: {{ snapshot.connection.last_error }}
            </div>

            <template v-if="canViewLive">
                <section
                    class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                    aria-label="Live operations metrics"
                >
                <article
                    v-for="kpi in kpis"
                    :key="kpi.label"
                    class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900"
                >
                    <p class="text-sm text-slate-500">{{ kpi.label }}</p>
                    <p
                        class="mt-3 text-3xl font-semibold text-slate-950 dark:text-white"
                    >
                        {{ loading && !snapshot ? '—' : kpi.value }}
                    </p>
                    <p class="mt-2 text-xs text-slate-400">Current window</p>
                </article>
            </section>

            <section class="grid gap-5 lg:grid-cols-[0.8fr_1.2fr]">
                <article
                    class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm text-slate-500">
                                Telephony integration
                            </p>
                            <h2
                                class="mt-1 text-lg font-semibold text-slate-950 dark:text-white"
                            >
                                VitalPBX AMI
                            </h2>
                        </div>
                        <span
                            class="rounded-full px-3 py-1.5 text-xs font-semibold uppercase"
                            :class="
                                freshnessClasses(
                                    snapshot?.freshness.state ?? 'offline',
                                )
                            "
                        >
                            {{ snapshot?.freshness.state ?? 'checking' }}
                        </span>
                    </div>
                    <dl class="mt-6 space-y-4">
                        <div
                            class="flex justify-between gap-3 border-t border-slate-100 pt-4 dark:border-slate-800"
                        >
                            <dt class="text-sm text-slate-500">Last heartbeat</dt>
                            <dd
                                class="text-right text-sm font-medium text-slate-800 dark:text-slate-200"
                            >
                                {{
                                    formatClock(
                                        snapshot?.connection?.last_heartbeat_at,
                                    )
                                }}
                            </dd>
                        </div>
                        <div
                            class="flex justify-between gap-3 border-t border-slate-100 pt-4 dark:border-slate-800"
                        >
                            <dt class="text-sm text-slate-500">Last PBX event</dt>
                            <dd
                                class="text-right text-sm font-medium text-slate-800 dark:text-slate-200"
                            >
                                {{
                                    formatClock(snapshot?.connection?.last_event_at)
                                }}
                            </dd>
                        </div>
                        <div
                            class="flex justify-between gap-3 border-t border-slate-100 pt-4 dark:border-slate-800"
                        >
                            <dt class="text-sm text-slate-500">Agent presence</dt>
                            <dd
                                class="text-right text-sm font-medium text-slate-800 dark:text-slate-200"
                            >
                                {{ snapshot?.agent_presence.state ?? 'unknown' }}
                                (inferred)
                            </dd>
                        </div>
                    </dl>
                    <p
                        class="mt-6 rounded-xl bg-slate-50 p-3 text-xs leading-5 text-slate-500 dark:bg-slate-800/60"
                    >
                        Phone numbers are masked and no recordings are available.
                        Missing hangups keep a journey active until
                        reconciliation.
                    </p>
                </article>


                <article
                    class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900"
                >
                    <div
                        class="flex items-center justify-between gap-4 border-b border-slate-100 px-5 py-4 dark:border-slate-800"
                    >
                        <div>
                            <h2 class="font-semibold text-slate-950 dark:text-white">
                                Active calls
                            </h2>
                            <p class="mt-1 text-xs text-slate-500">
                                Updates every {{ refreshSeconds }} seconds
                            </p>
                        </div>
                        <Link
                            :href="liveActiveCalls()"
                            class="text-sm font-semibold text-emerald-700 hover:text-emerald-800"
                            >View all</Link
                        >
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead
                                class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-800/60"
                            >
                                <tr>
                                    <th class="px-5 py-3">Journey</th>
                                    <th class="px-5 py-3">Stage</th>
                                    <th class="px-5 py-3">Agent</th>
                                    <th class="px-5 py-3">Duration</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="call in (
                                        snapshot?.active_calls ?? []
                                    ).slice(0, 6)"
                                    :key="call.id"
                                    class="border-t border-slate-100 dark:border-slate-800"
                                >
                                    <td class="px-5 py-3 font-medium">
                                        #{{ call.id }}
                                    </td>
                                    <td class="px-5 py-3">
                                        <span
                                            class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                            :class="stageClasses(call.stage)"
                                            >{{ stageLabel(call.stage) }}</span
                                        >
                                    </td>
                                    <td class="px-5 py-3 font-mono text-xs">
                                        {{ call.agent_channel ?? '—' }}
                                    </td>
                                    <td class="px-5 py-3">
                                        {{ formatDuration(call.duration_seconds) }}
                                    </td>
                                </tr>
                                <tr v-if="(snapshot?.active_calls ?? []).length === 0">
                                    <td
                                        colspan="4"
                                        class="px-5 py-10 text-center text-slate-500"
                                    >
                                        No active calls right now.
                                    </td>
                                </tr>
                                <tr v-if="loading && !snapshot">
                                    <td
                                        colspan="4"
                                        class="px-5 py-10 text-center text-slate-500"
                                    >
                                        Connecting to the live feed…
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </article>
            </section>
            </template>
        </div>
    </div>
</template>

