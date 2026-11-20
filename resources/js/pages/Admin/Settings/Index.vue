<script setup lang="ts">
import { ref } from "vue";
import { Head, useForm, usePage } from "@inertiajs/vue3";
import AppLayout from "@/layouts/AppLayout.vue";
import Heading from "@/components/Heading.vue";
import AdminTable from "@/components/AdminTable.vue";
import endpoints from "@/routes/admin/settings";
type Row = Record<string, unknown> & { id: number };
const props = defineProps<{
    settings: { data: Row[]; prev_page_url: string | null; next_page_url: string | null };
}>();
const page = usePage();
const can = (permission: string) => page.props.auth.permissions.includes(permission);
const selected = ref<number | null>(null);
const visible = ref(false);
const form = useForm({ value: "" });
function edit(row: Record<string, unknown>) {
    selected.value = Number(row.id);
    form.value = String(row.value ?? "");
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
    }
}
defineOptions({ layout: AppLayout });
</script>
<template>
    <div>
        <Head title="Settings" />
        <div class="space-y-6 p-6">
            <Heading title="Settings" />

            <form
                v-if="visible"
                class="grid max-w-xl gap-4 rounded-xl border p-5"
                @submit.prevent="save"
            >
                <h2 class="font-semibold">{{ selected ? "Edit" : "Add" }} Settings</h2>
                <label class="grid gap-2"
                    >Value<input
                        v-model="form.value"
                        type="text"
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
                :rows="props.settings.data"
                :columns="[
                    { key: 'key', label: 'Key' },
                    { key: 'group', label: 'Group' },
                    { key: 'value', label: 'Value' },
                    { key: 'cast', label: 'Cast' },
                    { key: 'is_feature_flag', label: 'Is Feature Flag' },
                ]"
                :previous="props.settings.prev_page_url"
                :next="props.settings.next_page_url"
            >
                <template #actions="{ row }"
                    ><button v-if="can('edit settings')" @click="edit(row)">Edit</button></template
                >
            </AdminTable>
        </div>
    </div>
</template>
