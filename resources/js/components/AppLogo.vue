<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { AudioLines } from '@lucide/vue';

const name = usePage().props.name;
</script>

<template>
    <div
        class="flex aspect-square size-8 items-center justify-center rounded-xl bg-teal-700 text-white shadow-md shadow-teal-900/20"
    >
        <AudioLines class="size-5" :stroke-width="1.6" />
    </div>
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="mb-0.5 truncate leading-tight font-semibold">{{
            name
        }}</span>
    </div>
</template>
