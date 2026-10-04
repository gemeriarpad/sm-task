<script setup lang="ts" generic="T extends Record<string, any>">
import { ColumnFilter, SelectField, SortHeader, TablePagination } from '@/components/table/parts';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';
import type { DataTableColumn, Pagination } from '@/types';
import { router } from '@inertiajs/vue3';
import type { ColumnDef, PaginationState, SortingState, Updater } from '@tanstack/vue-table';
import { getCoreRowModel, useVueTable } from '@tanstack/vue-table';
import { computed, reactive, watch } from 'vue';

/**
 * Server-driven data table, ported from the laravel-vue-skeleton DataTable with
 * only the features this app uses: sorting, pagination, per-column filters and a
 * leading row-actions column. The server sorts, filters and pages; TanStack only
 * mirrors that state.
 */
const props = withDefaults(
    defineProps<{
        columns: DataTableColumn[];
        /** Rows for the current page (already sorted + paged by the server). */
        rows: T[];
        /** Laravel paginator meta for the current result set. */
        pagination: Pagination;
        /** Active sort column key. */
        sort?: string;
        /** Active sort direction. */
        direction?: 'asc' | 'desc';
        /** Row field used as the row key (defaults to "id"). */
        rowKey?: string;
        emptyText?: string;
        perPageOptions?: number[];
        /** Max height of the table card; the body scrolls under a sticky header. */
        maxHeight?: string;
        /**
         * Inertia prop name holding this table's paginated data. When set,
         * sorting/paging do a partial reload of just this prop (+ sort meta)
         * instead of a full-page visit.
         */
        dataKey?: string;
        /**
         * Active per-column filter values, echoed by the server, used to seed the
         * filter inputs. Only meaningful for columns marked filterable.
         */
        filters?: Record<string, string>;
    }>(),
    {
        sort: undefined,
        direction: undefined,
        rowKey: 'id',
        emptyText: 'Nothing here yet.',
        perPageOptions: () => [10, 25, 50, 100, 250, 500],
        maxHeight: '70vh',
        dataKey: undefined,
        filters: () => ({}),
    },
);

// Column metadata we tuck onto each TanStack column for rendering.
type ColumnMeta = { class?: string };

/**
 * Re-fetch the table from the server, merging the given params into the current
 * query string (so other filters survive). When `dataKey` is set this is a
 * partial reload: only the table's prop (+ sort meta) refreshes, not the page.
 */
function navigate(params: Record<string, string | number>): void {
    const query: Record<string, string | number> = {};
    new URLSearchParams(window.location.search).forEach((value, key) => {
        query[key] = value;
    });
    Object.assign(query, params);

    // Drop emptied keys (e.g. a cleared filter) so they leave the URL entirely.
    for (const key of Object.keys(query)) {
        if (query[key] === '') {
            delete query[key];
        }
    }

    router.get(window.location.pathname, query, {
        only: props.dataKey ? [props.dataKey, 'sort', 'direction', 'filters'] : undefined,
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

// --- Per-column filtering --------------------------------------------------

// Sentinel for a select filter's "All" row. An empty value would read as "no
// selection" and show the placeholder instead.
const FILTER_ALL = '__all__';

const hasFilters = computed(() => props.columns.some((c) => c.filterable || c.filterOptions));

// Local, editable copy of the active filters, seeded from the server echo.
const filterState = reactive<Record<string, string>>({});

for (const col of props.columns) {
    if (col.filterable || col.filterOptions) {
        filterState[col.key] = props.filters[col.key] ?? '';
    }
}

// The last filter set the server echoed. Used to distinguish a genuine
// server-side change (back/forward, reset, or another column's commit) from a
// column the user is mid-typing but hasn't committed, whose echo simply omits
// it. Re-syncing must adopt the former and never clobber the latter.
let lastServerFilters: Record<string, string> = { ...props.filters };

watch(
    () => props.filters,
    (source) => {
        for (const col of props.columns) {
            if (!col.filterable && !col.filterOptions) {
                continue;
            }

            const next = source[col.key] ?? '';

            // Only adopt the server value when it actually changed server-side.
            if (next !== (lastServerFilters[col.key] ?? '')) {
                filterState[col.key] = next;
            }
        }

        lastServerFilters = { ...source };
    },
);

// Push a single column's filter to the server, resetting to page 1. An empty
// value is dropped by navigate(), so clearing a filter removes it from the URL.
function applyFilter(key: string, value: string): void {
    filterState[key] = value;
    navigate({ [`filter[${key}]`]: value, page: 1 });
}

// --- TanStack table (mirrors server state) ---------------------------------

// Map our lightweight column config onto TanStack column defs.
const tableColumns = computed<ColumnDef<T>[]>(() =>
    props.columns.map((col) => ({
        id: col.key,
        accessorKey: col.key,
        header: col.label,
        enableSorting: col.sortable !== false,
        meta: { class: col.class } satisfies ColumnMeta,
    })),
);

// Controlled state mirrored from the server-provided props.
const sortingState = computed<SortingState>(() => (props.sort ? [{ id: props.sort, desc: props.direction === 'desc' }] : []));

const paginationState = computed<PaginationState>(() => ({
    pageIndex: Math.max(0, props.pagination.current_page - 1),
    pageSize: props.pagination.per_page,
}));

const table = useVueTable({
    get data() {
        return props.rows;
    },
    get columns() {
        return tableColumns.value;
    },
    getCoreRowModel: getCoreRowModel(),
    // Server does the work; TanStack just reflects current state.
    manualSorting: true,
    manualPagination: true,
    enableSortingRemoval: false,
    get rowCount() {
        return props.pagination.total;
    },
    state: {
        get sorting() {
            return sortingState.value;
        },
        get pagination() {
            return paginationState.value;
        },
    },
    onSortingChange: (updater: Updater<SortingState>) => {
        const next = typeof updater === 'function' ? updater(sortingState.value) : updater;
        const active = next[0];
        navigate(active ? { sort: active.id, direction: active.desc ? 'desc' : 'asc', page: 1 } : { page: 1 });
    },
    onPaginationChange: (updater: Updater<PaginationState>) => {
        const current = paginationState.value;
        const next = typeof updater === 'function' ? updater(current) : updater;

        if (next.pageSize !== current.pageSize) {
            navigate({ per_page: next.pageSize, page: 1 });
        } else if (next.pageIndex !== current.pageIndex) {
            navigate({ page: next.pageIndex + 1 });
        }
    },
});

function columnClass(meta: unknown): string | undefined {
    return (meta as ColumnMeta | undefined)?.class;
}

function fallback(value: unknown): string {
    return value === null || value === undefined || value === '' ? '—' : String(value);
}

// The row-controls column (edit/etc.) is lifted out of the normal column flow
// and rendered as the very first column, so per-row actions always sit at the
// start of the row. Matched by key; it still lives in `columns` (and TanStack's
// model) but is skipped in the data loops.
const ACTIONS_KEY = 'actions';
const actionsColumn = computed(() => props.columns.find((col) => col.key === ACTIONS_KEY));
const dataColumns = computed(() => props.columns.filter((col) => col.key !== ACTIONS_KEY));
</script>

<template>
    <div :style="{ maxHeight }" class="isolate flex flex-col overflow-hidden rounded-xl border bg-card">
        <Table container-class="min-h-0 min-w-0 flex-1 overflow-y-auto">
            <TableHeader class="sticky top-0 z-20 bg-card">
                <TableRow v-for="headerGroup in table.getHeaderGroups()" :key="headerGroup.id" class="hover:bg-transparent">
                    <TableHead v-if="actionsColumn" :class="actionsColumn.class">
                        <SortHeader :label="actionsColumn.label" />
                    </TableHead>
                    <template v-for="header in headerGroup.headers" :key="header.id">
                        <TableHead v-if="header.column.id !== ACTIONS_KEY" :class="columnClass(header.column.columnDef.meta)">
                            <SortHeader
                                :label="header.column.columnDef.header as string"
                                :sort-key="header.column.getCanSort() ? header.column.id : undefined"
                                :active="sort === header.column.id"
                                :direction="direction ?? 'asc'"
                                @sort="header.column.getToggleSortingHandler()?.($event)"
                            />
                        </TableHead>
                    </template>
                </TableRow>

                <!-- Per-column filter row (text input / select per column). -->
                <TableRow v-if="hasFilters" class="hover:bg-transparent">
                    <TableHead v-if="actionsColumn" :class="cn('h-auto px-2 py-1', actionsColumn.class)" />
                    <TableHead v-for="col in dataColumns" :key="`filter-${col.key}`" :class="cn('h-auto px-2 py-1', col.class)">
                        <SelectField
                            v-if="col.filterOptions"
                            :model-value="filterState[col.key] || FILTER_ALL"
                            :options="[{ value: FILTER_ALL, label: 'All' }, ...col.filterOptions]"
                            size="sm"
                            class="w-full"
                            @update:model-value="(value: string) => applyFilter(col.key, value === FILTER_ALL ? '' : value)"
                        />
                        <ColumnFilter
                            v-else-if="col.filterable"
                            :model-value="filterState[col.key] ?? ''"
                            @update:model-value="(value: string) => (filterState[col.key] = value)"
                            @commit="(value: string) => applyFilter(col.key, value)"
                        />
                    </TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableEmpty v-if="pagination.total === 0" :colspan="columns.length">
                    {{ emptyText }}
                </TableEmpty>

                <TableRow v-for="row in table.getRowModel().rows" :key="String(row.original[rowKey])">
                    <TableCell v-if="actionsColumn" :class="actionsColumn.class">
                        <slot name="cell-actions" :row="row.original" :value="undefined" />
                    </TableCell>
                    <template v-for="cell in row.getVisibleCells()" :key="cell.id">
                        <TableCell v-if="cell.column.id !== ACTIONS_KEY" :class="columnClass(cell.column.columnDef.meta)">
                            <slot :name="`cell-${cell.column.id}`" :row="row.original" :value="cell.getValue()">
                                {{ fallback(cell.getValue()) }}
                            </slot>
                        </TableCell>
                    </template>
                </TableRow>
            </TableBody>
        </Table>

        <!-- Pagination footer -->
        <TablePagination
            v-if="pagination.total > 0"
            :pagination="pagination"
            :per-page-options="perPageOptions"
            class="shrink-0 border-t px-4 py-3"
            @update:page="(p: number) => table.setPageIndex(p - 1)"
            @update:per-page="(n: number) => table.setPageSize(n)"
        />
    </div>
</template>
