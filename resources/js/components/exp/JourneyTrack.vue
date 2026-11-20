<script setup lang="ts">
import { computed } from 'vue';

type Step = {
    key?: string;
    label: string;
    detail?: string | null;
    at?: string | null;
    tone?: string;
    title?: string;
};

const props = withDefaults(
    defineProps<{
        steps: Step[];
        variant?: 'track' | 'chips';
    }>(),
    { variant: 'track' },
);

const tones: Record<string, { dot: string; chip: string; text: string }> = {
    brand: {
        dot: 'bg-emerald-500',
        chip: 'bg-emerald-500/10 text-emerald-700 ring-emerald-500/30 dark:text-emerald-300',
        text: 'text-emerald-600 dark:text-emerald-300',
    },
    info: {
        dot: 'bg-sky-500',
        chip: 'bg-sky-500/10 text-sky-700 ring-sky-500/30 dark:text-sky-300',
        text: 'text-sky-600 dark:text-sky-300',
    },
    success: {
        dot: 'bg-teal-500',
        chip: 'bg-teal-500/10 text-teal-700 ring-teal-500/30 dark:text-teal-300',
        text: 'text-teal-600 dark:text-teal-300',
    },
    warning: {
        dot: 'bg-amber-500',
        chip: 'bg-amber-500/10 text-amber-700 ring-amber-500/30 dark:text-amber-300',
        text: 'text-amber-600 dark:text-amber-300',
    },
    danger: {
        dot: 'bg-rose-500',
        chip: 'bg-rose-500/10 text-rose-700 ring-rose-500/30 dark:text-rose-300',
        text: 'text-rose-600 dark:text-rose-300',
    },
    muted: {
        dot: 'bg-slate-400',
        chip: 'bg-slate-500/10 text-slate-600 ring-slate-500/20 dark:text-slate-300',
        text: 'text-slate-500 dark:text-slate-400',
    },
};

const resolved = computed(() =>
    props.steps.map((step) => ({
        ...step,
        tone: tones[step.tone ?? 'muted'] ?? tones.muted,
    })),
);

function clock(value?: string | null): string | null {
    if (!value) {
        return null;
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? null
        : date.toLocaleTimeString('en-GB', {
              hour: '2-digit',
              minute: '2-digit',
          });
}
</script>

<template>
    <ol
        v-if="variant === 'track'"
        class="relative space-y-4 border-l border-dashed border-border/70 pl-5"
    >
        <li
            v-for="(step, index) in resolved"
            :key="step.key ?? index"
            class="exp-rise relative"
            :class="`exp-rise-${Math.min(index + 1, 4)}`"
        >
            <span
                class="exp-ring absolute top-1.5 -left-[26px] size-2.5 rounded-full ring-4 ring-background"
                :class="step.tone.dot"
                aria-hidden="true"
            />
            <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                <p class="text-sm font-medium">{{ step.label }}</p>
                <span v-if="clock(step.at)" class="text-xs tabular-nums text-muted-foreground">
                    {{ clock(step.at) }}
                </span>
            </div>
            <p v-if="step.detail" class="mt-0.5 text-xs" :class="step.tone.text">
                {{ step.detail }}
            </p>
        </li>
    </ol>

    <div v-else class="flex flex-wrap items-center gap-1.5">
        <template v-for="(step, index) in resolved" :key="step.key ?? index">
            <span
                class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1"
                :class="step.tone.chip"
                :title="step.title ?? step.detail ?? step.label"
            >
                {{ step.label }}
            </span>
            <span
                v-if="index < resolved.length - 1"
                class="text-xs text-muted-foreground"
                aria-hidden="true"
                >→</span
            >
        </template>
    </div>
</template>
