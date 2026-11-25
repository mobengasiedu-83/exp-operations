<script setup lang="ts">
import { computed } from 'vue';
import type { Component } from 'vue';

/**
 * Journey outcomes as a ring.
 *
 * Only "answered by an agent" and "no agent answer" are a true partition of the
 * window — they add up to the total. The dentist, active and completed counters
 * overlap that partition, so they are listed underneath as counters rather than
 * painted into the ring.
 */
const props = defineProps<{
    answered: number;
    abandoned: number;
    total: number;
    counters: { label: string; value: number; icon: Component }[];
}>();

const segments = computed(() => {
    const answeredShare =
        props.total > 0 ? (props.answered / props.total) * 100 : 0;
    const abandonedShare =
        props.total > 0 ? (props.abandoned / props.total) * 100 : 0;

    return [
        {
            key: 'answered',
            label: 'Answered by an agent',
            value: props.answered,
            share: answeredShare,
            hex: '#10b981',
            dot: 'bg-emerald-500',
        },
        {
            key: 'abandoned',
            label: 'No agent answer',
            value: props.abandoned,
            share: abandonedShare,
            hex: '#f43f5e',
            dot: 'bg-rose-500',
        },
    ];
});

const gradient = computed(() => {
    if (props.total <= 0) {
        return 'conic-gradient(var(--muted) 0% 100%)';
    }

    const stops: string[] = [];
    let cursor = 0;

    for (const segment of segments.value) {
        if (segment.share <= 0) {
            continue;
        }

        const start = cursor;
        cursor += segment.share;
        stops.push(`${segment.hex} ${start}% ${cursor}%`);
    }

    return `conic-gradient(from -90deg, ${stops.join(', ')})`;
});

function percentOf(share: number): string {
    return `${Math.round(share)}%`;
}
</script>

<template>
    <div class="flex flex-col items-center gap-6 sm:flex-row sm:items-center">
        <div
            class="relative size-40 shrink-0"
            role="img"
            :aria-label="`${answered} of ${total} journeys were answered by an agent`"
        >
            <div
                class="exp-pop absolute inset-0 rounded-full shadow-inner ring-1 ring-black/5 dark:ring-white/10"
                :style="{ background: gradient }"
            />
            <div
                class="absolute inset-[15%] grid place-content-center gap-0.5 rounded-full bg-card text-center shadow-sm ring-1 ring-border"
            >
                <p class="text-2xl font-semibold tabular-nums">
                    {{ total.toLocaleString() }}
                </p>
                <p
                    class="text-[10px] font-semibold tracking-[0.16em] text-muted-foreground uppercase"
                >
                    journeys
                </p>
            </div>
        </div>

        <div class="w-full space-y-3">
            <div
                v-for="segment in segments"
                :key="segment.key"
                class="group rounded-xl border border-transparent px-3 py-2 transition duration-200 hover:border-border hover:bg-muted/50"
            >
                <div class="flex items-center justify-between gap-3">
                    <span class="inline-flex items-center gap-2 text-sm">
                        <span
                            class="size-2.5 rounded-full ring-4 ring-white/30 transition ring-inset group-hover:scale-110"
                            :class="segment.dot"
                            aria-hidden="true"
                        />
                        {{ segment.label }}
                    </span>
                    <span class="text-sm font-semibold tabular-nums">
                        {{ segment.value }}
                    </span>
                </div>
                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-muted">
                    <div
                        class="exp-grow-x h-full rounded-full transition-[width] duration-500"
                        :style="{
                            width: `${segment.share}%`,
                            backgroundColor: segment.hex,
                        }"
                    />
                </div>
                <p class="mt-1 text-[11px] text-muted-foreground">
                    {{ percentOf(segment.share) }} of journeys in this window
                </p>
            </div>

            <dl class="grid gap-2 border-t pt-3 sm:grid-cols-3">
                <div
                    v-for="counter in counters"
                    :key="counter.label"
                    class="rounded-xl bg-muted/50 px-3 py-2"
                >
                    <dt
                        class="flex items-center gap-1.5 text-[10px] font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        <component
                            :is="counter.icon"
                            class="size-3"
                            aria-hidden="true"
                        />
                        {{ counter.label }}
                    </dt>
                    <dd class="mt-0.5 text-lg font-semibold tabular-nums">
                        {{ counter.value }}
                    </dd>
                </div>
            </dl>
            <p class="text-[11px] text-muted-foreground">
                These counters overlap the ring: a journey can reach a dentist
                and still be active, so they are not a second partition.
            </p>
        </div>
    </div>
</template>
