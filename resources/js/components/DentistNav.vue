<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import { useCurrentUrl } from "@/composables/useCurrentUrl";
import {
    availability,
    credentials,
    index,
    numbers,
    performance,
    rosters,
} from "@/routes/dentists";

const items = [
    { title: "Directory", href: index.url() },
    { title: "Numbers", href: numbers.url() },
    { title: "Credentials", href: credentials.url() },
    { title: "Rosters", href: rosters.url() },
    { title: "Availability", href: availability.url() },
    { title: "Performance", href: performance.url() },
];

const { isCurrentUrl } = useCurrentUrl();
</script>

<template>
    <nav class="flex flex-wrap gap-2" aria-label="Dentist directory sections">
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
