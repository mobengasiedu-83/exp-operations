<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';

defineOptions({ layout: AppLayout });

type Section = {
    key: string;
    title: string;
    description: string;
    href: string;
};

type RoleSummary = { id: number; name: string; users: number };

defineProps<{ sections: Section[]; roles: RoleSummary[] }>();
</script>

<template>
    <div>
        <Head title="Administration" />
        <div class="space-y-6 p-6">
            <Heading
                title="Administration"
                description="Users, question versions, dentists and service settings."
            />

            <section class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <Link
                    v-for="section in sections"
                    :key="section.key"
                    :href="section.href"
                    class="rounded-xl border p-4 hover:bg-slate-50 dark:hover:bg-slate-900"
                >
                    <h2 class="font-semibold">{{ section.title }}</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ section.description }}
                    </p>
                </Link>
            </section>

            <section class="rounded-xl border p-4">
                <h2 class="font-semibold">Roles in use</h2>
                <p v-if="!roles.length" class="mt-2 text-sm text-muted-foreground">
                    No roles have been created yet.
                </p>
                <ul v-else class="mt-2 grid gap-1 text-sm md:grid-cols-2">
                    <li
                        v-for="role in roles"
                        :key="role.id"
                        class="flex items-center justify-between border-b py-1"
                    >
                        <span>{{ role.name }}</span>
                        <span class="text-muted-foreground">
                            {{ role.users }}
                            {{ role.users === 1 ? 'user' : 'users' }}
                        </span>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
