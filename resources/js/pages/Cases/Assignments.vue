<script setup lang="ts">
import { reactive, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import AdminTable from '@/components/AdminTable.vue';
import { assignments, handover } from '@/routes/cases';
import { store } from '@/routes/cases/assignments';

defineOptions({ layout: AppLayout });

type CaseRow = Record<string, unknown> & {
    id: number; priority: string | null; status: string; owner_id: number | null; owner: string | null;
    next_action: string | null; due_at: string | null; overdue: boolean;
};
type Paginator<T> = { data: T[]; prev_page_url: string | null; next_page_url: string | null };

const props = defineProps<{
    cases: Paginator<CaseRow>;
    filters: { owner?: string; mine: boolean };
    owners: { id: number; name: string }[];
    canEdit: boolean;
}>();

const filters = reactive({ owner: props.filters.owner ?? '', mine: props.filters.mine });
const selected = ref<CaseRow | null>(null);
const form = useForm({ assigned_to: '', next_action: '', due_at: '' });

function search() { router.get(assignments.url(), { owner: filters.owner, mine: filters.mine ? 1 : 0 }); }
function manage(row: CaseRow) {
    selected.value = row;
    form.reset();
    form.assigned_to = row.owner_id === null ? '' : String(row.owner_id);
    form.next_action = row.next_action ?? '';
    form.due_at = row.due_at === null ? '' : row.due_at.slice(0, 16);
}
function save() {
    if (selected.value === null) return;
    form.post(store(selected.value.id).url, { preserveScroll: true, onSuccess: () => (selected.value = null) });
}
</script>

<template>
    <div class="space-y-6 p-6">
        <Head title="Case assignments" />
        <Heading
            title="Case ownership and next actions"
            description="Every unresolved case keeps an owner, a next action and a due time. Reassigning closes the previous assignment and preserves the audit history."
        />
        <nav class="flex flex-wrap gap-4 text-sm">
            <Link :href="assignments()">Assignments</Link>
            <Link :href="handover()">Shift handover</Link>
        </nav>
        <form class="flex flex-wrap items-end gap-4 text-sm" @submit.prevent="search">
            <label class="grid gap-2">
                Owner
                <select v-model="filters.owner" class="rounded border bg-background p-2">
                    <option value="">Any owner</option>
                    <option v-for="owner in owners" :key="owner.id" :value="String(owner.id)">{{ owner.name }}</option>
                </select>
            </label>
            <label class="flex items-center gap-2 py-2">
                <input v-model="filters.mine" type="checkbox" />My cases
            </label>
            <button class="rounded border px-3 py-2">Apply</button>
        </form>

        <section v-if="canEdit && selected" class="space-y-4 rounded-xl border bg-card p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="font-semibold">Assign case #{{ selected.id }}</h2>
                <button type="button" class="rounded border px-3 py-1 text-sm" @click="selected = null">Close</button>
            </div>
            <form class="grid gap-4 md:grid-cols-3" @submit.prevent="save">
                <label class="grid gap-2">
                    Owner
                    <select v-model="form.assigned_to" required class="rounded border bg-background p-2">
                        <option value="" disabled>Select the owner</option>
                        <option v-for="owner in owners" :key="owner.id" :value="String(owner.id)">{{ owner.name }}</option>
                    </select>
                </label>
                <label class="grid gap-2">
                    Next action due
                    <input v-model="form.due_at" type="datetime-local" required class="rounded border bg-background p-2" />
                </label>
                <label class="grid gap-2 md:col-span-3">
                    Next action
                    <textarea v-model="form.next_action" required rows="3" class="rounded border bg-background p-2"></textarea>
                </label>
                <p v-for="(error, key) in form.errors" :key="key" role="alert" class="text-sm text-red-600 md:col-span-3">{{ error }}</p>
                <button :disabled="form.processing" class="w-fit rounded bg-primary px-4 py-2 text-primary-foreground">Save assignment</button>
            </form>
        </section>

        <AdminTable
            :rows="cases.data"
            :columns="[
                { key: 'id', label: 'Case' },
                { key: 'priority', label: 'Priority' },
                { key: 'status', label: 'Status' },
                { key: 'owner', label: 'Owner' },
                { key: 'next_action', label: 'Next action' },
                { key: 'due_at', label: 'Due' },
                { key: 'overdue', label: 'Overdue' },
            ]"
            :previous="cases.prev_page_url"
            :next="cases.next_page_url"
        >
            <template #actions="{ row }">
                <button v-if="canEdit" type="button" class="underline" @click="manage(row as CaseRow)">Assign</button>
            </template>
        </AdminTable>
    </div>
</template>
