<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import RepositoryList from '@/components/repositories/RepositoryList.vue';
import { useLiveSync } from '@/composables/useLiveSync';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount } from '@/lib/format';
import type { BreadcrumbItem, Paginated, RepositoryListItem, RepositoryListOptions, RepositoryQuery } from '@/types';
import { Head, usePage } from '@inertiajs/vue3';
import { FolderGit2 } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage<{
    repositories: Paginated<RepositoryListItem>;
    query: RepositoryQuery;
    options: RepositoryListOptions;
    totals: { repositories: number; missing: number };
    syncing: boolean;
}>();
const totals = computed(() => page.props.totals);

// New repositories appear as syncs finish.
useLiveSync({
    only: ['repositories', 'options', 'totals', 'syncing'],
    pollWhile: () => page.props.syncing,
    events: ['.target.sync.updated', '.repository.clone.updated'],
});

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Repositories', href: route('repositories.index') }];
</script>

<template>
    <Head title="Repositories" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex w-full flex-col gap-6 p-4">
            <Heading
                title="Repositories"
                :description="`${formatCount(totals.repositories)} repositories across all targets${totals.missing > 0 ? `, ${formatCount(totals.missing)} removed from GitHub` : ''}.`"
                :icon="FolderGit2"
            />
            <RepositoryList
                :repositories="page.props.repositories"
                :query="page.props.query"
                :options="page.props.options"
                :url="route('repositories.index')"
                :has-any="totals.repositories > 0"
                show-target
                empty-text="No repositories yet. Add a target and sync it to fill this list."
            />
        </div>
    </AppLayout>
</template>
