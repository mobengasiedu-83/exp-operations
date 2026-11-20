<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import { useLiveSnapshot } from '@/composables/useLiveSnapshot';
import {
    formatClock,
    formatDuration,
    formatSeconds,
    freshnessClasses,
    stageClasses,
    stageLabel,
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

const activeCalls = computed(() => snapshot.value?.active_calls ?? []);
</script>

<template>
    <div>
        <Head title="Active calls" />
        <div class="space-y-6 p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading
                    title="Active calls"
                    description="Journeys without a hangup, grouped by the furthest stage reached. Stale journeys may be missing hangup events."
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
                    <span class="text-xs text-slate-500">
                        Heartbeat
                        {{ formatSeconds(snapshot?.freshness.heartbeat_age_seconds) }}
                        ago
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

            <div
                v-if="snapshot && snapshot.freshness.state !== 'live'"
                class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
            >
                PBX data is {{ snapshot.freshness.state }}. Active calls may be
                incomplete until heartbeats resume.
            </div>

            <div class="overflow-x-auto rounded-xl border bg-white dark:bg-slate-900">
                <table class="w-full text-left text-sm">
                    <thead
                        class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-800"
                    >
                        <tr>
                            <th class="px-4 py-3">Journey</th>
                            <th class="px-4 py-3">Stage</th>
                            <th class="px-4 py-3">Agent</th>
                            <th class="px-4 py-3">Destination</th>
                            <th class="px-4 py-3">Started</th>
                            <th class="px-4 py-3">Duration</th>
                            <th class="px-4 py-3">Time basis</th>
                            <th class="px-4 py-3">Flags</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="call in activeCalls"
                            :key="call.id"
                            class="border-t"
                        >
                            <td class="px-4 py-3 font-medium">#{{ call.id }}</td>
                            <td class="px-4 py-3">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                    :class="stageClasses(call.stage)"
                                    >{{ stageLabel(call.stage) }}</span
                                >
                            </td>
                            <td class="px-4 py-3 font-mono text-xs">
                                {{ call.agent_channel ?? '—' }}
                            </td>
                            <td class="px-4 py-3 font-mono text-xs">
                                {{ call.destination_channel ?? '—' }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                {{ formatClock(call.started_at) }}
                            </td>
                            <td class="px-4 py-3">
                                {{ formatDuration(call.duration_seconds) }}
                            </td>
                            <td class="px-4 py-3">
                                {{ call.timing_quality ?? '—' }}
                            </td>
                            <td class="px-4 py-3">{{ call.exceptions }}</td>
                            <td class="px-4 py-3">
                                <Link
                                    :href="showJourney(call.id)"
                                    class="text-emerald-700"
                                    >Inspect</Link
                                >
                            </td>
                        </tr>
                        <tr v-if="activeCalls.length === 0">
                            <td
                                colspan="9"
                                class="px-4 py-10 text-center text-slate-500"
                            >
                                No active calls in this window.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="text-xs text-slate-500">
                Window {{ formatClock(snapshot?.window.from_iso) }} —
                {{ formatClock(snapshot?.window.to_iso) }} · polling every
                {{ refreshSeconds }}s
            </p>
        </div>
    </div>
</template>

