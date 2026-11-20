<script setup lang="ts">
import { reactive } from "vue";
import { Head, Link, router } from "@inertiajs/vue3";
import Heading from "@/components/Heading.vue";
import AdminTable from "@/components/AdminTable.vue";
import { index } from "@/routes/correlation-exceptions";
import { show } from "@/routes/calls";
const props = defineProps<{
    exceptions: {
        data: Record<string, unknown>[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: Record<string, string>;
}>();
const filters = reactive({
    category: props.filters.category ?? "",
    code: props.filters.code ?? "",
    connection_id: props.filters.connection_id ?? "",
});
function search() {
    router.get(index.url(), { ...filters }, { preserveState: true });
}
</script>
<template>
    <div class="space-y-6 p-6">
        <Head title="Correlation diagnostics" />
        <Heading
            title="Unmatched events and reconciliation"
            description="Missing evidence and timing discrepancies require review; no durations are invented to fill gaps."
        />
        <form class="flex flex-wrap items-end gap-4" @submit.prevent="search">
            <label class="grid gap-2"
                >Category<select
                    v-model="filters.category"
                    class="rounded border bg-background p-2"
                >
                    <option value="">All</option>
                    <option
                        v-for="category in ['ingestion', 'projection', 'reconciliation']"
                        :key="category"
                        :value="category"
                    >
                        {{ category }}
                    </option>
                </select></label
            >
            <label class="grid gap-2"
                >Code<input v-model="filters.code" class="rounded border bg-background p-2"
            /></label>
            <label class="grid gap-2"
                >Connection ID<input
                    v-model="filters.connection_id"
                    type="number"
                    min="1"
                    class="w-28 rounded border bg-background p-2"
            /></label>
            <button class="rounded border px-4 py-2" type="submit">Filter</button
            ><Link :href="index()">Clear</Link>
        </form>
        <AdminTable
            :rows="exceptions.data"
            :columns="[
                { key: 'pbx_connection_id', label: 'PBX' },
                { key: 'category', label: 'Category' },
                { key: 'code', label: 'Code' },
                { key: 'subject', label: 'Subject' },
                { key: 'details', label: 'Evidence' },
                { key: 'event', label: 'Unmatched event' },
            ]"
            :previous="exceptions.prev_page_url"
            :next="exceptions.next_page_url"
        >
            <template #actions="{ row }"
                ><Link v-if="row.interaction_id" :href="show(Number(row.interaction_id))"
                    >Inspect journey</Link
                ><span v-else>Unmatched event {{ row.call_event_id }}</span></template
            >
        </AdminTable>
    </div>
</template>
