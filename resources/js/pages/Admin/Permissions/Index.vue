<script setup lang="ts">
import { Head } from "@inertiajs/vue3";
import AppLayout from "@/layouts/AppLayout.vue";
import Heading from "@/components/Heading.vue";
import AdminTable from "@/components/AdminTable.vue";
defineProps<{
    permissions: {
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
        <Head title="Permissions" />
        <div class="space-y-6 p-6">
            <Heading title="Permissions" />

            <AdminTable
                :rows="permissions.data"
                :columns="[{ key: 'name', label: 'Name' }]"
                :previous="permissions.prev_page_url"
                :next="permissions.next_page_url"
            />
        </div>
    </div>
</template>
