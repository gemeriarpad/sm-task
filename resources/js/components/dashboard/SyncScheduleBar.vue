<script setup lang="ts">
import QueueStatus from '@/components/dashboard/QueueStatus.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { formatCount, formatDateTime, formatRelative } from '@/lib/format';
import type { QueueHealthInfo, SyncScheduleInfo } from '@/types';
import { router, useForm } from '@inertiajs/vue3';
import { CalendarClock, CircleAlert, LoaderCircle, RefreshCw, TriangleAlert } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref } from 'vue';

/**
 * When the scheduled resync of all targets runs next and whether the queue workers
 * that run it are up, plus a button to sync them all right away.
 */
const props = defineProps<{ schedule: SyncScheduleInfo; queue: QueueHealthInfo; activeTargets: number; syncing: boolean }>();

const open = ref(false);
const form = useForm({});

function submit(): void {
    form.post(route('targets.sync-all'), { preserveScroll: true, onSuccess: () => (open.value = false) });
}

// Keep "in 12 minutes" current, and fetch the following run time once this one has passed.
const now = ref(Date.now());
let timer: number | undefined;
let reloadedFor: string | null = null;

onMounted(() => {
    timer = window.setInterval(() => {
        now.value = Date.now();
        const next = props.schedule.next_run_at;

        if (next && next !== reloadedFor && now.value > Date.parse(next)) {
            reloadedFor = next;
            router.reload({ only: ['schedule'] });
        }
    }, 15_000);
});
onUnmounted(() => window.clearInterval(timer));

const nextRunIn = computed(() => {
    void now.value;

    return formatRelative(props.schedule.next_run_at);
});
const lastSeen = computed(() => {
    void now.value;

    return props.schedule.last_heartbeat_at ? `Last ran ${formatRelative(props.schedule.last_heartbeat_at)}.` : 'It has never run.';
});
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3 rounded-xl border bg-card px-4 py-3">
        <div class="space-y-2">
            <div v-if="schedule.next_run_at === null" class="flex items-center gap-2 text-sm text-red-600 dark:text-red-400">
                <CircleAlert class="size-4 shrink-0" />
                <span
                    >No scheduled sync: <code class="rounded bg-muted px-1 py-0.5 text-xs">GITHUB_SYNC_SCHEDULE="{{ schedule.expression }}"</code> is
                    not a valid cron expression.</span
                >
            </div>
            <div v-else-if="!schedule.scheduler_running" class="flex items-start gap-2 text-sm">
                <TriangleAlert class="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400" />
                <div>
                    <p class="font-medium text-amber-700 dark:text-amber-400">Scheduler not running</p>
                    <p class="text-muted-foreground">
                        {{ lastSeen }} Scheduled syncs ({{ schedule.description ?? schedule.expression }}) won't happen until it is started with
                        <code class="rounded bg-muted px-1 py-0.5 text-xs">php artisan schedule:work</code>.
                    </p>
                </div>
            </div>
            <div v-else class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                <CalendarClock class="size-4 shrink-0 text-muted-foreground" />
                <span>
                    Next scheduled sync <span class="font-medium">{{ nextRunIn }}</span>
                </span>
                <span class="text-muted-foreground">
                    {{ formatDateTime(schedule.next_run_at) }}
                    <span :title="schedule.expression">· {{ schedule.description ?? schedule.expression }}</span>
                </span>
            </div>
            <QueueStatus :queue="queue" />
        </div>

        <Button variant="outline" size="sm" :disabled="activeTargets === 0" @click="open = true">
            <LoaderCircle v-if="syncing" class="size-4 animate-spin" />
            <RefreshCw v-else class="size-4" />
            Sync all now
        </Button>
    </div>

    <Dialog v-model:open="open">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Sync all targets now?</DialogTitle>
                <DialogDescription>
                    Queues a sync for {{ formatCount(activeTargets) }} active {{ activeTargets === 1 ? 'target' : 'targets' }}. Targets that are
                    already syncing are skipped and inactive targets are left out.
                </DialogDescription>
            </DialogHeader>

            <DialogFooter class="gap-2">
                <Button variant="ghost" @click="open = false">Cancel</Button>
                <Button :disabled="form.processing" @click="submit">
                    <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                    <RefreshCw v-else class="size-4" />
                    Sync all
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
