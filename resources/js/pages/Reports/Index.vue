<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import { show } from '@/routes/reports';

defineOptions({ layout: AppLayout });

type LatestRun = {
    reference: string;
    period_start: string;
    period_end: string;
    generated_at: string;
    revision: number;
} | null;

type Definition = {
    id: number;
    key: string;
    name: string;
    category: string;
    grain: string;
    description: string;
    sensitive: boolean;
    kpi_count: number;
    latest_run: LatestRun;
};

const props = defineProps<{
    definitions: Definition[];
    period: { today: string; max_days: number };
}>();

const categoryLabels: Record<string, string> = {
    operations: 'Operations',
    safety: 'Safety and incidents',
    quality: 'Quality assurance',
    follow_up: 'Callback follow-up',
    dentists: 'Dentist engagement',
    executive: 'Executive',
};

const grouped = computed(() => {
    const groups = new Map<string, Definition[]>();

    for (const definition of props.definitions) {
        groups.set(definition.category, [
            ...(groups.get(definition.category) ?? []),
            definition,
        ]);
    }

    return [...groups.entries()].map(([category, definitions]) => ({
        category,
        label: categoryLabels[category] ?? category,
        definitions,
    }));
});
</script>

<template>
    <Head title="Operational reports" />
    <div class="space-y-6 p-6">
        <Heading
            title="Operational reports"
            description="Management visibility built only from evidence EXP already stores. Every figure carries its definition, numerator, denominator and exclusions; evidence the PBX did not record stays empty instead of being guessed."
        />

        <div class="rounded-xl border p-4 text-sm text-muted-foreground">
            A manual report defaults to today and covers at most
            {{ period.max_days }} days. Saving a run stores the KPI snapshot with
            a checksum and revision, so re-running the same period keeps the
            earlier figure visible instead of silently replacing it.
        </div>

        <section
            v-for="group in grouped"
            :key="group.category"
            class="space-y-3"
        >
            <h2 class="text-lg font-semibold">{{ group.label }}</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <Link
                    v-for="definition in group.definitions"
                    :key="definition.id"
                    :href="
                        show(definition.id, {
                            query: { from: period.today, to: period.today },
                        })
                    "
                    class="rounded-xl border p-4 transition hover:border-slate-400"
                >
                    <div class="flex items-start justify-between gap-3">
                        <h3 class="font-medium">{{ definition.name }}</h3>
                        <span
                            v-if="definition.sensitive"
                            class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900"
                        >
                            Sensitive
                        </span>
                    </div>
                    <p class="mt-2 text-sm text-muted-foreground">
                        {{ definition.description }}
                    </p>
                    <p class="mt-3 text-xs text-muted-foreground">
                        {{ definition.kpi_count }} KPIs · {{ definition.grain }} ·
                        {{ definition.key }}
                    </p>
                    <p v-if="definition.latest_run" class="mt-2 text-xs">
                        Last run {{ definition.latest_run.reference }} ·
                        {{ definition.latest_run.period_start }} →
                        {{ definition.latest_run.period_end }} · revision
                        {{ definition.latest_run.revision }}
                    </p>
                    <p v-else class="mt-2 text-xs text-muted-foreground">
                        No saved run for this definition yet.
                    </p>
                </Link>
            </div>
        </section>

        <p
            v-if="definitions.length === 0"
            class="rounded-xl border p-6 text-muted-foreground"
        >
            No report definition is published for your permissions. Executive
            summaries need the explicit executive reporting grant.
        </p>
    </div>
</template>
