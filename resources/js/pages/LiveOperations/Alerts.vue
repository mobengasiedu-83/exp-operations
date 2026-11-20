<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import { formatClock, severityClasses } from '@/lib/live-operations';
import { alerts as alertsPage } from '@/routes/live-operations';
import { acknowledge as acknowledgeAlert } from '@/routes/live-operations/alerts';
import { show as showJourney } from '@/routes/calls';

defineOptions({ layout: AppLayout });

type AlertRow = {
    id: number;
    severity: string;
    status: string;
    subject: string | null;
    details: Record<string, unknown> | null;
    first_seen_at: string | null;
    last_seen_at: string | null;
    acknowledged_at: string | null;
    resolved_at: string | null;
    rule: { key: string; name: string; category: string } | null;
    interaction: { id: number; started_at: string | null; status: string } | null;
};

type RuleRow = {
    id: number;
    key: string;
    name: string;
    category: string;
    metric: string;
    threshold_seconds: number | null;
    severity: string;
    enabled: boolean;
};

const props = defineProps<{
    alerts: {
        data: AlertRow[];
        prev_page_url: string | null;
        next_page_url: string | null;
        total: number;
    };
    rules: RuleRow[];
    counts: { open: number; acknowledged: number; resolved_24h: number };
    filters: { status: string; severity: string };
    canAcknowledge: boolean;
    refreshSeconds: number;
}>();

const filters = reactive({
    status: props.filters.status ?? '',
    severity: props.filters.severity ?? '',
});
let timer: ReturnType<typeof setInterval> | undefined;

const openAlerts = computed(
    () => props.alerts.data.filter((alert) => alert.status === 'open').length,
);

function search() {
    router.get(alertsPage.url(), { ...filters }, { preserveState: true });
}

function clearFilters() {
    filters.status = '';
    filters.severity = '';
    router.get(alertsPage.url(), {}, { preserveState: true });
}

function acknowledge(alert: AlertRow) {
    router.post(acknowledgeAlert.url(alert.id), {}, { preserveScroll: true });
}

onMounted(() => {
    timer = setInterval(() => {
        router.reload({
            only: ['alerts', 'counts'],
        });
    }, Math.max(props.refreshSeconds, 2) * 1000);
});

onUnmounted(() => {
    if (timer) {
        clearInterval(timer);
    }
});
</script>

<template>
    <div>
        <Head title="Alerts" />
        <div class="space-y-6 p-6">
            <Heading
                title="Alerts"
                description="Rule-based operational alerts for AMI staleness, long ringing and failed transfers. Alerts resolve automatically when the condition clears."
            />

            <section class="grid gap-4 sm:grid-cols-3">
                <div
                    v-for="metric in [
                        { label: 'Open', value: counts.open },
                        { label: 'Acknowledged', value: counts.acknowledged },
                        { label: 'Resolved (24h)', value: counts.resolved_24h },
                    ]"
                    :key="metric.label"
                    class="rounded-xl border bg-white p-4 shadow-sm dark:bg-slate-900"
                >
                    <p class="text-xs font-medium uppercase text-slate-500">
                        {{ metric.label }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold">{{ metric.value }}</p>
                </div>
            </section>

            <form class="flex flex-wrap items-end gap-4" @submit.prevent="search">
                <label class="grid gap-2 text-sm"
                    >Status<select
                        v-model="filters.status"
                        class="rounded border bg-background p-2"
                    >
                        <option value="">All</option>
                        <option
                            v-for="state in ['open', 'acknowledged', 'resolved']"
                            :key="state"
                            :value="state"
                        >
                            {{ state }}
                        </option>
                    </select></label
                >
                <label class="grid gap-2 text-sm"
                    >Severity<select
                        v-model="filters.severity"
                        class="rounded border bg-background p-2"
                    >
                        <option value="">All</option>
                        <option
                            v-for="severity in ['critical', 'warning', 'info']"
                            :key="severity"
                            :value="severity"
                        >
                            {{ severity }}
                        </option>
                    </select></label
                >
                <button class="rounded border px-4 py-2" type="submit">Filter</button>
                <button class="text-sm" type="button" @click="clearFilters">
                    Clear
                </button>
                <span class="text-xs text-slate-500">
                    {{ alerts.total }} alerts · {{ openAlerts }} open on this page ·
                    refreshes every {{ refreshSeconds }}s
                </span>
            </form>


            <div class="overflow-x-auto rounded-xl border bg-white dark:bg-slate-900">
                <table class="w-full text-left text-sm">
                    <thead
                        class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-800"
                    >
                        <tr>
                            <th class="px-4 py-3">Rule</th>
                            <th class="px-4 py-3">Severity</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Subject</th>
                            <th class="px-4 py-3">First seen</th>
                            <th class="px-4 py-3">Last seen</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="alert in alerts.data"
                            :key="alert.id"
                            class="border-t"
                        >
                            <td class="px-4 py-3">
                                <p class="font-medium">
                                    {{ alert.rule?.name ?? 'Alert' }}
                                </p>
                                <p class="text-xs text-slate-500">
                                    {{ alert.rule?.category ?? 'uncategorised' }}
                                </p>
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                    :class="severityClasses(alert.severity)"
                                    >{{ alert.severity }}</span
                                >
                            </td>
                            <td class="px-4 py-3 text-xs uppercase">
                                {{ alert.status }}
                            </td>
                            <td class="px-4 py-3">
                                <Link
                                    v-if="alert.interaction"
                                    :href="showJourney(alert.interaction.id)"
                                    class="text-emerald-700"
                                    >#{{ alert.interaction.id }}</Link
                                >
                                <span v-else>{{ alert.subject ?? '—' }}</span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                {{ formatClock(alert.first_seen_at) }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                {{ formatClock(alert.last_seen_at) }}
                            </td>
                            <td class="px-4 py-3">
                                <button
                                    v-if="canAcknowledge && alert.status === 'open'"
                                    type="button"
                                    class="rounded border px-2 py-1 text-xs"
                                    @click="acknowledge(alert)"
                                >
                                    Acknowledge
                                </button>
                            </td>
                        </tr>
                        <tr v-if="alerts.data.length === 0">
                            <td
                                colspan="7"
                                class="px-4 py-10 text-center text-slate-500"
                            >
                                No alerts match these filters.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav class="flex gap-4 text-sm" aria-label="Pagination">
                <Link v-if="alerts.prev_page_url" :href="alerts.prev_page_url">
                    Previous
                </Link>
                <Link v-if="alerts.next_page_url" :href="alerts.next_page_url">
                    Next
                </Link>
            </nav>


            <section class="space-y-3">
                <h2 class="font-semibold">Configured rules</h2>
                <p class="text-xs text-slate-500">
                    Thresholds are read-only here; they are seeded and evaluated
                    by <code>exp:evaluate-alerts</code> every minute.
                </p>
                <div class="overflow-x-auto rounded-xl border bg-white dark:bg-slate-900">
                    <table class="w-full text-left text-sm">
                        <thead
                            class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-800"
                        >
                            <tr>
                                <th class="px-4 py-3">Rule</th>
                                <th class="px-4 py-3">Category</th>
                                <th class="px-4 py-3">Metric</th>
                                <th class="px-4 py-3">Threshold</th>
                                <th class="px-4 py-3">Severity</th>
                                <th class="px-4 py-3">Enabled</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="rule in rules" :key="rule.id" class="border-t">
                                <td class="px-4 py-3">
                                    <p class="font-medium">{{ rule.name }}</p>
                                    <p class="font-mono text-xs text-slate-500">
                                        {{ rule.key }}
                                    </p>
                                </td>
                                <td class="px-4 py-3">{{ rule.category }}</td>
                                <td class="px-4 py-3">{{ rule.metric }}</td>
                                <td class="px-4 py-3">
                                    {{
                                        rule.threshold_seconds === null
                                            ? '—'
                                            : `${rule.threshold_seconds}s`
                                    }}
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                        :class="severityClasses(rule.severity)"
                                        >{{ rule.severity }}</span
                                    >
                                </td>
                                <td class="px-4 py-3">
                                    {{ rule.enabled ? 'Yes' : 'No' }}
                                </td>
                            </tr>
                            <tr v-if="rules.length === 0">
                                <td
                                    colspan="6"
                                    class="px-4 py-10 text-center text-slate-500"
                                >
                                    No alert rules configured. Run the database
                                    seeder.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</template>

