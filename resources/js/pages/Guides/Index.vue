<script setup lang="ts">
import { computed } from 'vue';
import type { Component } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowUpRight,
    BookOpen,
    Clock,
    Eye,
    Headphones,
    ListTree,
    ShieldCheck,
} from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { show } from '@/routes/guides';

defineOptions({ layout: AppLayout });

type Guide = {
    key: string;
    title: string;
    short_title: string;
    audience: string;
    summary: string;
    icon: string;
    accent: string;
    href: string;
    available: boolean;
    sections: number;
    reading_minutes: number;
    updated_at: string | null;
};

type Accent = {
    card: string;
    bar: string;
    badge: string;
    tile: string;
    link: string;
};

const props = defineProps<{ guides: Guide[] }>();

const accents: Record<string, Accent> = {
    emerald: {
        card: 'border-emerald-200/70 dark:border-emerald-500/25',
        bar: 'bg-emerald-600 dark:bg-emerald-400',
        badge: 'bg-emerald-600/10 text-emerald-800 ring-1 ring-emerald-600/20 dark:bg-emerald-500/15 dark:text-emerald-200 dark:ring-emerald-400/25',
        tile: 'bg-emerald-600 text-white shadow-sm shadow-emerald-900/20',
        link: 'text-emerald-700 hover:text-emerald-800 dark:text-emerald-300 dark:hover:text-emerald-200',
    },
    sky: {
        card: 'border-sky-200/70 dark:border-sky-500/25',
        bar: 'bg-sky-600 dark:bg-sky-400',
        badge: 'bg-sky-600/10 text-sky-800 ring-1 ring-sky-600/20 dark:bg-sky-500/15 dark:text-sky-200 dark:ring-sky-400/25',
        tile: 'bg-sky-600 text-white shadow-sm shadow-sky-900/20',
        link: 'text-sky-700 hover:text-sky-800 dark:text-sky-300 dark:hover:text-sky-200',
    },
    violet: {
        card: 'border-violet-200/70 dark:border-violet-500/25',
        bar: 'bg-violet-600 dark:bg-violet-400',
        badge: 'bg-violet-600/10 text-violet-800 ring-1 ring-violet-600/20 dark:bg-violet-500/15 dark:text-violet-200 dark:ring-violet-400/25',
        tile: 'bg-violet-600 text-white shadow-sm shadow-violet-900/20',
        link: 'text-violet-700 hover:text-violet-800 dark:text-violet-300 dark:hover:text-violet-200',
    },
};

const icons: Record<string, Component> = {
    headphones: Headphones,
    eye: Eye,
    'shield-check': ShieldCheck,
};

const cards = computed(() =>
    props.guides.map((guide) => ({
        ...guide,
        theme: accents[guide.accent] ?? (accents.emerald as Accent),
        glyph: icons[guide.icon] ?? BookOpen,
    })),
);

function updatedLabel(value: string | null): string {
    if (!value) {
        return 'Not published on this environment';
    }

    return `Updated ${new Date(value).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    })}`;
}
</script>

<template>
    <Head title="Guides" />

    <div class="p-6">
        <div class="mx-auto max-w-7xl space-y-6">
            <header
                class="exp-rise relative overflow-hidden rounded-2xl border bg-card p-6 sm:p-8"
            >
                <div
                    class="pointer-events-none absolute inset-0 bg-gradient-to-br from-emerald-500/10 via-transparent to-transparent"
                    aria-hidden="true"
                />
                <div class="relative max-w-3xl">
                    <p
                        class="text-xs font-semibold tracking-[0.18em] text-emerald-700 uppercase dark:text-emerald-300"
                    >
                        Operating manuals
                    </p>
                    <h1 class="mt-2 text-2xl font-semibold tracking-tight sm:text-3xl">
                        Role guides
                    </h1>
                    <p class="mt-2 text-sm leading-relaxed text-muted-foreground">
                        One complete manual per role, written from the screens
                        and rules that actually ship. Each manual is gated by its
                        own permission, so a guide you cannot see is a guide you
                        cannot open by URL either.
                    </p>
                    <div
                        class="mt-4 flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
                    >
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full border bg-background px-2.5 py-1"
                        >
                            <BookOpen class="size-3.5" aria-hidden="true" />
                            {{ cards.length }} available to you
                        </span>
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full border bg-background px-2.5 py-1"
                        >
                            <ShieldCheck class="size-3.5" aria-hidden="true" />
                            Checked on every request
                        </span>
                    </div>
                </div>
            </header>

            <div
                v-if="cards.length"
                class="grid gap-4 md:grid-cols-2 xl:grid-cols-3"
            >
                <article
                    v-for="card in cards"
                    :key="card.key"
                    class="group relative flex flex-col overflow-hidden rounded-2xl border bg-card shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
                    :class="card.theme.card"
                >
                    <div
                        class="absolute inset-x-0 top-0 h-1"
                        :class="card.theme.bar"
                        aria-hidden="true"
                    />
                    <div class="flex flex-1 flex-col p-5">
                        <div class="flex items-start justify-between gap-3">
                            <span
                                class="flex size-10 items-center justify-center rounded-xl"
                                :class="card.theme.tile"
                            >
                                <component
                                    :is="card.glyph"
                                    class="size-5"
                                    aria-hidden="true"
                                />
                            </span>
                            <span
                                class="rounded-full px-2.5 py-0.5 text-[11px] font-semibold"
                                :class="card.theme.badge"
                            >
                                {{ card.audience }}
                            </span>
                        </div>

                        <h2 class="mt-4 text-base font-semibold text-balance">
                            {{ card.title }}
                        </h2>
                        <p
                            class="mt-2 flex-1 text-sm leading-relaxed text-muted-foreground"
                        >
                            {{ card.summary }}
                        </p>

                        <div
                            class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground"
                        >
                            <span class="inline-flex items-center gap-1">
                                <Clock class="size-3.5" aria-hidden="true" />
                                {{ card.reading_minutes }} min
                            </span>
                            <span class="inline-flex items-center gap-1">
                                <ListTree class="size-3.5" aria-hidden="true" />
                                {{ card.sections }} sections
                            </span>
                        </div>
                        <p class="mt-2 text-[11px] text-muted-foreground">
                            {{ updatedLabel(card.updated_at) }}
                        </p>

                        <Link
                            v-if="card.available"
                            :href="show(card.key)"
                            class="mt-4 inline-flex items-center gap-1.5 text-sm font-medium transition"
                            :class="card.theme.link"
                        >
                            Open manual
                            <ArrowUpRight
                                class="size-3.5 transition group-hover:translate-x-0.5"
                                aria-hidden="true"
                            />
                        </Link>
                        <p v-else class="mt-4 text-sm text-muted-foreground">
                            Not installed on this environment. Only the
                            capability is missing, not your access.
                        </p>
                    </div>
                </article>
            </div>

            <p
                v-else
                class="rounded-2xl border bg-card p-6 text-sm text-muted-foreground"
            >
                No guide is published for your role yet. An administrator grants
                manual access with the <code>view agent guides</code>,
                <code>view viewer guides</code> or
                <code>view super admin guides</code> permission on the role.
            </p>

            <section class="rounded-2xl border bg-card p-5">
                <h2 class="text-sm font-semibold">
                    Why you may not see every manual
                </h2>
                <p class="mt-2 text-sm leading-relaxed text-muted-foreground">
                    Manual access is a permission, not a menu preference. The
                    Agent manual is for the call-centre role, the Viewer manual
                    covers the read-only screens, and the Super Administrator
                    manual covers administration and integration health. A Super
                    Administrator holds all three; every other role sees the one
                    that matches its work.
                </p>
            </section>
        </div>
    </div>
</template>
