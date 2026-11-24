<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { Link } from "@inertiajs/vue3";
import { ChevronLeft, ChevronRight, Inbox } from "@lucide/vue";

const props = withDefaults(
    defineProps<{
        rows: Record<string, unknown>[];
        columns: { key: string; label: string }[];
        previous?: string | null;
        next?: string | null;
        emptyTitle?: string;
        emptyDescription?: string;
        rowNoun?: string;
    }>(),
    {
        previous: null,
        next: null,
        emptyTitle: "No records found.",
        emptyDescription: "Adjust the filters above and try again.",
        rowNoun: "record",
    },
);

/** Keeps the right-edge fade in sync with how far the table has been scrolled. */
const scroller = ref<HTMLElement | null>(null);
const canScrollRight = ref(false);

function updateScrollHint(): void {
    const element = scroller.value;

    if (!element) {
        return;
    }

    canScrollRight.value = element.scrollWidth - element.clientWidth - element.scrollLeft > 4;
}

onMounted(() => {
    updateScrollHint();
    window.addEventListener("resize", updateScrollHint);
});

watch(
    () => props.rows,
    () => nextTick(updateScrollHint),
);

onBeforeUnmount(() => {
    window.removeEventListener("resize", updateScrollHint);
});

/** Rows are capped so a full page never takes more than a beat to settle. */
function rowDelay(index: number): string {
    return `${Math.min(index * 20, 200)}ms`;
}

function display(value: unknown): string {
    if (value === null || value === undefined) return "—";
    if (Array.isArray(value))
        return value
            .map((item) =>
                typeof item === "object" && item !== null && "name" in item
                    ? String(item.name)
                    : String(item),
            )
            .join(", ");
    if (typeof value === "object") return JSON.stringify(value);
    return String(value);
}
</script>

<template>
    <div
        class="exp-rise overflow-hidden rounded-2xl border bg-card shadow-sm ring-1 ring-border/60"
    >
        <div class="relative">
            <div
                ref="scroller"
                class="overflow-x-auto"
                @scroll.passive="updateScrollHint"
            >
                <table class="w-full min-w-max border-collapse text-left text-sm md:min-w-full">
                    <thead>
                        <tr class="bg-muted/60">
                            <th
                                v-for="column in columns"
                                :key="column.key"
                                scope="col"
                                class="px-3.5 py-3 text-[11px] font-semibold tracking-[0.12em] whitespace-nowrap text-muted-foreground uppercase first:pl-4 last:pr-4"
                            >
                                {{ column.label }}
                            </th>
                            <th
                                v-if="$slots.actions"
                                scope="col"
                                class="px-3.5 py-3 text-right text-[11px] font-semibold tracking-[0.12em] whitespace-nowrap text-muted-foreground uppercase last:pr-4"
                            >
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/70">
                        <tr
                            v-for="(row, index) in rows"
                            :key="String(row.id ?? index)"
                            class="exp-row group transition-colors duration-200 hover:bg-emerald-500/[0.04] dark:hover:bg-emerald-400/[0.07]"
                            :style="{ animationDelay: rowDelay(index) }"
                        >
                            <td
                                v-for="column in columns"
                                :key="column.key"
                                class="max-w-lg px-3.5 py-3 align-top break-words first:pl-4 last:pr-4"
                            >
                                <slot :name="`cell-${column.key}`" :row="row">{{
                                    display(row[column.key])
                                }}</slot>
                            </td>
                            <td v-if="$slots.actions" class="px-3.5 py-3 text-right align-top last:pr-4">
                                <slot name="actions" :row="row" />
                            </td>
                        </tr>
                        <tr v-if="rows.length === 0">
                            <td
                                :colspan="columns.length + ($slots.actions ? 1 : 0)"
                                class="px-6 py-16"
                            >
                                <div class="mx-auto flex max-w-sm flex-col items-center gap-3 text-center">
                                    <span
                                        class="exp-float relative grid size-14 place-items-center rounded-2xl bg-muted text-muted-foreground"
                                        aria-hidden="true"
                                    >
                                        <Inbox class="size-6" :stroke-width="1.6" />
                                        <span
                                            class="exp-ping-soft absolute inset-0 rounded-2xl ring-2 ring-emerald-500/30"
                                        />
                                    </span>
                                    <p class="text-sm font-semibold">{{ emptyTitle }}</p>
                                    <p class="text-xs text-muted-foreground">{{ emptyDescription }}</p>
                                    <slot name="empty" />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div
                v-show="canScrollRight"
                class="pointer-events-none absolute inset-y-0 right-0 w-10 bg-gradient-to-l from-card to-transparent"
                aria-hidden="true"
            />
        </div>
        <footer
            v-if="rows.length > 0"
            class="flex flex-wrap items-center justify-between gap-3 border-t bg-muted/30 px-4 py-3"
        >
            <p class="text-xs text-muted-foreground">
                Showing
                <span class="font-semibold text-foreground">{{ rows.length }}</span>
                {{ rowNoun }}{{ rows.length === 1 ? "" : "s" }} on this page
            </p>
            <nav class="flex items-center gap-2" aria-label="Pagination">
                <Link
                    v-if="previous"
                    :href="previous"
                    class="inline-flex items-center gap-1.5 rounded-xl border bg-card px-3 py-1.5 text-xs font-semibold shadow-xs transition duration-200 hover:-translate-y-px hover:border-emerald-500/40 hover:text-emerald-700 hover:shadow-sm dark:hover:text-emerald-300"
                >
                    <ChevronLeft class="size-3.5" aria-hidden="true" />
                    Previous
                </Link>
                <span
                    v-else
                    aria-disabled="true"
                    class="inline-flex items-center gap-1.5 rounded-xl border border-dashed px-3 py-1.5 text-xs font-semibold text-muted-foreground/60"
                >
                    <ChevronLeft class="size-3.5" aria-hidden="true" />
                    Previous
                </span>
                <Link
                    v-if="next"
                    :href="next"
                    class="inline-flex items-center gap-1.5 rounded-xl border bg-card px-3 py-1.5 text-xs font-semibold shadow-xs transition duration-200 hover:-translate-y-px hover:border-emerald-500/40 hover:text-emerald-700 hover:shadow-sm dark:hover:text-emerald-300"
                >
                    Next
                    <ChevronRight class="size-3.5" aria-hidden="true" />
                </Link>
                <span
                    v-else
                    aria-disabled="true"
                    class="inline-flex items-center gap-1.5 rounded-xl border border-dashed px-3 py-1.5 text-xs font-semibold text-muted-foreground/60"
                >
                    Next
                    <ChevronRight class="size-3.5" aria-hidden="true" />
                </span>
            </nav>
        </footer>
    </div>
</template>
