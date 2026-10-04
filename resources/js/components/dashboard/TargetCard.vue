<script setup lang="ts">
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { formatCount, formatRelative } from '@/lib/format';
import { isInProgress, STATUS_CLASSES } from '@/lib/targetStatus';
import type { DashboardTarget } from '@/types';
import { Link } from '@inertiajs/vue3';
import { CircleAlert, LoaderCircle } from 'lucide-vue-next';

defineProps<{ target: DashboardTarget }>();
</script>

<template>
    <Link
        :href="route('targets.show', { target: target.id })"
        class="flex w-64 shrink-0 snap-start flex-col gap-3 rounded-xl border bg-card p-4 transition-colors hover:bg-accent/50"
    >
        <div class="flex items-center gap-3">
            <Avatar class="size-10 shadow-sm ring-1 ring-black/10 dark:ring-white/15">
                <AvatarImage v-if="target.avatar_url" :src="target.avatar_url" :alt="target.name" />
                <AvatarFallback class="uppercase">{{ target.name.slice(0, 2) }}</AvatarFallback>
            </Avatar>
            <div class="min-w-0">
                <p class="truncate font-medium">{{ target.name }}</p>
                <p class="text-xs capitalize text-muted-foreground">{{ target.type }}</p>
            </div>
        </div>
        <div class="flex items-center justify-between gap-2">
            <Badge :class="['border-transparent capitalize', STATUS_CLASSES[target.status]]">
                <LoaderCircle v-if="isInProgress(target.status)" class="size-3 animate-spin" />
                {{ target.status }}
            </Badge>
            <span class="text-xs text-muted-foreground">{{ formatCount(target.repositories_count) }} repos</span>
        </div>
        <p class="text-xs text-muted-foreground">{{ target.last_synced_at ? `Synced ${formatRelative(target.last_synced_at)}` : 'Never synced' }}</p>
        <p v-if="target.last_error" class="flex items-start gap-1 text-xs text-red-600 dark:text-red-400" :title="target.last_error">
            <CircleAlert class="mt-0.5 size-3 shrink-0" />
            <span class="line-clamp-2">{{ target.last_error }}</span>
        </p>
    </Link>
</template>
