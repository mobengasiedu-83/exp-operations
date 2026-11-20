<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
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
    reading_minutes: number;
    updated_at: string | null;
};

defineProps<{ guides: Guide[] }>();

const accents: Record<string, string> = {
    emerald:
        'border-emerald-200 bg-emerald-50/60 dark:border-emerald-500/30 dark:bg-emerald-500/10',
    sky: 'border-sky-200 bg-sky-50/60 dark:border-sky-500/30 dark:bg-sky-500/10',
    violet: 'border-violet-200 bg-violet-50/60 dark:border-violet-500/30 dark:bg-violet-500/10',
};

const badges: Record<string, string> = {
    emerald:
        'bg-emerald-600/10 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-200',
    sky: 'bg-sky-600/10 text-sky-800 dark:bg-sky-500/15 dark:text-sky-200',
    violet: 'bg-violet-600/10 text-violet-800 dark:bg-violet-500/15 dark:text-violet-200',
};

function cardClasses(accent: string): string {
    return accents[accent] ?? accents.emerald ?? '';
}

function badgeClasses(accent: string): string {
    return badges[accent] ?? badges.emerald ?? '';
}

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
    <div class="space-y-6 p-6">
        <Heading
            title="Role guides"
            description="The complete manual for your role. Visibility is enforced on the server, so a guide you cannot see is a guide you cannot open by URL either."
        />

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <article
                v-for="guide in guides"
                :key="guide.key"
                class="flex flex-col rounded-xl border p-5"
                :class="cardClasses(guide.accent)"
            >
                <div class="flex items-center justify-between gap-3">
                    <span
                        class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                        :class="badgeClasses(guide.accent)"
                    >
                        {{ guide.audience }}
                    </span>
                    <span class="text-xs text-muted-foreground">
                        {{ guide.reading_minutes }} min read
                    </span>
                </div>

                <h2 class="mt-3 text-base font-semibold">{{ guide.title }}</h2>
                <p class="mt-2 flex-1 text-sm text-muted-foreground">
                    {{ guide.summary }}
                </p>

                <p class="mt-3 text-xs text-muted-foreground">
                    {{ updatedLabel(guide.updated_at) }}
                </p>

                <Link
                    v-if="guide.available"
                    :href="show(guide.key)"
                    class="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-emerald-700 hover:underline dark:text-emerald-300"
                >
                    Open guide
                    <span aria-hidden="true">→</span>
                </Link>
                <p v-else class="mt-4 text-sm text-muted-foreground">
                    This guide is not installed on this environment.
                </p>
            </article>
        </div>

        <p
            v-if="guides.length === 0"
            class="rounded-xl border p-6 text-sm text-muted-foreground"
        >
            No guide is published for your role yet. An administrator grants
            manual access with the <code>view agent guides</code>,
            <code>view viewer guides</code> or
            <code>view super admin guides</code> permission on the role.
        </p>
    </div>
</template>
