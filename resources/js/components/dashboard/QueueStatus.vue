<script setup lang="ts">
import { formatCount, formatRelative } from '@/lib/format';
import type { QueueHealthInfo } from '@/types';
import { Link } from '@inertiajs/vue3';
import { CircleAlert, Layers, TriangleAlert } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * One line about the queue workers: running, busy with a long job, or not running
 * (then queued syncs never run), plus failed jobs of the last 24 hours.
 */
const props = defineProps<{ queue: QueueHealthInfo }>();

const waiting = computed(() => {
    const { pending_jobs: pending, oldest_pending_job_at: oldest } = props.queue;

    if (pending === 0) {
        return null;
    }

    return `${formatCount(pending)} ${pending === 1 ? 'job' : 'jobs'} waiting${oldest ? ` (oldest queued ${formatRelative(oldest)})` : ''}`;
});
</script>

<template>
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
        <template v-if="queue.worker === 'stopped'">
            <TriangleAlert class="size-4 shrink-0 text-amber-600 dark:text-amber-400" />
            <span class="font-medium text-amber-700 dark:text-amber-400">Queue worker not running</span>
            <span class="text-muted-foreground">
                Queued syncs won't run until one is started with <code class="rounded bg-muted px-1 py-0.5 text-xs">php artisan queue:work</code>.
            </span>
        </template>
        <template v-else>
            <Layers class="size-4 shrink-0 text-muted-foreground" />
            <span v-if="queue.worker === 'busy'">Queue worker busy with a long job</span>
            <span v-else>Queue worker running</span>
            <span v-if="queue.worker === 'running' && queue.delay_seconds !== null && queue.delay_seconds > 5" class="text-muted-foreground">
                · jobs wait {{ queue.delay_seconds }} s
            </span>
        </template>
        <span v-if="waiting" class="text-muted-foreground">· {{ waiting }}</span>
        <Link
            v-if="queue.failed_jobs_24h > 0"
            :href="route('failed-jobs.index')"
            class="inline-flex items-center gap-1 text-red-600 hover:underline dark:text-red-400"
        >
            <CircleAlert class="size-3.5" />
            {{ formatCount(queue.failed_jobs_24h) }} failed {{ queue.failed_jobs_24h === 1 ? 'job' : 'jobs' }} in the last 24 h
        </Link>
    </div>
</template>
