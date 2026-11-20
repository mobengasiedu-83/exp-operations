<script setup lang="ts">
import { Head } from "@inertiajs/vue3";
import AppLayout from "@/layouts/AppLayout.vue";
import Heading from "@/components/Heading.vue";
import AdminTable from "@/components/AdminTable.vue";
defineProps<{
    logs: {
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
        <Head title="Structured Logs" />
        <div class="space-y-6 p-6">
            <Heading title="Structured Logs" />

            <AdminTable
                :rows="logs.data"
                :columns="[
                    { key: 'logged_at', label: 'Logged At' },
                    { key: 'connection_id', label: 'Connection Id' },
                    { key: 'level', label: 'Level' },
                    { key: 'event_name', label: 'Event Name' },
                    { key: 'message', label: 'Message' },
                    { key: 'backoff_seconds', label: 'Backoff Seconds' },
                    { key: 'lag_seconds', label: 'Lag Seconds' },
                ]"
                :previous="logs.prev_page_url"
                :next="logs.next_page_url"
            />
        </div>
    </div>
</template>
