<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
defineProps<{
    rows: Record<string, unknown>[];
    columns: { key: string; label: string }[];
    previous?: string | null;
    next?: string | null;
}>();
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
    <div class="space-y-4">
        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr>
                        <th v-for="column in columns" :key="column.key" class="p-3">
                            {{ column.label }}
                        </th>
                        <th v-if="$slots.actions" class="p-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(row, index) in rows"
                        :key="String(row.id ?? index)"
                        class="border-t"
                    >
                        <td
                            v-for="column in columns"
                            :key="column.key"
                            class="max-w-lg break-words p-3"
                        >
                            <slot :name="`cell-${column.key}`" :row="row">{{ display(row[column.key]) }}</slot>
                        </td>
                        <td v-if="$slots.actions" class="p-3">
                            <slot name="actions" :row="row" />
                        </td>
                    </tr>
                    <tr v-if="rows.length === 0">
                        <td :colspan="columns.length + 1" class="p-6 text-muted-foreground">
                            No records found.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <nav class="flex gap-4" aria-label="Pagination">
            <Link v-if="previous" :href="previous">Previous</Link
            ><Link v-if="next" :href="next">Next</Link>
        </nav>
    </div>
</template>
