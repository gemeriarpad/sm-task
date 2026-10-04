<script setup lang="ts">
import DataTable from '@/components/table/DataTable.vue';
import type { RowAction } from '@/components/table/RowActions.vue';
import RowActions from '@/components/table/RowActions.vue';
import SyncRunTarget from '@/components/table/SyncRunTarget.vue';
import { Badge } from '@/components/ui/badge';
import { formatCount, formatDateTime, formatDuration, formatRelative } from '@/lib/format';
import { RUN_STATUS_CLASSES } from '@/lib/targetStatus';
import type { DataTableColumn, Pagination, SyncRunItem, SyncRunOptions } from '@/types';
import { LoaderCircle } from 'lucide-vue-next';
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
    { sort: undefined, direction: undefined, emptyText: 'No syncs have run yet.' },
);

const columns = computed<DataTableColumn[]>(() => [
    // Actions lead every table (first column).
    { key: 'actions', label: 'Actions', sortable: false, class: 'w-0 text-center' },
    { key: 'started_at', label: 'Started', class: 'whitespace-nowrap' },
    { key: 'target', label: 'Target', filterOptions: props.options.targets },
    { key: 'status', label: 'Status', filterOptions: props.options.statuses },
    { key: 'attempt', label: 'Attempt', class: 'text-right' },
    { key: 'repositories_count', label: 'Repositories', class: 'text-right' },
    { key: 'clones', label: 'Clones', sortable: false, class: 'whitespace-nowrap' },
    { key: 'duration_ms', label: 'Duration', class: 'text-right whitespace-nowrap' },
    { key: 'error', label: 'Error', sortable: false },
]);

function rowActions(row: SyncRunItem): RowAction[] {
    return [{ action: 'view', href: route('targets.show', { target: row.target.id }), label: 'View target' }];
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
            <Badge :class="['border-transparent capitalize', RUN_STATUS_CLASSES[row.status]]">
                <LoaderCircle v-if="row.status === 'running'" class="size-3 animate-spin" />
                {{ row.status }}
            </Badge>
        </template>

        <template #cell-attempt="{ row }">
            <span class="tabular-nums">{{ row.attempt }}</span>
        </template>

        <template #cell-repositories_count="{ row }">
            <span class="tabular-nums">{{ row.repositories_count === null ? '—' : formatCount(row.repositories_count) }}</span>
        </template>

        <template #cell-clones="{ row }">
            <span
                v-if="row.clones_count || row.clone_errors_count || row.clones_deferred_count"
                class="tabular-nums"
                title="Cloned or updated · failed · left for the next sync"
            >
                {{ formatCount(row.clones_count ?? 0) }} <span class="text-muted-foreground">cloned</span>
                <template v-if="row.clone_errors_count">
                    · <span class="text-red-600 dark:text-red-400">{{ formatCount(row.clone_errors_count) }} failed</span>
                </template>
                <template v-if="row.clones_deferred_count">
                    · {{ formatCount(row.clones_deferred_count) }} <span class="text-muted-foreground">deferred</span>
                </template>
            </span>
            <span v-else class="text-muted-foreground">—</span>
        </template>

        <template #cell-duration_ms="{ row }">
            <span class="tabular-nums text-muted-foreground">{{ formatDuration(row.duration_ms) }}</span>
        </template>

        <template #cell-error="{ row }">
            <span v-if="row.error" class="block max-w-xs truncate text-red-600 dark:text-red-400" :title="row.error">
                {{ row.error }}
            </span>
            <span v-else class="text-muted-foreground">—</span>
        </template>
    </DataTable>
</template>
