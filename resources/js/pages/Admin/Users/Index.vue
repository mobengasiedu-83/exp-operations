<script setup lang="ts">
import { ref } from "vue";
import { Head, useForm, usePage } from "@inertiajs/vue3";
import AppLayout from "@/layouts/AppLayout.vue";
import Heading from "@/components/Heading.vue";
import AdminTable from "@/components/AdminTable.vue";
import endpoints from "@/routes/admin/users";
type Row = Record<string, unknown> & { id: number };
const props = defineProps<{
    users: { data: Row[]; prev_page_url: string | null; next_page_url: string | null };
    roles: { id: number; name: string }[];
}>();
const page = usePage();
const can = (permission: string) => page.props.auth.permissions.includes(permission);
const selected = ref<number | null>(null);
const visible = ref(false);
const form = useForm({
    name: "",
    email: "",
    password: "",
    password_confirmation: "",
    active: true,
    roles: [] as string[],
});
function edit(row: Record<string, unknown>) {
    selected.value = Number(row.id);
    form.name = String(row.name ?? "");
    form.email = String(row.email ?? "");
    form.password = "";
    form.password_confirmation = "";
    form.active = Boolean(row.active);
    form.roles = Array.isArray(row.roles)
        ? (row.roles as { name: string }[]).map((item) => item.name)
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
        <Head title="Users" />
        <div class="space-y-6 p-6">
            <Heading title="Users" />
            <button v-if="can('create users')" class="rounded border px-4 py-2" @click="create">
                Add Users
            </button>
            <form
                v-if="visible"
                class="grid max-w-xl gap-4 rounded-xl border p-5"
                @submit.prevent="save"
            >
                <h2 class="font-semibold">{{ selected ? "Edit" : "Add" }} Users</h2>
                <label class="grid gap-2"
                    >Name<input
                        v-model="form.name"
                        type="text"
                        class="rounded border bg-background p-2" /></label
                ><label class="grid gap-2"
                    >Email<input
                        v-model="form.email"
                        type="email"
                        class="rounded border bg-background p-2" /></label
                ><label class="grid gap-2"
                    >Password<input
                        v-model="form.password"
                        type="password"
                        class="rounded border bg-background p-2" /></label
                ><label class="grid gap-2"
                    >Confirm password<input
                        v-model="form.password_confirmation"
                        type="password"
                        class="rounded border bg-background p-2" /></label
                ><label class="flex gap-2"
                    ><input v-model="form.active" type="checkbox" />Active</label
                ><label class="grid gap-2"
                    >Roles<select
                        v-model="form.roles"
                        multiple
                        class="rounded border bg-background p-2"
                    >
                        <option v-for="option in props.roles" :key="option.id" :value="option.name">
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
                :rows="props.users.data"
                :columns="[
                    { key: 'name', label: 'Name' },
                    { key: 'email', label: 'Email' },
                    { key: 'roles', label: 'Roles' },
                    { key: 'active', label: 'Active' },
                ]"
                :previous="props.users.prev_page_url"
                :next="props.users.next_page_url"
            >
                <template #actions="{ row }"
                    ><button v-if="can('edit users')" @click="edit(row)">Edit</button></template
                >
            </AdminTable>
        </div>
    </div>
</template>
