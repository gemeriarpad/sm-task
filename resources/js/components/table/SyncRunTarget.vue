<script setup lang="ts">
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import type { SyncRunItem } from '@/types';
import { Link } from '@inertiajs/vue3';

/**
 * The target cell of the sync history and error tables: avatar and a link to the target.
 */
defineProps<{ target: SyncRunItem['target'] }>();
</script>

<template>
    <div class="flex items-center gap-2">
        <Avatar class="size-7 shadow-sm ring-1 ring-black/10 dark:ring-white/15">
            <AvatarImage v-if="target.avatar_url" :src="target.avatar_url" :alt="target.name" />
            <AvatarFallback class="text-xs uppercase">{{ target.name.slice(0, 2) }}</AvatarFallback>
        </Avatar>
        <Link :href="route('targets.show', { target: target.id })" class="whitespace-nowrap font-medium hover:underline">{{ target.name }}</Link>
    </div>
</template>
