<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import type { Component } from 'vue';

const props = withDefaults(
    defineProps<{
        label: string;
        value: number | string;
        hint?: string;
        tone?: 'emerald' | 'sky' | 'amber' | 'rose' | 'slate' | 'onDark';
        icon?: Component;
        delay?: 1 | 2 | 3 | 4;
    }>(),
    { tone: 'slate', delay: 1 },
);

const displayed = ref<number>(0);
const isNumber = computed(() => typeof props.value === 'number');
const shown = computed(() =>
    isNumber.value ? Math.round(displayed.value).toLocaleString() : props.value,
);

const tones: Record<string, { ring: string; text: string; chip: string }> = {
    emerald: {
        ring: 'ring-emerald-500/20',
        text: 'text-emerald-600 dark:text-emerald-300',
        chip: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    },
    sky: {
        ring: 'ring-sky-500/20',
        text: 'text-sky-600 dark:text-sky-300',
        chip: 'bg-sky-500/10 text-sky-700 dark:text-sky-300',
    },
    amber: {
        ring: 'ring-amber-500/20',
        text: 'text-amber-600 dark:text-amber-300',
        chip: 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
    },
    rose: {
        ring: 'ring-rose-500/20',
        text: 'text-rose-600 dark:text-rose-300',
        chip: 'bg-rose-500/10 text-rose-700 dark:text-rose-300',
    },
    slate: {
        ring: 'ring-slate-500/15',
        text: 'text-slate-700 dark:text-slate-200',
        chip: 'bg-slate-500/10 text-slate-700 dark:text-slate-200',
    },
    onDark: {
        ring: 'ring-white/25',
        text: 'text-white',
        chip: 'bg-white/15 text-white',
    },
};

const style = computed(() => tones[props.tone] ?? tones.slate);
const isOnDark = computed(() => props.tone === 'onDark');
const labelClass = computed(() =>
    isOnDark.value ? 'text-white/70' : 'text-muted-foreground',
);
const hintClass = computed(() =>
    isOnDark.value ? 'text-white/60' : 'text-muted-foreground',
);

function animate(to: number): void {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        displayed.value = to;

        return;
    }

    const start = performance.now();
    const duration = 700;

    const step = (now: number): void => {
        const progress = Math.min((now - start) / duration, 1);
        const eased = 1 - Math.pow(1 - progress, 3);
        displayed.value = to * eased;

        if (progress < 1) {
            requestAnimationFrame(step);
        }
    };

    requestAnimationFrame(step);
}

onMounted(() => {
    if (isNumber.value) {
        animate(Number(props.value));
    }
});

watch(
    () => props.value,
    (next) => {
        if (typeof next === 'number') {
            animate(next);
        }
    },
);
</script>

<template>
    <div
        class="operations-stat exp-rise group relative overflow-hidden rounded-2xl border p-4 shadow-sm ring-1 transition hover:-translate-y-0.5 hover:shadow-md"
        :class="[
            style.ring,
            `exp-rise-${delay}`,
            isOnDark
                ? 'border-white/20 bg-white/10 backdrop-blur'
                : 'border-border bg-card',
        ]"
    >
        <div class="flex items-start justify-between gap-3">
            <p
                class="text-xs font-semibold tracking-wide uppercase"
                :class="labelClass"
            >
                {{ label }}
            </p>
            <span
                v-if="icon"
                class="rounded-xl p-1.5"
                :class="style.chip"
                aria-hidden="true"
            >
                <component :is="icon" class="size-4" />
            </span>
        </div>
        <p class="mt-2 text-3xl font-semibold tabular-nums" :class="style.text">
            {{ shown }}
        </p>
        <p v-if="hint" class="mt-1 text-xs" :class="hintClass">{{ hint }}</p>
        <div
            class="pointer-events-none absolute inset-x-0 bottom-0 h-px bg-gradient-to-r from-transparent via-emerald-500/40 to-transparent opacity-0 transition group-hover:opacity-100"
            aria-hidden="true"
        />
    </div>
</template>
