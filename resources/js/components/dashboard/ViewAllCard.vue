<script setup lang="ts">
import { formatCount } from '@/lib/format';
import { Link } from '@inertiajs/vue3';
import { ArrowRight } from 'lucide-vue-next';

/**
 * The last card of a dashboard strip: links to the full list with how many more it holds.
 */
defineProps<{ href: string; more: number }>();
</script>

<template>
    <Link
        :href="href"
        class="flex w-48 shrink-0 snap-start flex-col items-center justify-center gap-1 rounded-xl border border-dashed p-4 text-muted-foreground transition-colors hover:border-foreground hover:text-foreground"
    >
        <ArrowRight class="mb-1 size-6" />
        <span class="text-sm font-medium">View all</span>
        <span class="text-xs">+{{ formatCount(more) }} more</span>
    </Link>
</template>
