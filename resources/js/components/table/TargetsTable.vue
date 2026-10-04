<script setup lang="ts">
import DataTable from '@/components/table/DataTable.vue';
import type { RowAction } from '@/components/table/RowActions.vue';
import RowActions from '@/components/table/RowActions.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { formatCount, formatDateTime } from '@/lib/format';
import { STATUS_CLASSES } from '@/lib/targetStatus';
import type { DataTableColumn, Pagination, TargetListItem, TargetOptions, TargetStatus } from '@/types';
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        targets: TargetListItem[];
        pagination: Pagination;
        sort?: string;
        direction?: 'asc' | 'desc';
        /** Active inline filters, keyed by column key (server echo). */
        filters: Record<string, string>;
        options: TargetOptions;
        emptyText?: string;
    }>(),
    { sort: undefined, direction: undefined, emptyText: 'No targets yet.' },
);

const columns = computed<DataTableColumn[]>(() => [
    // Actions lead every table (first column).
    { key: 'actions', label: 'Actions', sortable: false, class: 'w-0 text-center' },
    { key: 'name', label: 'Name', filterable: true },
    { key: 'type', label: 'Type', filterOptions: props.options.types },
    { key: 'status', label: 'Status', filterOptions: props.options.statuses },
    { key: 'repositories_count', label: 'Repositories', class: 'whitespace-nowrap' },
    { key: 'excluded_count', label: 'Excluded', class: 'text-right' },
    { key: 'clones', label: 'Clones', sortable: false, class: 'whitespace-nowrap' },
    { key: 'last_synced_at', label: 'Last synced' },
    { key: 'last_error', label: 'Last error', sortable: false },
]);

const labelOf = (options: { value: string; label: string }[], value: string): string => options.find((o) => o.value === value)?.label ?? value;

function rowActions(row: TargetListItem): RowAction[] {
    return [
        { action: 'view', href: route('targets.show', { target: row.id }) },
        // Inactive targets are reactivated from their page.
        ...(row.status === 'inactive'
            ? []
            : [
                  {
                      action: 'sync',
                      onSelect: () => router.post(route('targets.sync', { target: row.id }), {}, { preserveScroll: true }),
                  } as RowAction,
              ]),
        { action: 'edit', href: route('targets.edit', { target: row.id }) },
    ];
}
</script>

<template>
    <DataTable
        :columns="columns"
        :rows="targets"
        :pagination="pagination"
        :sort="sort"
        :direction="direction"
        :filters="filters"
        :empty-text="emptyText"
        data-key="targets"
    >
        <template #cell-actions="{ row }">
            <RowActions :actions="rowActions(row)" />
        </template>

        <template #cell-name="{ row }">
            <div class="flex items-center gap-2">
                <Avatar class="size-7 shadow-sm ring-1 ring-black/10 dark:ring-white/15">
                    <AvatarImage v-if="row.avatar_url" :src="row.avatar_url" :alt="row.name" />
                    <AvatarFallback class="text-xs uppercase">{{ row.name.slice(0, 2) }}</AvatarFallback>
                </Avatar>
                <Link :href="route('targets.show', { target: row.id })" class="font-medium hover:underline">{{ row.name }}</Link>
            </div>
        </template>

        <template #cell-type="{ row }">
            <Badge variant="outline">{{ labelOf(options.types, row.type) }}</Badge>
        </template>

        <template #cell-status="{ row }">
            <Badge :class="['border-transparent', STATUS_CLASSES[row.status as TargetStatus]]">
                {{ labelOf(options.statuses, row.status) }}
            </Badge>
        </template>

        <template #cell-repositories_count="{ row }">
            <span
                :class="[
                    'tabular-nums',
                    row.github_repositories_count !== null && row.repositories_count + row.excluded_count < row.github_repositories_count
                        ? 'text-amber-700 dark:text-amber-400'
                        : '',
                ]"
                :title="
                    row.github_repositories_count === null
                        ? 'Not synced yet'
                        : `${row.repositories_count} stored of ${row.github_repositories_count} returned by GitHub in the last sync`
                "
            >
                {{ formatCount(row.repositories_count) }} /
                {{ row.github_repositories_count === null ? '?' : formatCount(row.github_repositories_count) }}
                <span class="text-muted-foreground">synced</span>
            </span>
        </template>

        <template #cell-excluded_count="{ row }">
            <span :class="['tabular-nums', row.excluded_count === 0 && 'text-muted-foreground']">{{ formatCount(row.excluded_count) }}</span>
        </template>

        <template #cell-clones="{ row }">
            <span v-if="row.clones_enabled_count > 0" class="tabular-nums" title="Cloned / repositories with cloning enabled">
                {{ formatCount(row.clones_cloned_count) }} / {{ formatCount(row.clones_enabled_count) }}
                <span class="text-muted-foreground">cloned</span>
            </span>
            <span v-else class="text-muted-foreground">—</span>
        </template>

        <template #cell-last_synced_at="{ row }">
            <span class="whitespace-nowrap text-muted-foreground">{{ formatDateTime(row.last_synced_at, '—') }}</span>
        </template>

        <template #cell-last_error="{ row }">
            <span v-if="row.last_error" class="block max-w-xs truncate text-red-600 dark:text-red-400" :title="row.last_error">
                {{ row.last_error }}
            </span>
            <span v-else class="text-muted-foreground">—</span>
        </template>
    </DataTable>
</template>
