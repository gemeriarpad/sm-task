<script setup lang="ts">
import { cn } from '@/lib/utils';
import { ArrowDown, ArrowUp, ChevronsUpDown } from 'lucide-vue-next';

/**
 * Inline sortable column header — the label plus a sort-direction indicator.
 * Renders as a `<button>` when `sortKey` is set, otherwise a plain `<span>`.
 * Drop it inside any `<th>` / `TableHead`; the parent owns the sort state and
 * reacts to the `sort` event (the server, TanStack, etc.).
 */
const props = withDefaults(
    defineProps<{
        /** Header text. */
        label: string;
        /** Sort key to emit; when omitted the header is not sortable. */
        sortKey?: string;
        /** Whether this column is the active sort. */
        active?: boolean;
        /** Active sort direction. */
        direction?: 'asc' | 'desc';
    }>(),
    { sortKey: undefined, active: false, direction: 'asc' },
);

const emit = defineEmits<{ sort: [key: string] }>();

function onClick(): void {
    if (props.sortKey) {
        emit('sort', props.sortKey);
    }
}
</script>

<template>
    <button
        v-if="sortKey"
        type="button"
        :class="cn('flex w-full cursor-pointer items-center justify-between gap-2 hover:underline', active && 'font-bold')"
        @click="onClick"
    >
        {{ label }}
        <ArrowUp v-if="active && direction === 'asc'" class="size-3.5" />
        <ArrowDown v-else-if="active && direction === 'desc'" class="size-3.5" />
        <ChevronsUpDown v-else class="size-3.5 opacity-40" />
    </button>
    <span v-else>{{ label }}</span>
</template>
