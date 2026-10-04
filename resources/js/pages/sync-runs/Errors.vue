<script setup lang="ts">
import ErrorTabs from '@/components/ErrorTabs.vue';
import Heading from '@/components/Heading.vue';
import SyncErrorsTable from '@/components/table/SyncErrorsTable.vue';
import { useLiveSync } from '@/composables/useLiveSync';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem, Paginated, SyncRunItem, SyncRunOptions } from '@/types';
import { Head, usePage } from '@inertiajs/vue3';
import { CircleAlert } from 'lucide-vue-next';
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

// New errors appear as syncs fail.
useLiveSync({ only: ['runs', 'options', 'syncing'], pollWhile: () => page.props.syncing });

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Errors', href: route('sync-runs.errors') }];
</script>

<template>
    <Head title="Errors" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex w-full flex-col gap-6 p-4">
            <Heading
                title="Errors"
                description="Failed sync attempts, and syncs that finished but failed to clone some repositories. Newest first."
                :icon="CircleAlert"
            />
            <ErrorTabs current="sync" />
            <SyncErrorsTable
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
