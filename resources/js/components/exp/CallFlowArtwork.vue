<script setup lang="ts">
import { CircleCheck, Headphones, PhoneIncoming, Stethoscope } from "@lucide/vue";
</script>

<!--
    Decorative call-journey illustration for the call history hero. Purely
    presentational: it paints from the hero's white text colour and every
    animation stops under `prefers-reduced-motion`.
-->
<template>
    <div class="relative h-36 w-[19rem]">
        <svg
            class="absolute inset-0 h-full w-full text-teal-200/70"
            viewBox="0 0 304 144"
            fill="none"
        >
            <path
                class="exp-flow"
                d="M64 72 H112"
                stroke="currentColor"
                stroke-width="1.5"
                stroke-linecap="round"
                stroke-dasharray="5 7"
            />
            <path
                class="exp-flow"
                d="M192 72 H240"
                stroke="currentColor"
                stroke-width="1.5"
                stroke-linecap="round"
                stroke-dasharray="5 7"
                style="animation-delay: -2s"
            />
            <circle
                cx="152"
                cy="72"
                r="38"
                stroke="currentColor"
                stroke-opacity=".28"
                stroke-dasharray="3 7"
            />
            <circle cx="152" cy="72" r="58" stroke="currentColor" stroke-opacity=".14" />
        </svg>

        <div class="absolute top-1/2 left-1 -translate-y-1/2">
            <span class="relative grid size-11 place-items-center rounded-2xl border border-white/25 bg-white/10 text-white shadow-lg shadow-emerald-950/30 backdrop-blur">
                <PhoneIncoming class="size-5" :stroke-width="1.7" />
                <span class="exp-ping-soft absolute inset-0 rounded-2xl ring-2 ring-white/40" />
            </span>
            <span class="mt-1.5 block text-center text-[9px] font-semibold tracking-[0.18em] text-white/55 uppercase">
                Caller
            </span>
        </div>

        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2">
            <span class="relative grid size-14 place-items-center rounded-2xl border border-white/30 bg-white/15 text-white shadow-xl shadow-emerald-950/40 backdrop-blur">
                <Headphones class="size-6" :stroke-width="1.6" />
                <span class="absolute -inset-1.5 rounded-[1.35rem] border border-white/15" />
            </span>
        </div>

        <div class="absolute top-1/2 right-1 -translate-y-1/2">
            <span class="relative grid size-11 place-items-center rounded-2xl border border-white/25 bg-white/10 text-white shadow-lg shadow-emerald-950/30 backdrop-blur">
                <Stethoscope class="size-5" :stroke-width="1.7" />
            </span>
            <span class="mt-1.5 block text-center text-[9px] font-semibold tracking-[0.18em] text-white/55 uppercase">
                Dentist
            </span>
        </div>

        <div
            class="exp-float absolute top-0 right-6 inline-flex items-center gap-1 rounded-full border border-white/25 bg-white/15 px-2 py-0.5 text-[10px] font-semibold text-white/90 backdrop-blur"
            style="animation-delay: -1.4s"
        >
            <CircleCheck class="size-3" aria-hidden="true" />
            Verified bridge
        </div>

        <p class="absolute inset-x-0 -bottom-1 text-center text-[10px] font-semibold tracking-[0.22em] text-white/45 uppercase">
            One caller · one journey
        </p>
    </div>
</template>
