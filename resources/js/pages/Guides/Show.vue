<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import type { Component } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    ArrowUp,
    BookOpen,
    ChevronDown,
    ChevronRight,
    Clock,
    Eye,
    Headphones,
    ListTree,
    Printer,
    ShieldCheck,
} from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { index, show } from '@/routes/guides';

defineOptions({ layout: AppLayout });

type Guide = {
    key: string;
    title: string;
    short_title: string;
    audience: string;
    summary: string;
    permission: string;
    icon: string;
    accent: string;
    href: string;
    available: boolean;
    sections: number;
    reading_minutes: number;
    updated_at: string | null;
};

type Heading = { title: string; id: string };

type Fact = { label: string; value: string };

type DocumentBody = {
    title: string;
    html: string;
    headings: Heading[];
    facts: Fact[];
    sections: number;
};

type Accent = {
    badge: string;
    tile: string;
    glow: string;
    bar: string;
    css: string;
};

const props = defineProps<{
    guide: Guide;
    document: DocumentBody;
    others: Guide[];
}>();

const updatedLabel = computed(() => {
    if (!props.guide.updated_at) {
        return null;
    }

    return new Date(props.guide.updated_at).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
});

/**
 * Mirrors the accents used on the guides index so each manual keeps its colour.
 * `css` feeds `--guide-accent`, which the global `.guide-prose` stylesheet uses
 * for links, callouts and section markers.
 */
const accents: Record<string, Accent> = {
    emerald: {
        badge: 'bg-emerald-600/10 text-emerald-800 ring-1 ring-emerald-600/20 dark:bg-emerald-500/15 dark:text-emerald-200 dark:ring-emerald-400/25',
        tile: 'bg-emerald-600 text-white shadow-lg shadow-emerald-900/20',
        glow: 'from-emerald-500/15 via-emerald-500/5 to-transparent',
        bar: 'bg-emerald-600 dark:bg-emerald-400',
        css: 'var(--color-emerald-600)',
    },
    sky: {
        badge: 'bg-sky-600/10 text-sky-800 ring-1 ring-sky-600/20 dark:bg-sky-500/15 dark:text-sky-200 dark:ring-sky-400/25',
        tile: 'bg-sky-600 text-white shadow-lg shadow-sky-900/20',
        glow: 'from-sky-500/15 via-sky-500/5 to-transparent',
        bar: 'bg-sky-600 dark:bg-sky-400',
        css: 'var(--color-sky-600)',
    },
    violet: {
        badge: 'bg-violet-600/10 text-violet-800 ring-1 ring-violet-600/20 dark:bg-violet-500/15 dark:text-violet-200 dark:ring-violet-400/25',
        tile: 'bg-violet-600 text-white shadow-lg shadow-violet-900/20',
        glow: 'from-violet-500/15 via-violet-500/5 to-transparent',
        bar: 'bg-violet-600 dark:bg-violet-400',
        css: 'var(--color-violet-600)',
    },
};

const icons: Record<string, Component> = {
    headphones: Headphones,
    eye: Eye,
    'shield-check': ShieldCheck,
};

const accent = computed<Accent>(
    () => accents[props.guide.accent] ?? (accents.emerald as Accent),
);

const guideIcon = computed<Component>(() => icons[props.guide.icon] ?? BookOpen);

const headings = computed<Heading[]>(() => props.document.headings);

const activeId = ref<string>(headings.value[0]?.id ?? '');
const progress = ref(0);
const showBackToTop = ref(false);
const article = ref<HTMLElement | null>(null);

let frame = 0;

/** Track the current section and reading progress while the page scrolls. */
function measure(): void {
    frame = 0;

    if (!article.value) {
        return;
    }

    const top = article.value.getBoundingClientRect().top + window.scrollY;
    const scrollable = Math.max(1, article.value.offsetHeight - window.innerHeight * 0.6);
    const travelled = window.scrollY - top + window.innerHeight * 0.25;

    progress.value = Math.min(100, Math.max(0, Math.round((travelled / scrollable) * 100)));
    showBackToTop.value = window.scrollY > 640;

    let current = headings.value[0]?.id ?? '';

    for (const heading of headings.value) {
        const target = window.document.getElementById(heading.id);

        if (!target) {
            continue;
        }

        if (target.getBoundingClientRect().top <= 120) {
            current = heading.id;
        } else {
            break;
        }
    }

    activeId.value = current;
}

function onScroll(): void {
    if (frame !== 0) {
        return;
    }

    frame = window.requestAnimationFrame(measure);
}

function scrollToTop(): void {
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
}

function printManual(): void {
    window.print();
}

onMounted(() => {
    measure();
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll);
});

onBeforeUnmount(() => {
    if (frame !== 0) {
        window.cancelAnimationFrame(frame);
    }

    window.removeEventListener('scroll', onScroll);
    window.removeEventListener('resize', onScroll);
});
</script>

<template>
    <Head :title="document.title" />

    <div class="p-6">
        <div class="mx-auto max-w-7xl">
            <nav
                class="flex items-center gap-1.5 text-sm text-muted-foreground"
                aria-label="Breadcrumb"
            >
                <Link
                    :href="index()"
                    class="inline-flex items-center gap-1.5 font-medium transition hover:text-foreground"
                >
                    <ArrowLeft class="size-3.5" aria-hidden="true" />
                    All guides
                </Link>
                <ChevronRight class="size-3.5 opacity-50" aria-hidden="true" />
                <span class="truncate">{{ guide.audience }}</span>
            </nav>

            <div class="mt-4 flex flex-col gap-8 lg:flex-row">
                <div class="min-w-0 flex-1">
                    <header
                        class="exp-rise relative overflow-hidden rounded-2xl border bg-card"
                    >
                        <div
                            class="pointer-events-none absolute inset-0 bg-gradient-to-br"
                            :class="accent.glow"
                            aria-hidden="true"
                        />
                        <div class="relative p-6 sm:p-8">
                            <div class="flex flex-wrap items-start gap-4">
                                <span
                                    class="flex size-12 shrink-0 items-center justify-center rounded-xl"
                                    :class="accent.tile"
                                >
                                    <component
                                        :is="guideIcon"
                                        class="size-6"
                                        aria-hidden="true"
                                    />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="flex flex-wrap items-center gap-x-3 gap-y-1.5"
                                    >
                                        <span
                                            class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                            :class="accent.badge"
                                        >
                                            {{ guide.audience }} edition
                                        </span>
                                        <span
                                            class="inline-flex items-center gap-1 text-xs text-muted-foreground"
                                        >
                                            <Clock
                                                class="size-3.5"
                                                aria-hidden="true"
                                            />
                                            {{ guide.reading_minutes }} min read
                                        </span>
                                        <span
                                            class="inline-flex items-center gap-1 text-xs text-muted-foreground"
                                        >
                                            <ListTree
                                                class="size-3.5"
                                                aria-hidden="true"
                                            />
                                            {{ document.sections }} sections
                                        </span>
                                    </div>
                                    <h1
                                        class="mt-3 text-2xl font-semibold tracking-tight text-balance sm:text-3xl"
                                    >
                                        {{ document.title }}
                                    </h1>
                                    <p
                                        class="mt-2 max-w-3xl text-sm leading-relaxed text-muted-foreground"
                                    >
                                        {{ guide.summary }}
                                    </p>
                                </div>
                            </div>

                            <dl
                                v-if="document.facts.length"
                                class="mt-6 grid gap-px overflow-hidden rounded-xl border border-border bg-border sm:grid-cols-2"
                            >
                                <div
                                    v-for="fact in document.facts"
                                    :key="fact.label"
                                    class="bg-card px-4 py-3"
                                >
                                    <dt
                                        class="text-[11px] font-semibold tracking-[0.08em] text-muted-foreground uppercase"
                                    >
                                        {{ fact.label }}
                                    </dt>
                                    <dd
                                        class="mt-1 text-sm leading-relaxed"
                                        v-html="fact.value"
                                    />
                                </div>
                            </dl>

                            <div
                                class="mt-5 flex flex-wrap items-center justify-between gap-3"
                            >
                                <p class="text-xs text-muted-foreground">
                                    <template v-if="updatedLabel">
                                        Last updated {{ updatedLabel }}
                                    </template>
                                    <template v-else>
                                        Installed with this build
                                    </template>
                                </p>
                                <button
                                    type="button"
                                    class="guide-print-hide inline-flex items-center gap-1.5 rounded-md border bg-background px-3 py-1.5 text-xs font-medium transition hover:bg-muted"
                                    @click="printManual"
                                >
                                    <Printer
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    Print or save as PDF
                                </button>
                            </div>
                        </div>
                    </header>

                    <details class="mt-6 rounded-xl border bg-card lg:hidden">
                        <summary
                            class="flex cursor-pointer list-none items-center justify-between gap-2 px-4 py-3 text-sm font-semibold [&::-webkit-details-marker]:hidden"
                        >
                            <span class="inline-flex items-center gap-2">
                                <ListTree
                                    class="size-4 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                On this page
                            </span>
                            <ChevronDown
                                class="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                        </summary>
                        <nav class="border-t p-2">
                            <a
                                v-for="(heading, position) in headings"
                                :key="heading.id"
                                :href="`#${heading.id}`"
                                class="flex items-start gap-2 rounded-md px-2 py-1.5 text-sm text-muted-foreground transition hover:bg-muted hover:text-foreground"
                            >
                                <span
                                    class="w-5 shrink-0 text-right text-xs tabular-nums opacity-60"
                                >
                                    {{ position + 1 }}
                                </span>
                                {{ heading.title }}
                            </a>
                        </nav>
                    </details>

                    <!-- CommonMark escapes raw HTML, so this is the rendered
                         manual and nothing a caller could inject. -->
                    <article class="mt-6 rounded-2xl border bg-card p-6 sm:p-10">
                        <div
                            ref="article"
                            class="guide-prose"
                            :style="`--guide-accent: ${accent.css}`"
                            v-html="document.html"
                        />
                    </article>

                    <footer
                        class="mt-6 flex flex-col gap-4 rounded-2xl border bg-card p-5 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div>
                            <p class="text-sm font-medium">
                                That is the end of the manual.
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                If a screen here does not match the console, tell
                                your supervisor and ask for the manual to be
                                corrected.
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <Link
                                :href="index()"
                                class="inline-flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm font-medium transition hover:bg-muted"
                            >
                                <ArrowLeft class="size-3.5" aria-hidden="true" />
                                All guides
                            </Link>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm font-medium transition hover:bg-muted"
                                @click="printManual"
                            >
                                <Printer class="size-3.5" aria-hidden="true" />
                                Print
                            </button>
                        </div>
                    </footer>

                    <nav
                        v-if="others.length"
                        class="mt-6 grid gap-3 sm:grid-cols-2"
                        aria-label="Other manuals"
                    >
                        <Link
                            v-for="other in others"
                            :key="other.key"
                            :href="show(other.key)"
                            class="group flex items-start gap-3 rounded-xl border bg-card p-4 transition hover:border-foreground/20 hover:shadow-sm"
                        >
                            <span
                                class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground"
                            >
                                <BookOpen class="size-4" aria-hidden="true" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                                >
                                    {{ other.audience }}
                                </span>
                                <span class="mt-0.5 block text-sm font-medium">
                                    {{ other.title }}
                                </span>
                                <span
                                    class="mt-1 block text-xs text-muted-foreground"
                                >
                                    {{ other.reading_minutes }} min read
                                </span>
                            </span>
                            <ArrowRight
                                class="size-4 shrink-0 self-center text-muted-foreground transition group-hover:translate-x-0.5"
                                aria-hidden="true"
                            />
                        </Link>
                    </nav>
                </div>

                <aside class="guide-print-hide w-full shrink-0 lg:w-72">
                    <div class="space-y-4 lg:sticky lg:top-6">
                        <nav
                            v-if="headings.length"
                            class="overflow-hidden rounded-xl border bg-card"
                            aria-label="Sections"
                        >
                            <div class="border-b px-4 py-3">
                                <div
                                    class="flex items-center justify-between gap-2"
                                >
                                    <p
                                        class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                                    >
                                        On this page
                                    </p>
                                    <span
                                        class="text-[11px] tabular-nums text-muted-foreground"
                                    >
                                        {{ progress }}%
                                    </span>
                                </div>
                                <div
                                    class="mt-2 h-1 overflow-hidden rounded-full bg-muted"
                                >
                                    <div
                                        class="h-full rounded-full transition-[width] duration-150"
                                        :class="accent.bar"
                                        :style="{ width: `${progress}%` }"
                                    />
                                </div>
                            </div>
                            <div
                                class="max-h-[calc(100vh-14rem)] overflow-y-auto p-2"
                            >
                                <a
                                    v-for="heading in headings"
                                    :key="heading.id"
                                    :href="`#${heading.id}`"
                                    class="block rounded-md border-l-2 px-2.5 py-1.5 text-xs leading-snug transition"
                                    :class="
                                        activeId === heading.id
                                            ? 'border-current bg-muted font-medium text-foreground'
                                            : 'border-transparent text-muted-foreground hover:bg-muted/60 hover:text-foreground'
                                    "
                                    :aria-current="
                                        activeId === heading.id
                                            ? 'true'
                                            : undefined
                                    "
                                >
                                    {{ heading.title }}
                                </a>
                            </div>
                        </nav>

                        <div class="rounded-xl border bg-card p-4">
                            <p
                                class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                Your access
                            </p>
                            <p
                                class="mt-2 text-xs leading-relaxed text-muted-foreground"
                            >
                                You can read this manual because your role holds
                                <code class="rounded bg-muted px-1 py-0.5 text-[11px]">
                                    {{ guide.permission }}
                                </code>
                                . The server checks that permission on every
                                request, not only when the menu is built.
                            </p>
                        </div>

                        <div
                            v-if="others.length"
                            class="rounded-xl border bg-card p-2"
                        >
                            <p
                                class="px-2 pt-1 pb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                Other manuals
                            </p>
                            <Link
                                v-for="other in others"
                                :key="other.key"
                                :href="show(other.key)"
                                class="flex items-center gap-2 rounded-md px-2 py-2 text-xs transition hover:bg-muted"
                            >
                                <BookOpen
                                    class="size-3.5 shrink-0 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-medium">
                                        {{ other.short_title }}
                                    </span>
                                    <span
                                        class="block text-[11px] text-muted-foreground"
                                    >
                                        {{ other.reading_minutes }} min ·
                                        {{ other.audience }}
                                    </span>
                                </span>
                                <ArrowRight
                                    class="size-3.5 shrink-0 text-muted-foreground"
                                    aria-hidden="true"
                                />
                            </Link>
                        </div>
                    </div>
                </aside>
            </div>
        </div>

        <button
            v-if="showBackToTop"
            type="button"
            class="guide-print-hide fixed right-6 bottom-6 z-40 inline-flex size-10 items-center justify-center rounded-full border bg-card text-muted-foreground shadow-lg transition hover:text-foreground"
            aria-label="Back to top"
            @click="scrollToTop"
        >
            <ArrowUp class="size-4" aria-hidden="true" />
        </button>
    </div>
</template>
