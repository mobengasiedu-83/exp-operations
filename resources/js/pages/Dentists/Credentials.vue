<script setup lang="ts">
import { reactive, ref } from "vue";
import { Head, router, useForm } from "@inertiajs/vue3";
import AppLayout from "@/layouts/AppLayout.vue";
import Heading from "@/components/Heading.vue";
import DentistNav from "@/components/DentistNav.vue";
import { credentialClasses, credentialLabel } from "@/lib/dentists";
import {
    destroy as destroyCredential,
    store as storeCredential,
    update as updateCredential,
} from "@/routes/dentists/credentials";
import { credentials as credentialsPage } from "@/routes/dentists";

defineOptions({ layout: AppLayout });

type CredentialRow = {
    id: number;
    dentist_id: number;
    type: string;
    number: string;
    issuer: string | null;
    issued_on: string | null;
    expires_on: string | null;
    verified_at: string | null;
    notes: string | null;
    dentist: { id: number; name: string } | null;
};

const props = defineProps<{
    credentials: {
        data: CredentialRow[];
        prev_page_url: string | null;
        next_page_url: string | null;
        total: number;
    };
    dentists: { id: number; name: string }[];
    counts: { expired: number; expiring: number; total: number };
    expiringWindowDays: number;
    filters: { state: string; dentist_id: string };
    canManage: boolean;
}>();

const filters = reactive({ ...props.filters });
const visible = ref(false);
const selected = ref<{ dentist: number; credential: number } | null>(null);
const form = useForm({
    dentist_id: "",
    type: "license",
    number: "",
    issuer: "",
    issued_on: "",
    expires_on: "",
    verified: true,
    notes: "",
});

function search() {
    router.get(credentialsPage.url(), { ...filters }, { preserveState: true });
}

function clearFilters() {
    filters.state = "";
    filters.dentist_id = "";
    router.get(credentialsPage.url(), {}, { preserveState: true });
}

function create() {
    selected.value = null;
    form.reset();
    form.dentist_id = props.dentists[0] ? String(props.dentists[0].id) : "";
    form.type = "license";
    form.verified = true;
    form.clearErrors();
    visible.value = true;
}

function edit(row: CredentialRow) {
    selected.value = { dentist: row.dentist_id, credential: row.id };
    form.dentist_id = String(row.dentist_id);
    form.type = row.type;
    form.number = row.number;
    form.issuer = row.issuer ?? "";
    form.issued_on = row.issued_on?.slice(0, 10) ?? "";
    form.expires_on = row.expires_on?.slice(0, 10) ?? "";
    form.verified = row.verified_at !== null;
    form.notes = row.notes ?? "";
    form.clearErrors();
    visible.value = true;
}

function save() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            visible.value = false;
            form.reset();
        },
    };

    if (selected.value !== null) {
        form.patch(updateCredential.url(selected.value), options);
    } else {
        form.post(storeCredential.url({ dentist: Number(form.dentist_id) }), options);
    }
}

function validity(row: CredentialRow): string {
    if (!row.expires_on) {
        return "no_expiry";
    }

    const expires = new Date(row.expires_on);
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    if (expires < today) {
        return "expired";
    }

    const threshold = new Date(today);
    threshold.setDate(threshold.getDate() + props.expiringWindowDays);

    return expires <= threshold ? "expiring" : "valid";
}

function remove(row: CredentialRow) {
    if (!window.confirm(`Remove ${row.type} ${row.number}?`)) {
        return;
    }

    router.delete(destroyCredential.url({ dentist: row.dentist_id, credential: row.id }), {
        preserveScroll: true,
    });
}
</script>

<template>
    <div>
        <Head title="Dentist credentials" />
        <div class="space-y-6 p-6">
            <Heading
                title="Dentist credentials"
                description="Licence, registration and indemnity tracking. Validity is derived from the expiry date so a lapsed credential is never reported as current."
            />
            <DentistNav />

            <section class="grid gap-4 sm:grid-cols-3">
                <div
                    v-for="metric in [
                        { label: 'Total', value: counts.total },
                        { label: 'Expired', value: counts.expired },
                        { label: `Expiring (${expiringWindowDays}d)`, value: counts.expiring },
                    ]"
                    :key="metric.label"
                    class="rounded-xl border bg-white p-4 shadow-sm dark:bg-slate-900"
                >
                    <p class="text-xs uppercase tracking-wide text-slate-500">{{ metric.label }}</p>
                    <p class="mt-1 text-2xl font-semibold">{{ metric.value }}</p>
                </div>
            </section>

            <form class="flex flex-wrap items-end gap-4" @submit.prevent="search">
                <label class="grid gap-2"
                    >Dentist<select v-model="filters.dentist_id" class="rounded border bg-background p-2">
                        <option value="">All</option>
                        <option v-for="dentist in dentists" :key="dentist.id" :value="String(dentist.id)">
                            {{ dentist.name }}
                        </option>
                    </select></label
                >
                <label class="grid gap-2"
                    >Validity<select v-model="filters.state" class="rounded border bg-background p-2">
                        <option value="">All</option>
                        <option v-for="state in ['valid', 'expiring', 'expired', 'no_expiry']" :key="state" :value="state">
                            {{ credentialLabel(state) }}
                        </option>
                    </select></label
                >
                <button type="submit" class="rounded bg-primary px-4 py-2 text-primary-foreground">Apply</button>
                <button type="button" class="text-sm underline" @click="clearFilters">Clear</button>
                <button v-if="canManage" type="button" class="rounded border px-4 py-2" @click="create">
                    Add credential
                </button>
            </form>

            <form v-if="visible && canManage" class="grid max-w-xl gap-4 rounded-xl border p-5" @submit.prevent="save">
                <h2 class="font-semibold">{{ selected ? "Edit" : "Add" }} credential</h2>
                <label class="grid gap-2"
                    >Dentist<select v-model="form.dentist_id" required class="rounded border bg-background p-2">
                        <option v-for="dentist in dentists" :key="dentist.id" :value="String(dentist.id)">
                            {{ dentist.name }}
                        </option>
                    </select></label
                >
                <label class="grid gap-2"
                    >Type<select v-model="form.type" class="rounded border bg-background p-2">
                        <option v-for="type in ['license', 'registration', 'indemnity']" :key="type" :value="type">
                            {{ type }}
                        </option>
                    </select></label
                >
                <label class="grid gap-2"
                    >Number<input v-model="form.number" type="text" required class="rounded border bg-background p-2"
                /></label>
                <label class="grid gap-2"
                    >Issuer<input v-model="form.issuer" type="text" class="rounded border bg-background p-2"
                /></label>
                <label class="grid gap-2"
                    >Issued on<input v-model="form.issued_on" type="date" class="rounded border bg-background p-2"
                /></label>
                <label class="grid gap-2"
                    >Expires on<input v-model="form.expires_on" type="date" class="rounded border bg-background p-2"
                /></label>
                <label class="flex gap-2"><input v-model="form.verified" type="checkbox" />Verified</label>
                <label class="grid gap-2"
                    >Notes<textarea v-model="form.notes" rows="3" class="rounded border bg-background p-2"></textarea>
                </label>
                <p v-for="(error, key) in form.errors" :key="key" class="text-sm text-red-600" role="alert">
                    {{ error }}
                </p>
                <div class="flex gap-3">
                    <button type="submit" :disabled="form.processing" class="rounded bg-primary px-4 py-2 text-primary-foreground">
                        Save
                    </button>
                    <button type="button" @click="visible = false">Cancel</button>
                </div>
            </form>

            <div class="space-y-4">
                <div class="overflow-x-auto rounded-xl border bg-white dark:bg-slate-900">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-800">
                            <tr>
                                <th class="px-4 py-3">Dentist</th>
                                <th class="px-4 py-3">Type</th>
                                <th class="px-4 py-3">Number</th>
                                <th class="px-4 py-3">Issuer</th>
                                <th class="px-4 py-3">Issued</th>
                                <th class="px-4 py-3">Expires</th>
                                <th class="px-4 py-3">Validity</th>
                                <th class="px-4 py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in credentials.data" :key="row.id" class="border-t">
                                <td class="px-4 py-3">{{ row.dentist?.name ?? "—" }}</td>
                                <td class="px-4 py-3">{{ row.type }}</td>
                                <td class="px-4 py-3 font-mono text-xs">{{ row.number }}</td>
                                <td class="px-4 py-3">{{ row.issuer ?? "—" }}</td>
                                <td class="px-4 py-3">{{ row.issued_on ?? "—" }}</td>
                                <td class="px-4 py-3">{{ row.expires_on ?? "—" }}</td>
                                <td class="px-4 py-3">
                                    <span
                                        class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                        :class="credentialClasses(validity(row))"
                                    >
                                        {{ credentialLabel(validity(row)) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div v-if="canManage" class="flex gap-3">
                                        <button type="button" class="underline" @click="edit(row)">Edit</button>
                                        <button type="button" class="underline" @click="remove(row)">Remove</button>
                                    </div>
                                    <span v-else class="text-xs text-slate-500">Read only</span>
                                </td>
                            </tr>
                            <tr v-if="credentials.data.length === 0">
                                <td colspan="8" class="px-4 py-10 text-center text-slate-500">
                                    No credentials recorded for this filter.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <nav class="flex gap-4" aria-label="Pagination">
                    <a v-if="credentials.prev_page_url" :href="credentials.prev_page_url">Previous</a>
                    <a v-if="credentials.next_page_url" :href="credentials.next_page_url">Next</a>
                </nav>
            </div>
        </div>
    </div>
</template>

