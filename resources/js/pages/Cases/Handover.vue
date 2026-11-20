<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import AdminTable from '@/components/AdminTable.vue';
import { assignments, handover } from '@/routes/cases';
import { store } from '@/routes/cases/handover';

defineOptions({ layout: AppLayout });

type UnresolvedRow = { id: number; priority: string | null; status: string; owner_id: number | null; next_action: string | null; due_at: string | null; overdue: boolean };
type HandoverRow = { id: number; outgoing: string | null; incoming: string | null; shift_started_at: string | null; shift_ended_at: string | null; unresolved_cases: { case_id: number }[]; notes: string | null; acknowledged_at: string | null };

const props = defineProps<{
    unresolved: UnresolvedRow[];
    ready: boolean;
    handovers: HandoverRow[];
    owners: { id: number; name: string }[];
    canEdit: boolean;
    asOf: string;
}>();

const form = useForm({ shift_started_at: '', shift_ended_at: '', incoming_user_id: '', notes: '', acknowledged: false });
const history = computed(() => props.handovers.map((row) => ({ ...row, unresolved: row.unresolved_cases.length })));

function submit() {
    form.post(store.url(), { preserveScroll: true, onSuccess: () => form.reset() });
}
</script>

<template>
    <div class="space-y-6 p-6">
        <Head title="Shift handover" />
        <Heading
            title="End-of-shift handover"
            description="No unresolved case leaves a shift without an owner and a due action. Handover is blocked until every case you own has an active assignment."
        />
        <nav class="flex flex-wrap gap-4 text-sm">
            <Link :href="assignments()">Assignments</Link>
            <Link :href="handover()">Shift handover</Link>
            <span class="text-muted-foreground">As of {{ asOf }}</span>
        </nav>
        <p :class="ready ? 'rounded border border-primary bg-primary/5 p-3 text-sm' : 'rounded border border-amber-500 bg-amber-50 p-3 text-sm text-amber-900'" role="status">
            <template v-if="ready">Every unresolved case you own has an owner and a due next action.</template>
            <template v-else>
                {{ unresolved.filter((row) => row.next_action === null).length }} unresolved case(s) still need an owner and due action.
                <Link :href="assignments()" class="underline">Open assignments</Link>.
            </template>
        </p>

        <section class="space-y-3">
            <h2 class="text-lg font-semibold">My unresolved cases</h2>
            <AdminTable
                :rows="unresolved"
                :columns="[
                    { key: 'id', label: 'Case' },
                    { key: 'priority', label: 'Priority' },
                    { key: 'status', label: 'Status' },
                    { key: 'next_action', label: 'Next action' },
                    { key: 'due_at', label: 'Due' },
                    { key: 'overdue', label: 'Overdue' },
                ]"
            />
        </section>

        <section v-if="canEdit" class="space-y-4 rounded-xl border bg-card p-5">
            <h2 class="font-semibold">Record this shift handover</h2>
            <form class="grid gap-4 md:grid-cols-2" @submit.prevent="submit">
                <label class="grid gap-2">
                    Shift started
                    <input v-model="form.shift_started_at" type="datetime-local" required class="rounded border bg-background p-2" />
                </label>
                <label class="grid gap-2">
                    Shift ended
                    <input v-model="form.shift_ended_at" type="datetime-local" required class="rounded border bg-background p-2" />
                </label>
                <label class="grid gap-2">
                    Incoming owner
                    <select v-model="form.incoming_user_id" class="rounded border bg-background p-2">
                        <option value="">Not yet accepted</option>
                        <option v-for="owner in owners" :key="owner.id" :value="String(owner.id)">{{ owner.name }}</option>
                    </select>
                </label>
                <label v-if="form.incoming_user_id" class="flex items-center gap-2 py-2 text-sm">
                    <input v-model="form.acknowledged" type="checkbox" />Incoming owner acknowledged the handover
                </label>
                <label class="grid gap-2 md:col-span-2">
                    Handover notes
                    <textarea v-model="form.notes" rows="3" class="rounded border bg-background p-2"></textarea>
                </label>
                <p v-for="(error, key) in form.errors" :key="key" role="alert" class="text-sm text-red-600 md:col-span-2">{{ error }}</p>
                <button :disabled="form.processing || !ready" class="w-fit rounded bg-primary px-4 py-2 text-primary-foreground disabled:opacity-50">
                    Record handover
                </button>
            </form>
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-semibold">Handover history</h2>
            <AdminTable
                :rows="history"
                :columns="[
                    { key: 'id', label: 'Handover' },
                    { key: 'outgoing', label: 'Outgoing' },
                    { key: 'incoming', label: 'Incoming' },
                    { key: 'shift_started_at', label: 'Shift started' },
                    { key: 'shift_ended_at', label: 'Shift ended' },
                    { key: 'unresolved', label: 'Unresolved cases' },
                    { key: 'acknowledged_at', label: 'Acknowledged' },
                ]"
            />
        </section>
    </div>
</template>
