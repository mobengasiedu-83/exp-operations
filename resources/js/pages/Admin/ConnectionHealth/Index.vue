<script setup lang="ts">
import { Head } from "@inertiajs/vue3";
import AppLayout from "@/layouts/AppLayout.vue";
import Heading from "@/components/Heading.vue";
import AdminTable from "@/components/AdminTable.vue";
defineProps<{
    health: {
        data: Record<string, unknown>[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    staleSeconds?: number;
}>();
defineOptions({ layout: AppLayout });
</script>
<template>
    <div>
        <Head title="Connection Health" />
        <div class="space-y-6 p-6">
            <Heading title="Connection Health" />
            <p>
                Heartbeat freshness limit: {{ staleSeconds }} seconds. An old event timestamp can
                also mean an idle PBX.
            </p>
            <AdminTable
                :rows="health.data"
                :columns="[
                    { key: 'connection_id', label: 'Connection Id' },
                    { key: 'status', label: 'Status' },
                    { key: 'reconnect_attempts', label: 'Reconnect Attempts' },
                    { key: 'backoff_seconds', label: 'Backoff Seconds' },
                    { key: 'last_heartbeat_at', label: 'Last Heartbeat At' },
                    { key: 'last_event_at', label: 'Last Event At' },
                    { key: 'lag_seconds', label: 'Lag Seconds' },
                    { key: 'last_error', label: 'Last Error' },
                ]"
                :previous="health.prev_page_url"
                :next="health.next_page_url"
            />
        </div>
    </div>
</template>
