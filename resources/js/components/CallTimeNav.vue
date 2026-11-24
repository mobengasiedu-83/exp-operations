<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { agents, dentists, overview } from '@/routes/call-time';

const items = [
    { title: 'Overview', href: overview.url() },
    { title: 'By agent', href: agents.url() },
    { title: 'By dentist', href: dentists.url() },
];

const { isCurrentUrl } = useCurrentUrl();
</script>

<template>
    <nav class="flex flex-wrap gap-2" aria-label="Call time sections">
        <Link
            v-for="item in items"
            :key="item.title"
            :href="item.href"
            class="rounded-full border px-4 py-1.5 text-sm"
            :class="
                isCurrentUrl(item.href)
                    ? 'border-transparent bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900'
                    : 'hover:bg-slate-100 dark:hover:bg-slate-800'
            "
        >
            {{ item.title }}
        </Link>
    </nav>
</template>
