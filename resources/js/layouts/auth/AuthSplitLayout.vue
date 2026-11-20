<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="relative grid h-dvh flex-col items-center justify-center px-8 sm:px-0 lg:max-w-none lg:grid-cols-2 lg:px-0"
    >
        <div class="relative hidden h-full flex-col overflow-hidden bg-[#073d2d] p-10 text-white lg:flex">
            <div class="absolute -right-32 -top-24 size-[32rem] rounded-full border border-white/10" />
            <div class="absolute -right-12 top-10 size-[25rem] rounded-full border border-white/10" />
            <div class="absolute -bottom-40 -left-24 size-[34rem] rounded-full bg-emerald-400/10 blur-3xl" />
            <Link
                :href="home()"
                class="relative z-20 flex items-center gap-3 text-lg font-semibold"
            >
                <span class="grid size-11 place-items-center rounded-2xl bg-white text-sm font-black text-emerald-900">EXP</span>
                <span>Operations Console</span>
            </Link>
            <div class="relative z-10 mt-auto max-w-xl pb-14">
                <p class="mb-5 text-xs font-semibold uppercase tracking-[0.2em] text-emerald-200">Pepsodent · Talk to a Dentist</p>
                <h2 class="text-5xl font-semibold leading-tight tracking-tight">Good care starts with a clear view.</h2>
                <p class="mt-6 max-w-md text-base leading-7 text-emerald-50/75">Sign in to follow call activity, coordinate referrals and support every step of the caller journey.</p>
                <div class="mt-10 flex items-center gap-3 rounded-2xl border border-white/10 bg-white/5 p-4 backdrop-blur"><span class="grid size-10 place-items-center rounded-xl bg-emerald-300/15 text-emerald-200">✓</span><span><strong class="block text-sm">Protected operations workspace</strong><span class="mt-1 block text-xs text-emerald-100/60">For authorised team members only</span></span></div>
            </div>
            <p class="relative z-10 mt-5 text-xs text-emerald-100/45">EXP Operations Console</p>
        </div>
        <div class="flex min-h-dvh items-center px-6 py-10 lg:min-h-0 lg:px-8 lg:py-8">
            <div
                class="mx-auto flex w-full flex-col justify-center space-y-6 sm:w-[350px]"
            >
                <Link :href="home()" class="mb-3 flex items-center gap-2 text-sm font-semibold text-emerald-800 lg:hidden"><span class="grid size-9 place-items-center rounded-xl bg-emerald-700 text-xs font-black text-white">EXP</span>Operations Console</Link>
                <div class="flex flex-col space-y-2 text-center">
                    <h1 class="text-xl font-medium tracking-tight" v-if="title">
                        {{ title }}
                    </h1>
                    <p class="text-sm text-muted-foreground" v-if="description">
                        {{ description }}
                    </p>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
