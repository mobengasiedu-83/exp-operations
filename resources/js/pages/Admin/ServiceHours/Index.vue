<script setup lang="ts">
import { ref } from "vue";
import { Head, useForm, usePage } from "@inertiajs/vue3";
import AppLayout from "@/layouts/AppLayout.vue";
import Heading from "@/components/Heading.vue";
import AdminTable from "@/components/AdminTable.vue";
import endpoints from "@/routes/admin/service-hours";
type Row = Record<string, unknown> & { id: number };
const props = defineProps<{
    hours: { data: Row[]; prev_page_url: string | null; next_page_url: string | null };
    service: {
        state: string;
        label: string;
        reason: string;
        opens_at: string;
        closes_at: string;
        next_opening_label: string | null;
        holiday: string | null;
    };
    holidays: { date: string; label: string; weekday: string }[];
}>();
const page = usePage();
const can = (permission: string) => page.props.auth.permissions.includes(permission);
const selected = ref<number | null>(null);
const visible = ref(false);
const weekdays = [
    { value: 1, label: "Mon" },
    { value: 2, label: "Tue" },
    { value: 3, label: "Wed" },
    { value: 4, label: "Thu" },
    { value: 5, label: "Fri" },
    { value: 6, label: "Sat" },
    { value: 7, label: "Sun" },
];
const form = useForm({
    name: "",
    opens_at: "10:00",
    closes_at: "14:00",
    days: [] as number[],
    is_closed: false,
    effective_from: "",
    effective_until: "",
    description: "",
});
function edit(row: Record<string, unknown>) {
    selected.value = Number(row.id);
    form.name = String(row.name ?? "");
    form.opens_at = String(row.opens_at ?? "").slice(0, 5);
    form.closes_at = String(row.closes_at ?? "").slice(0, 5);
    form.days = Array.isArray(row.days) ? row.days.map(Number) : [];
    form.is_closed = Boolean(row.is_closed);
    form.effective_from = String(row.effective_from ?? "").slice(0, 10);
    form.effective_until = String(row.effective_until ?? "").slice(0, 10);
    form.description = String(row.description ?? "");
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
        <Head title="Service Hours" />
        <div class="space-y-6 p-6">
            <Heading
                title="Service Hours"
                description="The published line window is Monday to Friday, 10:00–14:00 Ghana time, closed on Ghana public holidays. Rosters and call screens read this calendar."
            />

            <section
                class="rounded-2xl border bg-gradient-to-br from-emerald-50 via-white to-sky-50 p-5 shadow-sm dark:from-emerald-950/40 dark:via-slate-900 dark:to-slate-900"
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold tracking-wide text-emerald-700 uppercase dark:text-emerald-300">
                            Ghana service calendar
                        </p>
                        <p class="mt-1 text-lg font-semibold">
                            Monday to Friday · {{ service.opens_at }}–{{ service.closes_at }}
                        </p>
                        <p class="text-sm text-muted-foreground">{{ service.reason }}</p>
                    </div>
                    <span
                        class="rounded-full px-3 py-1.5 text-xs font-semibold ring-1"
                        :class="
                            service.state === 'open'
                                ? 'bg-emerald-500/15 text-emerald-800 ring-emerald-500/30 dark:text-emerald-200'
                                : 'bg-slate-500/10 text-slate-700 ring-slate-500/20 dark:text-slate-200'
                        "
                    >
                        {{ service.label }}
                    </span>
                </div>
                <ul class="mt-4 flex flex-wrap gap-2">
                    <li
                        v-for="holiday in holidays"
                        :key="holiday.date"
                        class="rounded-full bg-white/80 px-3 py-1 text-xs text-slate-700 ring-1 ring-slate-200 dark:bg-slate-900/70 dark:text-slate-200 dark:ring-slate-800"
                    >
                        <span class="font-semibold">{{ holiday.weekday }} {{ holiday.date.slice(5) }}</span>
                        · {{ holiday.label }}
                    </li>
                </ul>
            </section>

            <button v-if="can('edit settings')" class="rounded border px-4 py-2" @click="create">
                Add Service Hours
            </button>
            <form
                v-if="visible"
                class="grid max-w-xl gap-4 rounded-xl border p-5"
                @submit.prevent="save"
            >
                <h2 class="font-semibold">{{ selected ? "Edit" : "Add" }} Service Hours</h2>
                <label class="grid gap-2"
                    >Name<input
                        v-model="form.name"
                        type="text"
                        class="rounded border bg-background p-2" /></label
                ><label class="grid gap-2"
                    >Opens (Africa/Accra)<input
                        v-model="form.opens_at"
                        type="time"
                        class="rounded border bg-background p-2" /></label
                ><label class="grid gap-2"
                    >Closes (Africa/Accra)<input
                        v-model="form.closes_at"
                        type="time"
                        class="rounded border bg-background p-2" /></label
                ><div class="grid gap-2">
                    <span class="text-sm">Days the window runs</span>
                    <div class="flex flex-wrap gap-3">
                        <label
                            v-for="day in weekdays"
                            :key="day.value"
                            class="inline-flex items-center gap-1.5 text-sm"
                        >
                            <input v-model="form.days" type="checkbox" :value="day.value" />
                            {{ day.label }}
                        </label>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Leave every box clear for “runs daily”. Public holidays are closed by the
                        Ghana calendar and are not listed as weekday exceptions.
                    </p>
                </div>
                <label class="flex gap-2"
                    ><input v-model="form.is_closed" type="checkbox" />Closed</label
                ><label class="grid gap-2"
                    >Effective from<input
                        v-model="form.effective_from"
                        type="date"
                        class="rounded border bg-background p-2" /></label
                ><label class="grid gap-2"
                    >Effective until<input
                        v-model="form.effective_until"
                        type="date"
                        class="rounded border bg-background p-2" /></label
                ><label class="grid gap-2"
                    >Description<input
                        v-model="form.description"
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
                :rows="props.hours.data"
                :columns="[
                    { key: 'name', label: 'Name' },
                    { key: 'opens_at', label: 'Opens At' },
                    { key: 'closes_at', label: 'Closes At' },
                    { key: 'days', label: 'Days' },
                    { key: 'is_closed', label: 'Is Closed' },
                    { key: 'effective_from', label: 'Effective From' },
                    { key: 'effective_until', label: 'Effective Until' },
                ]"
                :previous="props.hours.prev_page_url"
                :next="props.hours.next_page_url"
            >
                <template #cell-days="{ row }">
                    {{
                        Array.isArray(row.days) && row.days.length
                            ? row.days
                                  .map(
                                      (day: number) =>
                                          weekdays.find((entry) => entry.value === Number(day))
                                              ?.label ?? day,
                                  )
                                  .join(" · ")
                            : "Daily"
                    }}
                </template>
                <template #actions="{ row }"
                    ><button v-if="can('edit settings')" @click="edit(row)">Edit</button></template
                >
            </AdminTable>
        </div>
    </div>
</template>
