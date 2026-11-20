<script setup lang="ts">
import { Head, Link, router } from "@inertiajs/vue3";
import AppLayout from "@/layouts/AppLayout.vue";
import Heading from "@/components/Heading.vue";

import { reactive } from "vue";
import { index as integrationLogsIndex } from "@/routes/admin/integration-logs";
type EventRow = {
    id: number;
    event_name: string;
    uniqueid: string | null;
    linkedid: string | null;
    channel: string | null;
    received_at: string;
};

type Props = {
    events: {
        data: EventRow[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    filters: Record<string, string | undefined>;
};

const props = defineProps<Props>();
const filters = reactive({ ...props.filters });
function refresh() {
    router.get(integrationLogsIndex.url(), { ...filters }, { preserveState: true });
}

defineOptions({ layout: AppLayout });
</script>

<template>
    <div>
        <Head title="Integration Logs" />
        <div class="p-6">
            <Heading
                title="Integration Logs"
                description="View raw AMI events from the PBX integration."
            />
        </div>

        <div class="space-y-6">
            <form @submit.prevent="refresh" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <label class="grid gap-1.5 text-xs font-medium text-slate-600 dark:text-slate-300">
                    Event name
                    <input
                        v-model="filters.event"
                        type="text"
                        class="h-10 rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-950"
                        placeholder="Filter by event"
                    />
                </label>
                <label class="grid gap-1.5 text-xs font-medium text-slate-600 dark:text-slate-300">
                    From
                    <input
                        v-model="filters.from"
                        type="datetime-local"
                        class="h-10 rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-950"
                    />
                </label>
                <label class="grid gap-1.5 text-xs font-medium text-slate-600 dark:text-slate-300">
                    To
                    <input
                        v-model="filters.to"
                        type="datetime-local"
                        class="h-10 rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-950"
                    />
                </label>
                <div class="flex items-end gap-2">
                    <button
                        type="submit"
                        class="h-10 rounded-lg bg-emerald-700 px-5 text-sm font-semibold text-white hover:bg-emerald-800"
                    >
                        Apply filters
                    </button>
                    <Link
                        :href="integrationLogsIndex()"
                        class="h-10 rounded-lg border px-4 text-sm font-medium hover:bg-slate-50 dark:hover:bg-slate-800"
                    >
                        Clear
                    </Link>
                </div>
            </form>

            <div class="rounded-xl border bg-white p-4 dark:bg-slate-900">
                <table class="w-full text-sm text-left">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-800">
                        <tr>
                            <th class="px-5 py-3">Time</th>
                            <th class="px-5 py-3">Event</th>
                            <th class="px-5 py-3 w-48">Unique ID</th>
                            <th class="px-5 py-3 w-48">Linked ID</th>
                            <th class="px-5 py-3 w-48">Channel</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr
                            v-for="event in props.events.data"
                            :key="event.id"
                            class="border-b border-slate-100 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800/50"
                        >
                            <td class="px-5 py-4 text-slate-500">
                                {{ new Date(event.received_at).toLocaleString() }}
                            </td>
                            <td class="px-5 py-4 font-medium">{{ event.event_name }}</td>
                            <td class="px-5 py-4 text-slate-500">{{ event.uniqueid || "—" }}</td>
                            <td class="px-5 py-4 text-slate-500">{{ event.linkedid || "—" }}</td>
                            <td class="px-5 py-4 text-slate-500">{{ event.channel || "—" }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="props.events.total > 0" class="flex items-center justify-between">
                <p class="text-xs text-slate-500">
                    Showing {{ props.events.data.length }} of {{ props.events.total }} events
                </p>
            </div>
        </div>
    </div>
</template>
