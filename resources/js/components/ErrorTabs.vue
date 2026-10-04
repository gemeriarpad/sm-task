<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

/**
 * Switches between the views of the Errors section: our own record of failed sync
 * attempts (sync_runs), the repositories whose clone failed or was skipped, and the
 * queue's failed_jobs table.
 */
defineProps<{ current: 'sync' | 'clones' | 'jobs' }>();

const tabs = [
    { key: 'sync', label: 'Sync errors', href: route('sync-runs.errors') },
    { key: 'clones', label: 'Clone errors', href: route('clone-errors.index') },
    { key: 'jobs', label: 'Failed jobs', href: route('failed-jobs.index') },
] as const;
</script>

<template>
    <nav aria-label="Error views" class="flex gap-1 border-b">
        <Link
            v-for="tab in tabs"
            :key="tab.key"
            :href="tab.href"
            :aria-current="tab.key === current ? 'page' : undefined"
            class="-mb-px border-b-2 px-3 py-2 text-sm font-medium transition-colors"
            :class="tab.key === current ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground'"
        >
            {{ tab.label }}
        </Link>
    </nav>
</template>
