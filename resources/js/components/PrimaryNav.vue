<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { Component } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
    Activity,
    BookOpen,
    ChartColumn,
    ChevronDown,
    Eye,
    Headphones,
    Inbox,
    LayoutDashboard,
    List,
    Phone,
    Route as RouteIcon,
    Settings,
    ShieldCheck,
    Stethoscope,
    Timer,
    Users,
} from '@lucide/vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';

type NavEntry = { key: string; title: string; href: string; icon?: string };
type PrimaryMenu = NavEntry & { children: NavEntry[] };

const icons: Record<string, Component> = {
    'layout-dashboard': LayoutDashboard,
    activity: Activity,
    headphones: Headphones,
    phone: Phone,
    inbox: Inbox,
    route: RouteIcon,
    list: List,
    timer: Timer,
    users: Users,
    'chart-column': ChartColumn,
    stethoscope: Stethoscope,
    settings: Settings,
    'book-open': BookOpen,
    eye: Eye,
    'shield-check': ShieldCheck,
};

const page = usePage();
const { isCurrentOrParentUrl } = useCurrentUrl();
const items = computed<PrimaryMenu[]>(
    () => (page.props.navigation ?? []) as PrimaryMenu[],
);
const expanded = ref<Record<string, boolean>>({});

watch(
    items,
    (menus) => {
        for (const menu of menus) {
            const within =
                isCurrentOrParentUrl(menu.href) ||
                menu.children.some((child) => isCurrentOrParentUrl(child.href));

            if (within) {
                expanded.value[menu.key] = true;
            }
        }
    },
    { immediate: true },
);

function active(menu: PrimaryMenu): boolean {
    return (
        isCurrentOrParentUrl(menu.href) ||
        menu.children.some((child) => isCurrentOrParentUrl(child.href))
    );
}

function toggle(key: string): void {
    expanded.value[key] = !expanded.value[key];
}
</script>

<template>
    <nav v-if="items.length" class="px-2 py-2" aria-label="Main menu">
        <p
            class="px-2 pb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
        >
            Workspace
        </p>
        <div class="flex flex-col gap-1">
            <div v-for="menu in items" :key="menu.key">
                <div class="flex items-center gap-1">
                    <Link
                        :href="menu.href"
                        class="group flex flex-1 items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium transition"
                        :class="
                            active(menu)
                                ? 'bg-emerald-600/10 text-emerald-800 shadow-sm ring-1 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-200'
                                : 'text-foreground/80 hover:bg-muted hover:text-foreground'
                        "
                    >
                        <component
                            :is="icons[menu.icon ?? ''] ?? LayoutDashboard"
                            class="size-4 shrink-0 transition group-hover:scale-110"
                        />
                        <span class="truncate">{{ menu.title }}</span>
                    </Link>
                    <button
                        v-if="menu.children.length"
                        type="button"
                        class="rounded-md p-1.5 text-muted-foreground transition hover:bg-muted hover:text-foreground"
                        :aria-expanded="expanded[menu.key] ? 'true' : 'false'"
                        :aria-label="`Toggle ${menu.title} sections`"
                        @click="toggle(menu.key)"
                    >
                        <ChevronDown
                            class="size-3.5 transition-transform duration-300"
                            :class="expanded[menu.key] ? 'rotate-180' : ''"
                        />
                    </button>
                </div>

                <div
                    v-show="menu.children.length && expanded[menu.key]"
                    class="mt-1 ml-4 space-y-0.5 border-l border-dashed border-border pl-2"
                >
                    <Link
                        v-for="child in menu.children"
                        :key="child.key"
                        :href="child.href"
                        class="flex items-center gap-2 rounded-md px-2 py-1.5 text-xs font-medium transition"
                        :class="
                            isCurrentOrParentUrl(child.href)
                                ? 'bg-emerald-600/10 text-emerald-800 dark:text-emerald-200'
                                : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                        "
                    >
                        <component
                            :is="icons[child.icon ?? ''] ?? List"
                            class="size-3.5 shrink-0"
                        />
                        {{ child.title }}
                    </Link>
                </div>
            </div>
        </div>
    </nav>
</template>
