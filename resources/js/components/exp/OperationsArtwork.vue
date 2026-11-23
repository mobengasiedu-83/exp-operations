<script setup lang="ts">
import { Headphones, HeartPulse, ShieldCheck, Stethoscope } from '@lucide/vue';
</script>

<template>
    <div class="operations-art" aria-hidden="true">
        <div class="operations-orbit operations-orbit-outer" />
        <div class="operations-orbit operations-orbit-inner" />
        <svg class="operations-connections" viewBox="0 0 360 280" fill="none">
            <path d="M70 65 Q180 30 180 140 T300 220 M300 60 Q180 20 180 140 T60 220" stroke="currentColor" stroke-width="1" stroke-dasharray="4 8" />
            <circle cx="180" cy="140" r="110" stroke="currentColor" stroke-opacity=".2" />
        </svg>
        <div class="operations-core">
            <Headphones :size="48" :stroke-width="1.3" />
            <div class="operations-wave"><i /><i /><i /><i /><i /><i /><i /></div>
        </div>
        <div class="operations-satellite operations-satellite-one"><Stethoscope :size="25" :stroke-width="1.5" /></div>
        <div class="operations-satellite operations-satellite-two"><HeartPulse :size="25" :stroke-width="1.5" /></div>
        <div class="operations-satellite operations-satellite-three"><ShieldCheck :size="22" :stroke-width="1.5" /></div>
        <span class="operations-caption">A little care. A lasting impact.</span>
    </div>
</template>
