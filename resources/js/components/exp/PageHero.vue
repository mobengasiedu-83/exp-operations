<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const props = withDefaults(
    defineProps<{
        eyebrow?: string;
        title: string;
        description?: string;
        accent?: 'emerald' | 'violet' | 'amber';
    }>(),
    { accent: 'emerald' },
);

const page = usePage();
const firstName = computed<string>(() => {
    const name = (page.props.auth as { user?: { name?: string } } | undefined)
        ?.user?.name;

    return typeof name === 'string' ? name.split(' ')[0] : 'there';
});

const gradient = computed(() =>
    ({
        emerald:
            'from-emerald-600 via-teal-600 to-cyan-700 dark:from-emerald-700 dark:via-teal-800 dark:to-slate-900',
        violet: 'from-violet-600 via-indigo-600 to-sky-700 dark:from-violet-800 dark:via-indigo-900 dark:to-slate-900',
        amber: 'from-amber-500 via-orange-600 to-rose-600 dark:from-amber-700 dark:via-orange-800 dark:to-slate-900',
    })[props.accent],
);
</script>

<template>
    <section
        class="exp-rise relative overflow-hidden rounded-3xl bg-gradient-to-br p-6 text-white shadow-xl shadow-emerald-900/10 sm:p-8"
        :class="gradient"
    >
        <div
            class="exp-grid pointer-events-none absolute inset-0 opacity-20 mix-blend-overlay"
            aria-hidden="true"
        />
        <div
            class="exp-drift pointer-events-none absolute -top-24 -right-16 h-72 w-72 rounded-full bg-white/20 blur-3xl"
            aria-hidden="true"
        />
        <div
            class="exp-drift pointer-events-none absolute -bottom-28 left-1/4 h-64 w-64 rounded-full bg-emerald-200/30 blur-3xl"
            style="animation-delay: -4s"
            aria-hidden="true"
        />

        <div class="relative flex flex-wrap items-start justify-between gap-6">
            <div class="max-w-2xl space-y-3">
                <p
                    v-if="eyebrow"
                    class="text-xs font-semibold tracking-[0.2em] text-white/70 uppercase"
                >
                    {{ eyebrow }}
                </p>
                <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">
                    {{ title }}
                </h1>
                <p v-if="description" class="text-sm leading-relaxed text-white/80">
                    {{ description }}
                </p>
                <p
                    v-if="!description"
                    class="text-sm text-white/80"
                >
                    Welcome back, {{ firstName }}.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <slot name="actions" />
            </div>
        </div>

        <div v-if="$slots.stats" class="relative mt-7">
            <slot name="stats" />
        </div>
    </section>
</template>
