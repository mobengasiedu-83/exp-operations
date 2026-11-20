<script setup lang="ts">
import { ref } from "vue";
import { Head, useForm, usePage } from "@inertiajs/vue3";
import AppLayout from "@/layouts/AppLayout.vue";
import Heading from "@/components/Heading.vue";
import AdminTable from "@/components/AdminTable.vue";
import endpoints from "@/routes/admin/pbx-connections";
type Row = Record<string, unknown> & { id: number };
const props = defineProps<{
    connections: { data: Row[]; prev_page_url: string | null; next_page_url: string | null };
}>();
const page = usePage();
const can = (permission: string) => page.props.auth.permissions.includes(permission);
const selected = ref<number | null>(null);
const visible = ref(false);
const form = useForm({ name: "", host: "", ami_port: 5038 });
function edit(row: Record<string, unknown>) {
    selected.value = Number(row.id);
    form.name = String(row.name ?? "");
    form.host = String(row.host ?? "");
    form.ami_port = Number(row.ami_port);
    form.clearErrors();
    visible.value = true;
}
function create() {
    selected.value = null;
    form.reset();
    form.clearErrors();
    visible.value = true;
}
function save() {
    if (selected.value !== null) {
        form.put(endpoints.update.url(selected.value), {
            onSuccess: () => {
                visible.value = false;
                form.reset();
            },
        });
    } else {
        form.post(endpoints.store.url(), {
            onSuccess: () => {
                visible.value = false;
                form.reset();
            },
        });
    }
}
defineOptions({ layout: AppLayout });
</script>
<template>
    <div>
        <Head title="PBX Connections" />
        <div class="space-y-6 p-6">
            <Heading title="PBX Connections" />
            <button v-if="can('edit settings')" class="rounded border px-4 py-2" @click="create">
                Add PBX Connections
            </button>
            <form
                v-if="visible"
                class="grid max-w-xl gap-4 rounded-xl border p-5"
                @submit.prevent="save"
            >
                <h2 class="font-semibold">{{ selected ? "Edit" : "Add" }} PBX Connections</h2>
                <label class="grid gap-2"
                    >Name<input
                        v-model="form.name"
                        type="text"
                        class="rounded border bg-background p-2" /></label
                ><label class="grid gap-2"
                    >Private hostname / IP<input
                        v-model="form.host"
                        type="text"
                        class="rounded border bg-background p-2" /></label
                ><label class="grid gap-2"
                    >AMI port<input
                        v-model.number="form.ami_port"
                        type="number"
                        class="rounded border bg-background p-2"
                /></label>
                <p
                    v-for="(error, key) in form.errors"
                    :key="key"
                    class="text-sm text-red-600"
                    role="alert"
                >
                    {{ error }}
                </p>
                <p v-if="form.recentlySuccessful" role="status">Saved.</p>
                <div class="flex gap-3">
                    <button
                        :disabled="form.processing"
                        type="submit"
                        class="rounded bg-primary px-4 py-2 text-primary-foreground"
                    >
                        Save</button
                    ><button type="button" @click="visible = false">Cancel</button>
                </div>
            </form>
            <AdminTable
                :rows="props.connections.data"
                :columns="[
                    { key: 'name', label: 'Name' },
                    { key: 'host', label: 'Host' },
                    { key: 'ami_port', label: 'Ami Port' },
                    { key: 'status', label: 'Status' },
                    { key: 'last_heartbeat_at', label: 'Last Heartbeat At' },
                    { key: 'last_event_at', label: 'Last Event At' },
                ]"
                :previous="props.connections.prev_page_url"
                :next="props.connections.next_page_url"
            >
                <template #actions="{ row }"
                    ><button v-if="can('edit settings')" @click="edit(row)">Edit</button></template
                >
            </AdminTable>
        </div>
    </div>
</template>
