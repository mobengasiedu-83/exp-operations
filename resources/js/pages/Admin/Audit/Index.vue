<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import AppLayout from "@/layouts/AppLayout.vue";
import Heading from "@/components/Heading.vue";

type ActivityRow = {
    id: number;
    causer: { name: string; email: string } | null;
    subject_type: string;
    event: string;
    properties: string;
    created_at: string;
};

type Props = {
    activities: {
        data: ActivityRow[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
};

const props = defineProps<Props>();

defineOptions({ layout: AppLayout });
</script>

<template>
    <div>
        <Head title="Audit Log" />
        <div class="p-6">
            <Heading title="Audit Log" description="View system activity and audit trail." />
        </div>

        <div class="space-y-6">
            <div class="rounded-xl border bg-white p-4 dark:bg-slate-900">
                <table class="w-full text-sm text-left">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-800">
                        <tr>
                            <th class="px-5 py-3">User</th>
                            <th class="px-5 py-3 w-40">Event</th>
                            <th class="px-5 py-3 w-40">Subject</th>
                            <th class="px-5 py-3">Time</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr
                            v-for="activity in props.activities.data"
                            :key="activity.id"
                            class="border-b border-slate-100 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800/50"
                        >
                            <td class="px-5 py-4">
                                {{ activity.causer ? activity.causer.name : "System" }}
                            </td>
                            <td class="px-5 py-4 font-medium">{{ activity.event }}</td>
                            <td class="px-5 py-4 text-slate-500">{{ activity.subject_type }}</td>
                            <td class="px-5 py-4 text-slate-500">
                                {{ new Date(activity.created_at).toLocaleString() }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
