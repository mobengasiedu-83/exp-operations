<script setup lang="ts">
import { Head, Link, router } from "@inertiajs/vue3";
import AppLayout from "@/layouts/AppLayout.vue";
import Heading from "@/components/Heading.vue";

import { reactive } from "vue";
import { index as cdrCelIndex } from "@/routes/admin/cdr-cel";
type RecordRow = {
    id: number;
    uniqueid: string | null;
    linkedid: string | null;
    channel: string | null;
    created_at: string;
};

type Props = {
    type: string;
    records: {
        data: RecordRow[];
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
    router.get(cdrCelIndex.url(), { ...filters, type: props.type }, { preserveState: true });
}

defineOptions({ layout: AppLayout });
</script>

<template>
    <div>
        <Head title="CDR/CEL Records" />
        <div class="p-6">
            <Heading
                :title="props.type === 'cel' ? 'CEL Records' : 'CDR Records'"
                :description="`View ${props.type === 'cel' ? 'Channel Event Log' : 'Call Detail Record'} entries from the PBX.`"
            />
        </div>

        <div class="space-y-6">
            <div class="flex items-center gap-4">
                <Link
                    :href="cdrCelIndex({ query: { type: 'cdr' } })"
                    class="px-4 py-2 rounded-lg text-sm font-medium text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800"
                >
                    CDR
                </Link>
                <Link
                    :href="cdrCelIndex({ query: { type: 'cel' } })"
                    class="px-4 py-2 rounded-lg text-sm font-medium text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800"
                >
                    CEL
                </Link>
            </div>

            <form @submit.prevent="refresh" class="grid gap-4 sm:grid-cols-3 lg:grid-cols-4">
                <label class="grid gap-1.5 text-xs font-medium text-slate-600 dark:text-slate-300">
                    Unique ID
                    <input
                        v-model="filters.uniqueid"
                        type="text"
                        class="h-10 rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-950"
                        placeholder="Filter by unique ID"
                    />
                </label>
                <label class="grid gap-1.5 text-xs font-medium text-slate-600 dark:text-slate-300">
                    Linked ID
                    <input
                        v-model="filters.linkedid"
                        type="text"
                        class="h-10 rounded-lg border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-950"
                        placeholder="Filter by linked ID"
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
                <div class="flex items-end gap-2">
                    <button
                        type="submit"
                        class="h-10 rounded-lg bg-emerald-700 px-5 text-sm font-semibold text-white hover:bg-emerald-800"
                    >
                        Apply filters
                    </button>
                </div>
            </form>

            <div class="rounded-xl border bg-white p-4 dark:bg-slate-900">
                <table class="w-full text-sm text-left">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-800">
                        <tr>
                            <th class="px-5 py-3">Unique ID</th>
                            <th class="px-5 py-3 w-48">Linked ID</th>
                            <th class="px-5 py-3 w-48">Channel</th>
                            <th class="px-5 py-3">Created</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr
                            v-for="record in props.records.data"
                            :key="record.id"
                            class="border-b border-slate-100 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800/50"
                        >
                            <td class="px-5 py-4 font-medium">{{ record.uniqueid || "—" }}</td>
                            <td class="px-5 py-4 text-slate-500">{{ record.linkedid || "—" }}</td>
                            <td class="px-5 py-4 text-slate-500">{{ record.channel || "—" }}</td>
                            <td class="px-5 py-4 text-slate-500">
                                {{ new Date(record.created_at).toLocaleString() }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="props.records.total > 0" class="flex items-center justify-between">
                <p class="text-xs text-slate-500">
                    Showing {{ props.records.data.length }} of {{ props.records.total }} records
                </p>
            </div>
        </div>
    </div>
</template>
