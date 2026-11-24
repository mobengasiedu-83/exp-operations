<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowUpRight, AudioLines, Headphones, PhoneIncoming, Radio, Stethoscope } from '@lucide/vue';
import OperationsArtwork from '@/components/exp/OperationsArtwork.vue';
import StatTile from '@/components/exp/StatTile.vue';
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
 * The overview is rendered for every role (`view operations overview`), so the
 * controller decides both flags: the overview data itself, and whether the
 * deeper live console — still guarded by `view pbx` — can be opened.
 */
const props = defineProps<{
    canViewOverview: boolean;
    canOpenConsole: boolean;
}>();

const overviewEnabled = computed<boolean>(() => props.canViewOverview);

const { snapshot, error, loading } = useLiveSnapshot(
    filters,
    refreshSeconds,
    paused,
    overviewEnabled,
);

const kpis = computed(() => [
    { label: 'Inbound journeys', icon: PhoneIncoming, tone: 'emerald' as const, value: snapshot.value?.kpis.inbound ?? 0 },
    { label: 'Answered by agent', icon: Headphones, tone: 'sky' as const, value: snapshot.value?.kpis.answered ?? 0 },
    { label: 'Dentist ringing', icon: Stethoscope, tone: 'amber' as const, value: snapshot.value?.kpis.dentist_ringing ?? 0 },
    { label: 'Active now', icon: AudioLines, tone: 'emerald' as const, value: snapshot.value?.kpis.active ?? 0 },
]);
</script>

<template>
    <div
        class="min-h-full bg-gradient-to-b from-teal-50/70 via-background to-background p-4 sm:p-7 lg:p-9 dark:from-teal-950/25 dark:via-background dark:to-background"
    >
        <Head title="Dashboard" />

        <div class="mx-auto max-w-7xl space-y-7">
            <header
                class="exp-rise relative isolate overflow-hidden rounded-[1.75rem] bg-gradient-to-br from-teal-700 via-teal-800 to-emerald-900 p-6 text-white shadow-xl shadow-teal-950/20 sm:p-9 lg:p-11 dark:from-teal-800 dark:via-teal-900 dark:to-emerald-950"
            >
                <div
                    class="exp-grid pointer-events-none absolute inset-0 -z-10 opacity-20"
                    aria-hidden="true"
                />
                <div
                    class="exp-drift pointer-events-none absolute -top-24 -right-16 size-72 rounded-full bg-teal-300/25 blur-3xl"
                    aria-hidden="true"
                />
                <div class="relative z-10 max-w-xl lg:max-w-[62%]">
                    <span class="inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/5 px-3 py-1.5 text-[10px] font-semibold tracking-[.18em] text-teal-100 uppercase">
                        <span class="size-1.5 rounded-full bg-teal-200" /> Pepsodent · Talk to a Dentist
                    </span>
                    <h1 class="mt-6 text-3xl leading-[1.08] font-semibold tracking-[-.045em] sm:text-4xl lg:text-5xl">Better connections.<br /><span class="text-teal-200">More reasons to smile.</span></h1>
                    <p class="mt-4 max-w-md text-sm leading-7 text-teal-50/75">Your operations, together in one place. Follow every enquiry, support your agents, and connect callers with care.</p>
                    <Link v-if="canOpenConsole" :href="expDashboard()" class="group mt-6 inline-flex items-center gap-4 rounded-xl bg-white px-5 py-3 text-sm font-semibold text-teal-950 shadow-lg shadow-black/10 transition duration-300 hover:bg-teal-50 motion-safe:hover:-translate-y-0.5">
                        Open live console <ArrowUpRight class="size-4 transition-transform motion-safe:group-hover:translate-x-0.5 motion-safe:group-hover:-translate-y-0.5" />
                    </Link>
                </div>
                <OperationsArtwork class="absolute top-1/2 right-5 hidden -translate-y-1/2 lg:block" />
                <div class="relative z-10 mt-8 flex items-center gap-2 border-t border-white/15 pt-4 text-xs text-teal-100/70"><Radio class="size-3.5 shrink-0" /> Network answer does not confirm a human dentist accepted the call.</div>
            </header>

            <div
                v-if="!canViewOverview"
                role="status"
                class="rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-600 shadow-sm dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300"
            >
                The operations overview is not enabled for this account yet.
                Ask an administrator to grant the operations-overview
                permission.
            </div>

            <div
                v-if="canViewOverview && error"
                role="status"
                class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200"
            >
                {{ error }}
            </div>
            <div
                v-if="canViewOverview && snapshot?.connection?.last_error"
                role="status"
                class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100"
            >
                AMI listener: {{ snapshot.connection.last_error }}
            </div>

            <template v-if="canViewOverview">
                <section
                    class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                    aria-label="Live operations metrics"
                >
                    <StatTile v-for="(kpi, index) in kpis" :key="kpi.label" :label="kpi.label" :value="loading && !snapshot ? '—' : kpi.value" :icon="kpi.icon" :tone="kpi.tone" :delay="(index + 1) as 1 | 2 | 3 | 4" hint="Current reporting window" />
            </section>

            <section class="grid gap-5 lg:grid-cols-[0.8fr_1.2fr]">
                <article
                    class="exp-rise exp-rise-3 rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900"
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
                            v-if="canOpenConsole"
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
                                        class="px-5 py-14 text-center text-slate-500"
                                    >
                                        <div class="mx-auto mb-3 flex size-12 items-center justify-center rounded-2xl bg-teal-50 text-teal-600 dark:bg-teal-950 dark:text-teal-300"><Headphones class="size-6" :stroke-width="1.5" /></div>
                                        <p class="font-medium text-slate-700 dark:text-slate-200">All quiet for now</p>
                                        <p class="mt-1 text-xs">New active calls will appear here automatically.</p>
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

