<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Headphones, LayoutDashboard, Stethoscope } from '@lucide/vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { windowQuery } from '@/lib/call-time';
import { agents, dentists, overview } from '@/routes/call-time';
import type { CallTimeFilters } from '@/types/call-time';

/**
 * The three Call time screens share one window, so the section links carry the
 * current query. Switching from a November overview to "By dentist" keeps
 * November instead of silently resetting to the default rolling window.
 */
const page = usePage<{ filters?: CallTimeFilters }>();

const query = computed<Record<string, string>>(() => {
    const filters = page.props.filters;

    return filters ? windowQuery(filters) : {};
});

const items = computed(() => [
    {
        title: 'Overview',
        href: overview.url({ query: query.value }),
        path: overview.definition.url,
        icon: LayoutDashboard,
    },
    {
        title: 'By agent',
        href: agents.url({ query: query.value }),
        path: agents.definition.url,
        icon: Headphones,
    },
    {
        title: 'By dentist',
        href: dentists.url({ query: query.value }),
        path: dentists.definition.url,
        icon: Stethoscope,
    },
]);

const { isCurrentUrl } = useCurrentUrl();
</script>

<template>
    <nav
        class="exp-rise exp-rise-1 inline-flex flex-wrap items-center gap-1 rounded-2xl border bg-card/80 p-1.5 shadow-sm backdrop-blur"
        aria-label="Call time sections"
    >
        <Link
            v-for="item in items"
            :key="item.title"
            :href="item.href"
            prefetch
            class="group inline-flex items-center gap-2 rounded-xl px-3.5 py-2 text-sm font-semibold transition duration-200"
            :class="
                isCurrentUrl(item.path)
                    ? 'bg-emerald-600 text-white shadow-sm shadow-emerald-900/25'
                    : 'text-muted-foreground hover:-translate-y-px hover:bg-emerald-500/10 hover:text-emerald-700 dark:hover:text-emerald-300'
            "
            :aria-current="isCurrentUrl(item.path) ? 'page' : undefined"
        >
            <component
                :is="item.icon"
                class="size-4 transition-transform duration-200 group-hover:scale-110"
                aria-hidden="true"
            />
            {{ item.title }}
        </Link>
    </nav>
</template>
