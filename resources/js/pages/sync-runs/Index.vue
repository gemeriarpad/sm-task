<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import SyncRunsTable from '@/components/table/SyncRunsTable.vue';
import { useLiveSync } from '@/composables/useLiveSync';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem, Paginated, SyncRunItem, SyncRunOptions } from '@/types';
import { Head, usePage } from '@inertiajs/vue3';
import { History } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage<{
    runs: Paginated<SyncRunItem>;
    sort: string;
    direction: 'asc' | 'desc';
    filters: Record<string, string>;
    options: SyncRunOptions;
    syncing: boolean;
}>();
// Reactive: re-reads on each (partial) Inertia visit so the table updates.
const runs = computed(() => page.props.runs);

// New runs appear and running ones finish as syncs progress.
useLiveSync({ only: ['runs', 'options', 'syncing'], pollWhile: () => page.props.syncing });

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Sync history', href: route('sync-runs.index') }];
</script>

<template>
    <Head title="Sync history" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex w-full flex-col gap-6 p-4">
            <Heading
                title="Sync history"
                description="Every sync job attempt, newest first. A retried sync shows one row per attempt."
                :icon="History"
            />
            <SyncRunsTable
                :runs="runs.data"
                :pagination="runs"
                :sort="page.props.sort"
                :direction="page.props.direction"
                :filters="page.props.filters"
                :options="page.props.options"
            />
        </div>
    </AppLayout>
</template>
