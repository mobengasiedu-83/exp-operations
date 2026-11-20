<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { index, show } from '@/routes/guides';

defineOptions({ layout: AppLayout });

type Guide = {
    key: string;
    title: string;
    short_title: string;
    audience: string;
    summary: string;
    accent: string;
    reading_minutes: number;
    updated_at: string | null;
};

type Document = {
    title: string;
    html: string;
    headings: { title: string; id: string }[];
};

const props = defineProps<{
    guide: Guide;
    document: Document;
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

/** Mirrors the accents used on the guides index so each manual keeps its colour. */
const audienceClasses: Record<string, string> = {
    emerald:
        'bg-emerald-600/10 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-200',
    sky: 'bg-sky-600/10 text-sky-800 dark:bg-sky-500/15 dark:text-sky-200',
    violet: 'bg-violet-600/10 text-violet-800 dark:bg-violet-500/15 dark:text-violet-200',
};

const audienceClass = computed(
    () => audienceClasses[props.guide.accent] ?? audienceClasses.emerald,
);
</script>

<template>
    <Head :title="document.title" />
    <div class="p-6">
        <div class="mx-auto flex max-w-7xl flex-col gap-6 lg:flex-row">
            <article class="min-w-0 flex-1">
                <Link
                    :href="index()"
                    class="inline-flex items-center gap-1.5 text-sm font-medium text-muted-foreground transition hover:text-foreground"
                >
                    <span aria-hidden="true">←</span>
                    All guides
                </Link>

                <header class="mt-3">
                    <span
                        class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                        :class="audienceClass"
                    >
                        {{ guide.audience }} edition
                    </span>
                    <h1 class="mt-3 text-2xl font-semibold tracking-tight">
                        {{ document.title }}
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ guide.summary }}
                    </p>
                    <p class="mt-2 text-xs text-muted-foreground">
                        <template v-if="updatedLabel">
                            {{ updatedLabel }} ·
                        </template>
                        {{ guide.reading_minutes }} min read
                    </p>
                </header>

                <!-- CommonMark escapes raw HTML, so this is the rendered
                     manual and nothing a caller could inject. -->
                <div
                    class="guide-body mt-6 rounded-xl border bg-card p-6"
                    v-html="document.html"
                />
            </article>

            <aside class="w-full shrink-0 lg:w-64">
                <div class="space-y-4 lg:sticky lg:top-6">
                    <nav
                        v-if="document.headings.length"
                        class="rounded-xl border p-3"
                        aria-label="Sections"
                    >
                        <p
                            class="px-2 pb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            On this page
                        </p>
                        <a
                            v-for="heading in document.headings"
                            :key="heading.id"
                            :href="`#${heading.id}`"
                            class="block rounded-md px-2 py-1.5 text-xs leading-snug text-muted-foreground transition hover:bg-muted hover:text-foreground"
                        >
                            {{ heading.title }}
                        </a>
                    </nav>

                    <div v-if="others.length" class="rounded-xl border p-3">
                        <p
                            class="px-2 pb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            Other guides
                        </p>
                        <Link
                            v-for="other in others"
                            :key="other.key"
                            :href="show(other.key)"
                            class="block rounded-md px-2 py-1.5 text-xs leading-snug text-muted-foreground transition hover:bg-muted hover:text-foreground"
                        >
                            {{ other.short_title }}
                            <span class="block text-[11px] opacity-70">
                                {{ other.audience }}
                            </span>
                        </Link>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</template>

<style scoped>
.guide-body {
    font-size: 0.9375rem;
    line-height: 1.7;
    color: var(--foreground);
}

.guide-body :deep(h2) {
    margin-top: 2.25rem;
    font-size: 1.25rem;
    font-weight: 600;
    letter-spacing: -0.01em;
    scroll-margin-top: 5rem;
}

.guide-body :deep(h2:first-child) {
    margin-top: 0;
}

.guide-body :deep(h3) {
    margin-top: 1.5rem;
    font-size: 1rem;
    font-weight: 600;
    scroll-margin-top: 5rem;
}

.guide-body :deep(h4) {
    margin-top: 1.25rem;
    font-size: 0.9375rem;
    font-weight: 600;
}

.guide-body :deep(p) {
    margin-top: 0.75rem;
}

.guide-body :deep(ul),
.guide-body :deep(ol) {
    margin-top: 0.75rem;
    padding-left: 1.35rem;
}

.guide-body :deep(ul) {
    list-style: disc;
}

.guide-body :deep(ol) {
    list-style: decimal;
}

.guide-body :deep(li) {
    margin-top: 0.3rem;
}

.guide-body :deep(li > ul),
.guide-body :deep(li > ol) {
    margin-top: 0.3rem;
}

.guide-body :deep(a) {
    color: var(--color-emerald-700);
    text-decoration: underline;
    text-underline-offset: 2px;
}

.guide-body :deep(strong) {
    font-weight: 600;
}

.guide-body :deep(code) {
    border-radius: 0.3rem;
    background: var(--muted);
    padding: 0.1rem 0.3rem;
    font-size: 0.85em;
}

.guide-body :deep(pre) {
    margin-top: 1rem;
    overflow-x: auto;
    border-radius: 0.75rem;
    background: #0f172a;
    padding: 1rem;
    color: #e2e8f0;
}

.guide-body :deep(pre code) {
    background: transparent;
    padding: 0;
    color: inherit;
}

.guide-body :deep(blockquote) {
    margin-top: 1rem;
    border-left: 3px solid var(--color-emerald-500);
    padding-left: 0.9rem;
    color: var(--muted-foreground);
}

.guide-body :deep(hr) {
    margin: 2rem 0;
    border-color: var(--border);
}

.guide-body :deep(.guide-table-scroll) {
    margin-top: 1rem;
    overflow-x: auto;
}

.guide-body :deep(table) {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
}

.guide-body :deep(th),
.guide-body :deep(td) {
    border: 1px solid var(--border);
    padding: 0.5rem 0.65rem;
    text-align: left;
    vertical-align: top;
}

.guide-body :deep(th) {
    background: var(--muted);
    font-weight: 600;
}
</style>
