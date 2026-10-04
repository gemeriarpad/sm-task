<script setup lang="ts">
import DataTable from '@/components/table/DataTable.vue';
import type { RowAction } from '@/components/table/RowActions.vue';
import RowActions from '@/components/table/RowActions.vue';
import SyncRunTarget from '@/components/table/SyncRunTarget.vue';
import { Badge } from '@/components/ui/badge';
import { formatCount, formatDateTime, formatRelative } from '@/lib/format';
import type { DataTableColumn, Pagination, SyncRunItem, SyncRunOptions } from '@/types';
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        runs: SyncRunItem[];
        pagination: Pagination;
        sort?: string;
        direction?: 'asc' | 'desc';
        /** Active inline filters, keyed by column key (server echo). */
        filters: Record<string, string>;
        options: SyncRunOptions;
        emptyText?: string;
    }>(),
    { sort: undefined, direction: undefined, emptyText: 'No sync errors.' },
);

const columns = computed<DataTableColumn[]>(() => [
    // Actions lead every table (first column).
    { key: 'actions', label: 'Actions', sortable: false, class: 'w-0 text-center' },
    { key: 'started_at', label: 'When', class: 'whitespace-nowrap' },
    { key: 'target', label: 'Target', filterOptions: props.options.targets },
    // A failed sync, or a sync that succeeded but failed to clone some repositories.
    { key: 'status', label: 'Kind', class: 'whitespace-nowrap' },
    { key: 'error', label: 'Error', sortable: false, filterable: true, class: 'w-full min-w-64' },
    { key: 'attempt', label: 'Attempt', class: 'text-right' },
    { key: 'retrying', label: 'Retrying', sortable: false, filterOptions: props.options.retrying },
]);

function rowActions(row: SyncRunItem): RowAction[] {
    return [
        { action: 'view', href: route('targets.show', { target: row.target.id }), label: 'View target' },
        { action: 'sync', onSelect: () => router.post(route('targets.sync', { target: row.target.id }), {}, { preserveScroll: true }) },
    ];
}
</script>

<template>
    <DataTable
        :columns="columns"
        :rows="runs"
        :pagination="pagination"
        :sort="sort"
        :direction="direction"
        :filters="filters"
        :empty-text="emptyText"
        data-key="runs"
    >
        <template #cell-actions="{ row }">
            <RowActions :actions="rowActions(row)" />
        </template>

        <template #cell-started_at="{ row }">
            <span class="text-muted-foreground" :title="formatRelative(row.started_at)">{{ formatDateTime(row.started_at) }}</span>
        </template>

        <template #cell-target="{ row }">
            <SyncRunTarget :target="row.target" />
        </template>

        <template #cell-status="{ row }">
            <Badge v-if="row.status === 'failed'" class="border-transparent bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300">
                Sync failed
            </Badge>
            <Badge v-else class="border-transparent bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300">Clone errors</Badge>
        </template>

        <template #cell-error="{ row }">
            <p v-if="row.error" class="whitespace-normal break-words text-red-600 dark:text-red-400">{{ row.error }}</p>
            <!-- Clone error messages live on the repositories; the run only counts them. -->
            <p v-else-if="row.clone_errors_count" class="whitespace-normal text-amber-700 dark:text-amber-400">
                {{ formatCount(row.clone_errors_count) }} {{ row.clone_errors_count === 1 ? 'repository' : 'repositories' }} failed to clone.
                <Link :href="route('clone-errors.index', { filter: { target: row.target.id } })" class="underline-offset-2 hover:underline">
                    See why
                </Link>
            </p>
            <span v-else class="text-muted-foreground">—</span>
        </template>

        <template #cell-attempt="{ row }">
            <span class="tabular-nums">{{ row.attempt }}</span>
        </template>

        <template #cell-retrying="{ row }">
            <Badge v-if="row.will_retry" variant="outline">Retrying</Badge>
            <span v-else class="text-muted-foreground">—</span>
        </template>
    </DataTable>
</template>
