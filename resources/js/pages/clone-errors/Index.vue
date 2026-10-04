<script setup lang="ts">
import ErrorTabs from '@/components/ErrorTabs.vue';
import Heading from '@/components/Heading.vue';
import CloneErrorsTable from '@/components/table/CloneErrorsTable.vue';
import { useLiveSync } from '@/composables/useLiveSync';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem, CloneErrorItem, CloneErrorOptions, Paginated } from '@/types';
import { Head, usePage } from '@inertiajs/vue3';
import { CircleAlert } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage<{
    repositories: Paginated<CloneErrorItem>;
    sort: string;
    direction: 'asc' | 'desc';
    filters: Record<string, string>;
    options: CloneErrorOptions;
    syncing: boolean;
}>();
// Reactive: re-reads on each (partial) Inertia visit so the table updates.
const repositories = computed(() => page.props.repositories);

// Clones fail, succeed or get skipped as syncs run.
useLiveSync({
    only: ['repositories', 'options', 'syncing'],
    pollWhile: () => page.props.syncing,
    events: ['.target.sync.updated', '.repository.clone.updated'],
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Errors', href: route('sync-runs.errors') },
    { title: 'Clone errors', href: route('clone-errors.index') },
];
</script>

<template>
    <Head title="Clone errors" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex w-full flex-col gap-6 p-4">
            <Heading
                title="Errors"
                description="Repositories that should be cloned but aren't: the clone failed, or the repository is larger than the clone limit. A repository leaves this list once it is cloned or no longer meant to be."
                :icon="CircleAlert"
            />
            <ErrorTabs current="clones" />
            <CloneErrorsTable
                :repositories="repositories.data"
                :pagination="repositories"
                :sort="page.props.sort"
                :direction="page.props.direction"
                :filters="page.props.filters"
                :options="page.props.options"
            />
        </div>
    </AppLayout>
</template>
