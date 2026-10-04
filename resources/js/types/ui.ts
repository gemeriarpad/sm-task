/** Pagination metadata as serialized by Laravel's paginator. */
export type Pagination = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

/** A Laravel length-aware paginator payload (data + meta). */
export type Paginated<T> = Pagination & {
    data: T[];
};

/** A select option as sent by the server. */
export type Option = {
    value: string;
    label: string;
};

/** A column definition for the generic DataTable. */
export type DataTableColumn = {
    /** Row field key; also the name of the `cell-<key>` slot. */
    key: string;
    label: string;
    /** Extra classes applied to the header cell and body cells (e.g. 'text-right'). */
    class?: string;
    /** Disable sorting for this column (sortable by default). */
    sortable?: boolean;
    /** Render a debounced text filter for this column (filters server-side). */
    filterable?: boolean;
    /**
     * Render a select filter instead of a text input (for enum columns like a
     * status). Matched exactly server-side; an injected "All" option clears it.
     */
    filterOptions?: Option[];
};
