import { Eye, RefreshCw, RotateCcw, SquarePen, Trash2 } from 'lucide-vue-next';
import type { Component } from 'vue';

/**
 * The canonical row actions. Define every table action here ONCE so it uses the
 * same icon and label in every table. Rendered by RowActions in a table's first
 * column. Add a key here, plus its entry in TABLE_ACTIONS below, to introduce a
 * new action everywhere.
 */
export type TableActionKey = 'view' | 'edit' | 'sync' | 'retry' | 'forget';

export type TableActionDef = {
    icon: Component;
    /** Tooltip + aria-label. */
    label: string;
};

export const TABLE_ACTIONS: Record<TableActionKey, TableActionDef> = {
    view: { icon: Eye, label: 'View' },
    edit: { icon: SquarePen, label: 'Edit' },
    sync: { icon: RefreshCw, label: 'Sync now' },
    retry: { icon: RotateCcw, label: 'Retry' },
    forget: { icon: Trash2, label: 'Forget' },
};
