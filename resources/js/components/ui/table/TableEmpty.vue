<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';
import TableCell from './TableCell.vue';
import TableRow from './TableRow.vue';

const props = withDefaults(
    defineProps<{
        class?: HTMLAttributes['class'];
        colspan?: number;
    }>(),
    { class: undefined, colspan: 1 },
);
</script>

<template>
    <TableRow class="hover:bg-transparent">
        <TableCell :class="cn('whitespace-nowrap p-4 align-middle text-sm text-foreground', props.class)" :colspan="props.colspan">
            <div class="flex items-center justify-center py-10 text-muted-foreground">
                <slot />
            </div>
        </TableCell>
    </TableRow>
</template>
