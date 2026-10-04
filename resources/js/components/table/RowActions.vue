<script setup lang="ts">
import type { TableActionKey } from '@/lib/tableActions';
import { TABLE_ACTIONS } from '@/lib/tableActions';
import { Link } from '@inertiajs/vue3';

/**
 * A row's action icons, for the table's first column. Each entry names a
 * canonical action from TABLE_ACTIONS; the icon and label come from there, so
 * every table looks identical.
 *
 * Deliberately plain <Link>/<button> with static classes and a native `title`
 * tooltip (no per-row floating-tooltip instances), so an actions column stays
 * cheap even on a large table.
 */
export type RowAction = {
    /** Canonical action key, supplies the icon + label. */
    action: TableActionKey;
    /** Inertia navigation target. Omit to use `onSelect` instead. */
    href?: string;
    /** Click handler for non-navigation actions (e.g. open a dialog). */
    onSelect?: () => void;
    /** Override the registry label/tooltip. */
    label?: string;
};

defineProps<{ actions: RowAction[] }>();

const BASE =
    'inline-flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-md text-muted-foreground outline-none transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring';

const tip = (a: RowAction): string => a.label ?? TABLE_ACTIONS[a.action].label;
</script>

<template>
    <div class="flex items-center justify-center gap-0.5">
        <template v-for="a in actions" :key="a.action">
            <Link v-if="a.href" :href="a.href" :title="tip(a)" :aria-label="tip(a)" :class="BASE">
                <component :is="TABLE_ACTIONS[a.action].icon" class="size-4" />
            </Link>
            <button v-else type="button" :title="tip(a)" :aria-label="tip(a)" :class="BASE" @click="a.onSelect?.()">
                <component :is="TABLE_ACTIONS[a.action].icon" class="size-4" />
            </button>
        </template>
    </div>
</template>
