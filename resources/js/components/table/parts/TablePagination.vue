<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import type { Pagination } from '@/types';
import { ChevronDown, ChevronLeft, ChevronRight } from 'lucide-vue-next';
import type { HTMLAttributes } from 'vue';
import { computed } from 'vue';

/**
 * Rows-per-page picker + page navigator for a server-paginated table. Purely
 * presentational: it reads Laravel paginator meta and emits the requested page
 * / page-size, leaving the actual fetch to the parent. The wrapper `class`
 * lets a host place it inside a card footer (`border-t px-4 py-3`) or bare.
 */
const props = withDefaults(
    defineProps<{
        pagination: Pagination;
        perPageOptions?: number[];
        class?: HTMLAttributes['class'];
    }>(),
    { perPageOptions: () => [10, 25, 50, 100], class: undefined },
);

const emit = defineEmits<{
    'update:page': [page: number];
    'update:perPage': [perPage: number];
}>();

const canPrev = computed(() => props.pagination.current_page > 1);
const canNext = computed(() => props.pagination.current_page < props.pagination.last_page);
</script>

<template>
    <div :class="cn('flex flex-wrap items-center justify-between gap-3 text-sm', props.class)">
        <div class="flex items-center gap-2 text-muted-foreground">
            <span>Rows per page</span>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button variant="outline" size="sm">
                        {{ pagination.per_page }}
                        <ChevronDown class="size-3.5" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start">
                    <DropdownMenuItem v-for="n in perPageOptions" :key="n" @select="emit('update:perPage', n)">
                        {{ n }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <span class="ml-1">
                {{ pagination.from ?? 0 }}–{{ pagination.to ?? 0 }}
                of
                {{ pagination.total }}
            </span>
        </div>

        <div class="flex items-center gap-1">
            <Button
                variant="outline"
                size="icon-sm"
                :disabled="!canPrev"
                :aria-label="'Previous page'"
                @click="emit('update:page', pagination.current_page - 1)"
            >
                <ChevronLeft class="size-4" />
            </Button>
            <span class="px-2 text-muted-foreground">
                Page {{ pagination.current_page }} /
                {{ pagination.last_page }}
            </span>
            <Button
                variant="outline"
                size="icon-sm"
                :disabled="!canNext"
                :aria-label="'Next page'"
                @click="emit('update:page', pagination.current_page + 1)"
            >
                <ChevronRight class="size-4" />
            </Button>
        </div>
    </div>
</template>
