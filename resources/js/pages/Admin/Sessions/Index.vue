<script setup lang="ts">
import { Head, Link, usePage } from "@inertiajs/vue3";
import AppLayout from "@/layouts/AppLayout.vue";
import Heading from "@/components/Heading.vue";

import { destroy } from "@/routes/admin/sessions";
const page = usePage();
type SessionRow = {
    id: string;
    user_id: number;
    user_name: string;
    user_email: string;
    ip_address: string;
    user_agent: string;
    last_activity: string;
};

type Props = {
    sessions: {
        data: SessionRow[];
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
        <Head title="Sessions" />
        <div class="p-6">
            <Heading title="Sessions" description="View and manage active user sessions." />
        </div>

        <div class="space-y-6">
            <div class="rounded-xl border bg-white p-4 dark:bg-slate-900">
                <table class="w-full text-sm text-left">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-800">
                        <tr>
                            <th class="px-5 py-3">User</th>
                            <th class="px-5 py-3 w-32">IP Address</th>
                            <th class="px-5 py-3">Last Activity</th>
                            <th class="px-5 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr
                            v-for="session in props.sessions.data"
                            :key="session.id"
                            class="border-b border-slate-100 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800/50"
                        >
                            <td class="px-5 py-4">
                                {{ session.user_name }} ({{ session.user_email }})
                            </td>
                            <td class="px-5 py-4 text-slate-500">{{ session.ip_address }}</td>
                            <td class="px-5 py-4 text-slate-500">
                                {{
                                    new Date(Number(session.last_activity) * 1000).toLocaleString()
                                }}
                            </td>
                            <td class="px-5 py-4 text-right">
                                <Link
                                    v-if="
                                        page.props.auth.permissions.includes('terminate sessions')
                                    "
                                    :href="destroy(session.id)"
                                    as="button"
                                    >Terminate</Link
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
