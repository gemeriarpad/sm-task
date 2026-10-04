<script setup lang="ts">
import DataTable from '@/components/table/DataTable.vue';
import type { RowAction } from '@/components/table/RowActions.vue';
import RowActions from '@/components/table/RowActions.vue';
import SyncRunTarget from '@/components/table/SyncRunTarget.vue';
import { Badge } from '@/components/ui/badge';
import { formatSize } from '@/lib/format';
import type { CloneErrorItem, CloneErrorOptions, DataTableColumn, Pagination } from '@/types';
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        repositories: CloneErrorItem[];
        pagination: Pagination;
        sort?: string;
        direction?: 'asc' | 'desc';
        /** Active inline filters, keyed by column key (server echo). */
        filters: Record<string, string>;
        options: CloneErrorOptions;
        emptyText?: string;
    }>(),
    { sort: undefined, direction: undefined, emptyText: 'No clone errors.' },
);

const columns = computed<DataTableColumn[]>(() => [
    // Actions lead every table (first column).
    { key: 'actions', label: 'Actions', sortable: false, class: 'w-0 text-center' },
    { key: 'repository', label: 'Repository', class: 'whitespace-nowrap' },
    { key: 'target', label: 'Target', filterOptions: props.options.targets },
    { key: 'kind', label: 'Kind', class: 'whitespace-nowrap', filterOptions: props.options.kinds },
    { key: 'clone_error', label: 'Error', sortable: false, class: 'w-full min-w-64' },
    { key: 'size', label: 'Size', class: 'text-right whitespace-nowrap' },
]);

function rowActions(row: CloneErrorItem): RowAction[] {
    return [
        { action: 'view', href: route('repositories.show', { repository: row.id }), label: 'View repository' },
        // A skipped repository is still too large: retrying can't help.
        ...(row.clone_status === 'failed'
            ? [
                  {
                      action: 'retry' as const,
                      label: 'Retry clone',
                      onSelect: () => router.post(route('repositories.clone.refresh', { repository: row.id }), {}, { preserveScroll: true }),
                  },
              ]
            : []),
    ];
}
</script>

<template>
    <DataTable
        :columns="columns"
        :rows="repositories"
        :pagination="pagination"
        :sort="sort"
        :direction="direction"
        :filters="filters"
        :empty-text="emptyText"
        data-key="repositories"
    >
        <template #cell-actions="{ row }">
            <RowActions :actions="rowActions(row)" />
        </template>

        <template #cell-repository="{ row }">
            <Link :href="route('repositories.show', { repository: row.id })" class="font-medium hover:underline">{{ row.full_name }}</Link>
            <span v-if="row.clone_branch" class="block text-xs text-muted-foreground">branch {{ row.clone_branch }}</span>
        </template>

        <template #cell-target="{ row }">
            <SyncRunTarget :target="row.target" />
        </template>

        <template #cell-kind="{ row }">
            <Badge v-if="row.clone_status === 'failed'" class="border-transparent bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300">
                Failed
            </Badge>
            <Badge v-else class="border-transparent bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300">Too large</Badge>
        </template>

        <template #cell-clone_error="{ row }">
            <p
                :class="[
                    'whitespace-normal break-words',
                    row.clone_status === 'failed' ? 'text-red-600 dark:text-red-400' : 'text-amber-700 dark:text-amber-400',
                ]"
            >
                {{ row.clone_error ?? '—' }}
            </p>
        </template>

        <template #cell-size="{ row }">
            <span class="tabular-nums text-muted-foreground">{{ formatSize(row.size_kb) }}</span>
        </template>
    </DataTable>
</template>
