<script setup lang="ts">
import { Clock, PhoneIncoming, Stethoscope } from '@lucide/vue';
</script>

<!--
    Decorative "measured minutes" illustration for the Call time hero. It paints
    from the hero's white text colour, so a single rule set works in both themes.
    Everything here is presentational: the floating chips restate facts the page
    already shows, and every animation stops under `prefers-reduced-motion`.
-->
<template>
    <div class="relative h-48 w-[21rem] text-white">
        <svg
            class="absolute inset-0 h-full w-full text-teal-200/70"
            viewBox="0 0 336 192"
            fill="none"
            aria-hidden="true"
        >
            <circle
                cx="168"
                cy="96"
                r="78"
                stroke="currentColor"
                stroke-opacity=".14"
            />
            <circle
                class="exp-flow"
                cx="168"
                cy="96"
                r="64"
                stroke="currentColor"
                stroke-opacity=".55"
                stroke-width="1.5"
                stroke-dasharray="4 12"
                stroke-linecap="round"
            />
            <circle
                cx="168"
                cy="96"
                r="50"
                stroke="currentColor"
                stroke-opacity=".22"
                stroke-dasharray="1 6"
            />
            <path
                d="M168 34v-8M168 166v-8M106 96h-8M238 96h-8"
                stroke="currentColor"
                stroke-opacity=".35"
                stroke-width="1.5"
                stroke-linecap="round"
            />
        </svg>

        <div
            class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2"
        >
            <span
                class="relative grid size-20 place-items-center rounded-3xl border border-white/30 bg-white/15 shadow-xl shadow-emerald-950/40 backdrop-blur"
            >
                <Clock class="size-8" :stroke-width="1.5" aria-hidden="true" />
                <span
                    class="exp-ping-soft absolute inset-0 rounded-3xl ring-2 ring-white/40"
                />
                <span
                    class="absolute -inset-2 rounded-[1.6rem] border border-white/15"
                />
            </span>
        </div>

        <div
            class="exp-float absolute top-1 left-0 inline-flex items-center gap-1.5 rounded-full border border-white/25 bg-white/15 px-2.5 py-1 text-[11px] font-semibold text-white/90 backdrop-blur"
        >
            <PhoneIncoming class="size-3" aria-hidden="true" />
            Wait 0:42
        </div>

        <div
            class="exp-float absolute top-8 right-0 inline-flex items-center gap-1.5 rounded-full border border-white/25 bg-white/15 px-2.5 py-1 text-[11px] font-semibold text-white/90 backdrop-blur"
            style="animation-delay: -2.4s"
        >
            <Stethoscope class="size-3" aria-hidden="true" />
            Bridge 6:18
        </div>

        <div
            class="exp-float absolute bottom-4 left-3 inline-flex items-center gap-1.5 rounded-full border border-white/25 bg-white/15 px-2.5 py-1 text-[11px] font-semibold text-white/90 backdrop-blur"
            style="animation-delay: -3.6s"
        >
            <span
                class="size-1.5 rounded-full bg-emerald-300"
                aria-hidden="true"
            />
            Dial 0:11
        </div>

        <p
            class="absolute inset-x-0 bottom-0 text-center text-[10px] font-semibold tracking-[0.22em] text-white/45 uppercase"
        >
            Measured · never estimated
        </p>
    </div>
</template>
