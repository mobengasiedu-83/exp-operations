<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import { useLiveSnapshot } from '@/composables/useLiveSnapshot';
import {
    formatClock,
    formatSeconds,
    freshnessClasses,
} from '@/lib/live-operations';
import { show as showJourney } from '@/routes/calls';
import type { LiveFilters, LiveSnapshot } from '@/types/live-operations';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    snapshot: LiveSnapshot;
    filters: LiveFilters;
    refreshSeconds: number;
}>();

const filters = ref<LiveFilters>({ ...props.filters });
const refreshSeconds = ref(props.refreshSeconds);
const paused = ref(false);

const { snapshot, error, loading, refresh } = useLiveSnapshot(
    filters,
    refreshSeconds,
    paused,
);

const transfers = computed(() => snapshot.value?.transfers ?? []);
const activeCount = computed(
    () => transfers.value.filter((transfer) => transfer.active).length,
);
const answeredCount = computed(
    () => transfers.value.filter((transfer) => transfer.answered_at !== null).length,
);
const ringTimes = computed(() =>
    transfers.value
        .map((transfer) => transfer.ringing_for_seconds)
        .filter((value): value is number => value !== null),
);
const averageRing = computed(() =>
    ringTimes.value.length === 0
        ? null
        : ringTimes.value.reduce((total, value) => total + value, 0) /
          ringTimes.value.length,
);
const longestRing = computed(() =>
    ringTimes.value.length === 0 ? null : Math.max(...ringTimes.value),
);
</script>

<template>
    <div>
        <Head title="Transfer status" />
        <div class="space-y-6 p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading
                    title="Transfer status"
                    description="Mobile (dentist) legs with dial, ringing and answer timings. Network answer does not confirm a human dentist accepted the call."
                />
                <div class="flex items-center gap-3 text-sm">
                    <span
                        class="rounded-full px-3 py-1 text-xs font-semibold uppercase"
                        :class="
                            freshnessClasses(
                                snapshot?.freshness.state ?? 'offline',
                            )
                        "
                    >
                        {{ snapshot?.freshness.state ?? 'loading' }}
                    </span>
                    <button
                        type="button"
                        class="rounded border px-3 py-1.5"
                        :disabled="loading"
                        @click="refresh"
                    >
                        {{ loading ? 'Refreshing…' : 'Refresh' }}
                    </button>
                </div>
            </div>

            <div
                v-if="error"
                class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"
            >
                {{ error }}
            </div>

            <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div
                    v-for="metric in [
                        { label: 'Mobile legs', value: transfers.length },
                        { label: 'Still ringing', value: activeCount },
                        { label: 'Answered', value: answeredCount },
                        {
                            label: 'Average ring',
                            value: formatSeconds(averageRing),
                        },
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

            <div class="overflow-x-auto rounded-xl border bg-white dark:bg-slate-900">
                <table class="w-full text-left text-sm">
                    <thead
                        class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-800"
                    >
                        <tr>
                            <th class="px-4 py-3">Journey</th>
                            <th class="px-4 py-3">Channel</th>
                            <th class="px-4 py-3">Dial status</th>
                            <th class="px-4 py-3">Dialled</th>
                            <th class="px-4 py-3">Ringing</th>
                            <th class="px-4 py-3">Answered</th>
                            <th class="px-4 py-3">Ended</th>
                            <th class="px-4 py-3">Ring time</th>
                            <th class="px-4 py-3">Dial → answer</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="transfer in transfers"
                            :key="transfer.id"
                            class="border-t"
                        >
                            <td class="px-4 py-3">
                                <Link
                                    :href="showJourney(transfer.interaction_id)"
                                    class="font-medium text-emerald-700"
                                    >#{{ transfer.interaction_id }}</Link
                                >
                            </td>
                            <td class="px-4 py-3 font-mono text-xs">
                                {{ transfer.channel ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                {{ transfer.dial_status ?? '—' }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                {{ formatClock(transfer.dial_started_at) }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                {{ formatClock(transfer.ringing_at) }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                {{ formatClock(transfer.answered_at) }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                {{ formatClock(transfer.ended_at) }}
                            </td>
                            <td class="px-4 py-3">
                                {{ formatSeconds(transfer.ringing_for_seconds) }}
                            </td>
                            <td class="px-4 py-3">
                                {{ formatSeconds(transfer.dial_to_answer_seconds) }}
                            </td>
                        </tr>
                        <tr v-if="transfers.length === 0">
                            <td
                                colspan="9"
                                class="px-4 py-10 text-center text-slate-500"
                            >
                                No transfers recorded in this window.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="text-xs text-slate-500">
                Longest ring {{ formatSeconds(longestRing) }} · window
                {{ formatClock(snapshot?.window.from_iso) }} —
                {{ formatClock(snapshot?.window.to_iso) }} · polling every
                {{ refreshSeconds }}s
            </p>
        </div>
    </div>
</template>

