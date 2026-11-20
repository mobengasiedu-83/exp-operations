<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { login } from '@/routes'

const modules = [
    { number: '01', title: 'Live operations', detail: 'See call activity and PBX health as events arrive.' },
    { number: '02', title: 'Connected care', detail: 'Follow screening, dentist transfers and consultation journeys.' },
    { number: '03', title: 'Operational insight', detail: 'Build a reliable record for service reporting and follow-up.' },
]
</script>

<template>
    <Head title="EXP Operations Console">
        <meta name="description" content="A secure operations console for Pepsodent Talk to a Dentist." />
    </Head>
    <main class="min-h-screen overflow-hidden bg-[#f5f7f3] text-slate-950">
        <nav class="mx-auto flex max-w-7xl items-center justify-between px-6 py-6 lg:px-10">
            <Link href="/" class="flex items-center gap-3" aria-label="EXP Operations home">
                <span class="grid size-11 place-items-center rounded-2xl bg-emerald-700 text-sm font-black tracking-tight text-white">EXP</span>
                <span><strong class="block text-sm font-bold">Operations Console</strong><span class="text-xs text-slate-500">Pepsodent · Talk to a Dentist</span></span>
            </Link>
            <Link :href="login()" class="rounded-full bg-slate-950 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-800">Staff sign in <span aria-hidden="true">↗</span></Link>
        </nav>

        <section class="relative mx-auto grid max-w-7xl gap-12 px-6 pb-16 pt-10 lg:grid-cols-[1.1fr_0.9fr] lg:items-center lg:px-10 lg:pb-24 lg:pt-16">
            <div class="relative z-10">
                <div class="mb-7 inline-flex items-center gap-2 rounded-full border border-emerald-900/10 bg-white/80 px-4 py-2 text-xs font-semibold uppercase tracking-[0.16em] text-emerald-800 shadow-sm"><span class="size-2 rounded-full bg-emerald-500"></span> Care operations, in view</div>
                <h1 class="max-w-3xl text-5xl font-semibold leading-[1.04] tracking-tight sm:text-6xl lg:text-7xl">Every call.<br /><span class="text-emerald-700">A clearer journey.</span></h1>
                <p class="mt-7 max-w-xl text-lg leading-8 text-slate-600">EXP brings the moving parts of Talk to a Dentist into one calm, practical workspace—from the first call through screening, referral and follow-up.</p>
                <div class="mt-9 flex flex-wrap items-center gap-4">
                    <Link :href="login()" class="rounded-full bg-emerald-700 px-7 py-4 text-sm font-semibold text-white shadow-lg shadow-emerald-900/15 transition hover:-translate-y-0.5 hover:bg-emerald-800">Open operations console <span class="ml-2" aria-hidden="true">→</span></Link>
                    <span class="text-sm text-slate-500">For authorised operations staff</span>
                </div>
                <div class="mt-12 flex items-center gap-3 text-sm text-slate-500"><span class="flex -space-x-2"><i class="size-8 rounded-full border-2 border-[#f5f7f3] bg-emerald-200"></i><i class="size-8 rounded-full border-2 border-[#f5f7f3] bg-amber-200"></i><i class="size-8 rounded-full border-2 border-[#f5f7f3] bg-slate-300"></i></span><span>One connected view for the care team</span></div>
            </div>

            <div class="relative mx-auto w-full max-w-xl lg:justify-self-end">
                <div class="absolute -right-12 -top-12 size-56 rounded-full bg-lime-200/70 blur-3xl"></div>
                <div class="absolute -bottom-10 -left-10 size-52 rounded-full bg-emerald-200/70 blur-3xl"></div>
                <div class="relative rounded-[2rem] border border-white/80 bg-white/75 p-4 shadow-[0_35px_100px_-40px_rgba(15,70,48,0.35)] backdrop-blur-xl sm:p-6">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-5"><div><p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">Operations overview</p><p class="mt-1 text-lg font-semibold">Today at a glance</p></div><span class="rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700">● Systems ready</span></div>
                    <div class="grid grid-cols-2 gap-3 py-5 sm:gap-4">
                        <div class="rounded-2xl bg-[#f5f7f3] p-4"><p class="text-xs text-slate-500">Call activity</p><p class="mt-2 text-xl font-semibold">Live view</p><div class="mt-4 flex h-12 items-end gap-1.5"><i v-for="(height, index) in [35, 55, 43, 76, 48, 90, 62, 100, 69, 83, 54, 72]" :key="index" class="flex-1 rounded-t bg-emerald-500/80" :style="{ height: `${height}%` }"></i></div></div>
                        <div class="rounded-2xl bg-slate-950 p-4 text-white"><p class="text-xs text-white/55">PBX connection</p><p class="mt-2 text-xl font-semibold">Monitored</p><div class="mt-5 flex items-center gap-2 text-xs text-emerald-300"><span class="size-2 rounded-full bg-emerald-400"></span> Read-only event stream</div></div>
                    </div>
                    <div class="space-y-3">
                        <div v-for="(item, index) in [{ caller: 'Caller journey', stage: 'Screening', color: 'bg-amber-400' }, { caller: 'Dentist referral', stage: 'Connected', color: 'bg-emerald-500' }, { caller: 'Follow-up', stage: 'Scheduled', color: 'bg-sky-500' }]" :key="item.caller" class="flex items-center gap-3 rounded-2xl border border-slate-100 bg-white p-3.5"><span class="grid size-10 shrink-0 place-items-center rounded-xl bg-slate-50 text-sm font-bold text-slate-600">0{{ index + 1 }}</span><div class="min-w-0 flex-1"><p class="text-sm font-semibold">{{ item.caller }}</p><p class="mt-0.5 text-xs text-slate-500">EXP operations</p></div><span class="flex items-center gap-2 rounded-full bg-slate-50 px-3 py-1.5 text-xs font-medium text-slate-600"><i class="size-1.5 rounded-full" :class="item.color"></i>{{ item.stage }}</span></div>
                    </div>
                    <p class="mt-5 text-center text-[11px] text-slate-400">Illustrative interface · operational data appears after sign in</p>
                </div>
            </div>
        </section>

        <section class="border-y border-slate-200/70 bg-white/70">
            <div class="mx-auto grid max-w-7xl gap-8 px-6 py-10 sm:grid-cols-3 lg:px-10">
                <article v-for="item in modules" :key="item.number" class="flex gap-4"><span class="text-xs font-bold tracking-widest text-emerald-700">{{ item.number }}</span><div><h2 class="font-semibold">{{ item.title }}</h2><p class="mt-1 text-sm leading-6 text-slate-500">{{ item.detail }}</p></div></article>
            </div>
        </section>
        <footer class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-6 py-7 text-xs text-slate-500 lg:px-10"><span>EXP Operations Console</span><span>Secure workspace · Authorised access only</span></footer>
    </main>
</template>
