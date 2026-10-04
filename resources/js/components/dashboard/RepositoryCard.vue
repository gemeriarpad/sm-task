<script setup lang="ts">
import CloneBadge from '@/components/repositories/CloneBadge.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { formatCount, formatDate, formatDateTime, formatRelative } from '@/lib/format';
import type { RepositoryListItem } from '@/types';
import { Link } from '@inertiajs/vue3';
import { Archive, CircleDot, Star } from 'lucide-vue-next';

defineProps<{ repository: RepositoryListItem }>();
</script>

<template>
    <Link
        :href="route('repositories.show', { repository: repository.id })"
        :class="[
            'flex w-72 shrink-0 snap-start flex-col gap-2 rounded-xl border bg-card p-4 transition-colors hover:bg-accent/50',
            repository.missing_at && 'opacity-70',
        ]"
    >
        <div class="flex items-center gap-2">
            <Avatar v-if="repository.target" class="size-5">
                <AvatarImage v-if="repository.target.avatar_url" :src="repository.target.avatar_url" :alt="repository.target.name" />
                <AvatarFallback class="text-[10px] uppercase">{{ repository.target.name.slice(0, 2) }}</AvatarFallback>
            </Avatar>
            <p :class="['truncate text-sm font-medium', repository.missing_at && 'line-through']" :title="repository.full_name">
                {{ repository.full_name }}
            </p>
        </div>
        <p class="line-clamp-2 min-h-8 text-xs text-muted-foreground">{{ repository.description ?? 'No description' }}</p>
        <div class="mt-auto flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
            <span v-if="repository.language" class="font-medium text-foreground">{{ repository.language }}</span>
            <span class="inline-flex items-center gap-1"><Star class="size-3" />{{ formatCount(repository.stargazers_count) }}</span>
            <span class="inline-flex items-center gap-1"><CircleDot class="size-3" />{{ formatCount(repository.open_issues_count) }}</span>
            <Badge v-if="repository.archived" variant="outline" class="px-1.5"><Archive class="size-3" /></Badge>
            <span v-if="repository.missing_at" class="text-red-600 dark:text-red-400">Removed {{ formatDate(repository.missing_at) }}</span>
            <span v-else :title="`Last seen by a sync: ${formatDateTime(repository.last_seen_at)}`"
                >Synced {{ formatRelative(repository.last_seen_at) }}</span
            >
        </div>
        <CloneBadge :clone="repository.clone" class="text-xs" />
    </Link>
</template>
