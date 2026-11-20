<script setup lang="ts">
import { Head, router } from "@inertiajs/vue3";
import AppLayout from "@/layouts/AppLayout.vue";
import Heading from "@/components/Heading.vue";

type Props = {
    imports: {
        component: string;
        status: string;
        last_error: string | null;
        last_check_at: string | null;
    }[];
    connection: {
        id: number;
        name: string;
        host: string;
        ami_port: number;
        status: string;
        last_connected_at: string | null;
        last_event_at: string | null;
        last_heartbeat_at: string | null;
        last_error: string | null;
    } | null;
    health: {
        id: number;
        component: string;
        status: string;
        last_check_at: string | null;
        last_error: string | null;
        metadata: Record<string, unknown> | null;
    } | null;
};

import { check } from "@/routes/admin/ami-health";
const props = defineProps<Props>();
function runHealthCheck() {
    router.post(check.url());
}

defineOptions({ layout: AppLayout });
</script>

<template>
    <div>
        <Head title="AMI Health" />
        <div class="p-6">
            <Heading
                title="AMI Health"
                description="Monitor VitalPBX AMI listener health and connectivity."
            />
        </div>

        <div class="space-y-6 p-6">
            <section v-for="item in imports" :key="item.component" class="rounded border p-4">
                <h2>{{ item.component }}: {{ item.status }}</h2>
                <p>{{ item.last_error }}</p>
                <p>Last import: {{ item.last_check_at }}</p>
            </section>
            <div class="grid gap-4 md:grid-cols-2">
                <div class="rounded-xl border bg-white p-6 dark:bg-slate-900">
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-white">
                        Connection Status
                    </h3>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ props.connection ? props.connection.status : "Unknown" }}
                    </p>
                    <p class="mt-2 text-xs text-slate-400">
                        Host: {{ props.connection?.host }}:{{ props.connection?.ami_port }}
                    </p>
                    <p class="mt-1 text-xs text-slate-400">
                        Last heartbeat:
                        {{
                            props.connection?.last_heartbeat_at
                                ? new Date(props.connection.last_heartbeat_at).toLocaleString()
                                : "—"
                        }}
                    </p>
                    <p class="mt-1 text-xs text-slate-400">
                        Last event:
                        {{
                            props.connection?.last_event_at
                                ? new Date(props.connection.last_event_at).toLocaleString()
                                : "—"
                        }}
                    </p>
                    <p class="mt-2 text-xs text-slate-400">
                        Last error: {{ props.connection?.last_error || "None" }}
                    </p>
                </div>

                <div class="rounded-xl border bg-white p-6 dark:bg-slate-900">
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-white">
                        Integration Health
                    </h3>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ props.health ? props.health.status : "No checks recorded" }}
                    </p>
                    <p class="mt-1 text-xs text-slate-400">
                        Last check:
                        {{
                            props.health?.last_check_at
                                ? new Date(props.health.last_check_at).toLocaleString()
                                : "—"
                        }}
                    </p>
                    <p class="mt-1 text-xs text-slate-400">
                        Last error: {{ props.health?.last_error || "None" }}
                    </p>
                </div>
            </div>

            <div class="rounded-xl border bg-white p-6 dark:bg-slate-900">
                <button
                    @click="runHealthCheck"
                    class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800"
                >
                    Run Health Check
                </button>
            </div>
        </div>
    </div>
</template>
