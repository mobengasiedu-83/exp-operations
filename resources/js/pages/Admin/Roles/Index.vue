<script setup lang="ts">
import { ref } from "vue";
import { Head, useForm, usePage } from "@inertiajs/vue3";
import AppLayout from "@/layouts/AppLayout.vue";
import Heading from "@/components/Heading.vue";
import AdminTable from "@/components/AdminTable.vue";
import endpoints from "@/routes/admin/roles";
type Row = Record<string, unknown> & { id: number };
const props = defineProps<{
    roles: { data: Row[]; prev_page_url: string | null; next_page_url: string | null };
    permissions: { id: number; name: string }[];
}>();
const page = usePage();
const can = (permission: string) => page.props.auth.permissions.includes(permission);
const selected = ref<number | null>(null);
const visible = ref(false);
const form = useForm({ name: "", permissions: [] as string[] });
function edit(row: Record<string, unknown>) {
    selected.value = Number(row.id);
    form.name = String(row.name ?? "");
    form.permissions = Array.isArray(row.permissions)
        ? (row.permissions as { name: string }[]).map((item) => item.name)
        : [];
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
        <Head title="Roles" />
        <div class="space-y-6 p-6">
            <Heading title="Roles" />
            <button v-if="can('create roles')" class="rounded border px-4 py-2" @click="create">
                Add Roles
            </button>
            <form
                v-if="visible"
                class="grid max-w-xl gap-4 rounded-xl border p-5"
                @submit.prevent="save"
            >
                <h2 class="font-semibold">{{ selected ? "Edit" : "Add" }} Roles</h2>
                <label class="grid gap-2"
                    >Name<input
                        v-model="form.name"
                        type="text"
                        class="rounded border bg-background p-2" /></label
                ><label class="grid gap-2"
                    >Permissions<select
                        v-model="form.permissions"
                        multiple
                        class="rounded border bg-background p-2"
                    >
                        <option
                            v-for="option in props.permissions"
                            :key="option.id"
                            :value="option.name"
                        >
                            {{ option.name }}
                        </option>
                    </select></label
                >
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
                :rows="props.roles.data"
                :columns="[
                    { key: 'name', label: 'Name' },
                    { key: 'permissions', label: 'Permissions' },
                ]"
                :previous="props.roles.prev_page_url"
                :next="props.roles.next_page_url"
            >
                <template #actions="{ row }"
                    ><button v-if="can('edit roles')" @click="edit(row)">Edit</button></template
                >
            </AdminTable>
        </div>
    </div>
</template>
