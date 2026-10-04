<script setup lang="ts">
import RepositoryCard from '@/components/dashboard/RepositoryCard.vue';
import SyncScheduleBar from '@/components/dashboard/SyncScheduleBar.vue';
import TargetCard from '@/components/dashboard/TargetCard.vue';
import ViewAllCard from '@/components/dashboard/ViewAllCard.vue';
import { SelectField } from '@/components/table/parts';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { useLiveSync } from '@/composables/useLiveSync';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount, formatDuration, formatRelative } from '@/lib/format';
import { RUN_STATUS_CLASSES, STATUS_CLASSES } from '@/lib/targetStatus';
import type {
    BreadcrumbItem,
    DashboardStats,
    DashboardTarget,
    Option,
    QueueHealthInfo,
    RepositoryListItem,
    SyncRunItem,
    SyncScheduleInfo,
    TargetStatus,
} from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, FolderGit2, History, LoaderCircle, Plus, Radio, Target, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage<{
    stats: DashboardStats;
    schedule: SyncScheduleInfo;
    queue: QueueHealthInfo;
    targetStrip: string;
    targetStrips: Option[];
    targets: DashboardTarget[];
    targetsTotal: number;
    targetsUrl: string;
    strip: string;
    strips: Option[];
    repositoryStrip: RepositoryListItem[];
    repositoryStripTotal: number;
    repositoryStripUrl: string;
    latestErrors: SyncRunItem[];
    history: SyncRunItem[];
    syncing: boolean;
}>();
const stats = computed(() => page.props.stats);
const moreRepositories = computed(() => page.props.repositoryStripTotal - page.props.repositoryStrip.length);
const moreTargets = computed(() => page.props.targetsTotal - page.props.targets.length);

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

// Everything on the dashboard reflects sync state, so reload it all on updates.
const { connected } = useLiveSync({
    only: ['stats', 'schedule', 'queue', 'targets', 'targetsTotal', 'repositoryStrip', 'repositoryStripTotal', 'latestErrors', 'history', 'syncing'],
    pollWhile: () => page.props.syncing,
    events: ['.target.sync.updated', '.repository.clone.updated'],
});

const STATUS_ORDER: TargetStatus[] = ['synced', 'syncing', 'queued', 'failed', 'inactive'];

/** What the cloning step of a run did, e.g. "3 cloned · 1 clone error". */
function cloneSummary(run: SyncRunItem): { text: string; failed: boolean } | null {
    const parts = [
        run.clones_count ? `${formatCount(run.clones_count)} cloned` : null,
        run.clone_errors_count ? `${formatCount(run.clone_errors_count)} clone ${run.clone_errors_count === 1 ? 'error' : 'errors'}` : null,
        run.clones_deferred_count ? `${formatCount(run.clones_deferred_count)} left for the next sync` : null,
    ].filter(Boolean);

    return parts.length > 0 ? { text: parts.join(' · '), failed: !!run.clone_errors_count } : null;
}

/** Switch a strip, keeping the other strip's mode in the URL. */
function setStrip(params: { strip?: string; target_strip?: string }, only: string[]): void {
    router.get(
        route('dashboard'),
        { strip: page.props.strip, target_strip: page.props.targetStrip, ...params },
        {
            only,
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex min-w-0 flex-1 flex-col gap-8 p-4">
            <!-- Stats -->
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border bg-card p-4">
                    <div class="flex items-center gap-2 text-sm text-muted-foreground"><Target class="size-4" /> Targets</div>
                    <p class="mt-1 text-3xl font-semibold">{{ formatCount(stats.targets) }}</p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <div class="flex items-center gap-2 text-sm text-muted-foreground"><FolderGit2 class="size-4" /> Repositories</div>
                    <p class="mt-1 text-3xl font-semibold">{{ formatCount(stats.repositories) }}</p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <div class="flex items-center gap-2 text-sm text-muted-foreground"><Trash2 class="size-4" /> Removed from GitHub</div>
                    <p class="mt-1 text-3xl font-semibold">{{ formatCount(stats.missing) }}</p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <div class="flex items-center justify-between gap-2 text-sm text-muted-foreground">
                        <span>Sync status</span>
                        <span
                            class="inline-flex items-center gap-1 text-xs"
                            :title="connected ? 'Live updates via Reverb' : 'No live connection: polling while syncs run'"
                        >
                            <Radio :class="['size-3', connected ? 'text-emerald-500' : 'text-muted-foreground']" />
                            {{ connected ? 'Live' : 'Polling' }}
                        </span>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-1.5">
                        <Badge v-for="status in STATUS_ORDER" :key="status" :class="['border-transparent capitalize', STATUS_CLASSES[status]]">
                            {{ status }} {{ stats.statuses[status] }}
                        </Badge>
                    </div>
                </div>
            </div>

            <SyncScheduleBar
                :schedule="page.props.schedule"
                :queue="page.props.queue"
                :active-targets="stats.targets - stats.statuses.inactive"
                :syncing="page.props.syncing"
            />

            <!-- Targets strip -->
            <section class="min-w-0 space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <h2 class="text-lg font-semibold">Targets</h2>
                        <SelectField
                            :model-value="page.props.targetStrip"
                            :options="page.props.targetStrips"
                            size="sm"
                            class="w-44"
                            @update:model-value="
                                (mode: string) => setStrip({ target_strip: mode }, ['targetStrip', 'targets', 'targetsTotal', 'targetsUrl'])
                            "
                        />
                    </div>
                    <Link :href="route('targets.index')" class="text-sm text-muted-foreground hover:text-foreground">View all</Link>
                </div>
                <div class="scrollbar-thin flex snap-x gap-4 overflow-x-auto pb-2">
                    <Link
                        :href="route('targets.create')"
                        class="flex w-48 shrink-0 snap-start flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-4 text-muted-foreground transition-colors hover:border-foreground hover:text-foreground"
                    >
                        <Plus class="size-6" />
                        <span class="text-sm font-medium">New target</span>
                    </Link>
                    <TargetCard v-for="target in page.props.targets" :key="target.id" :target="target" />
                    <ViewAllCard v-if="moreTargets > 0" :href="page.props.targetsUrl" :more="moreTargets" />
                    <p
                        v-if="page.props.targets.length === 0 && page.props.targetStrip === 'failed'"
                        class="flex w-48 shrink-0 items-center justify-center gap-2 rounded-xl border border-dashed p-4 text-sm text-muted-foreground"
                    >
                        <CircleCheck class="size-4 text-emerald-500" /> No failed targets.
                    </p>
                </div>
            </section>

            <!-- Repository strip -->
            <section class="min-w-0 space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <h2 class="text-lg font-semibold">Repositories</h2>
                        <SelectField
                            :model-value="page.props.strip"
                            :options="page.props.strips"
                            size="sm"
                            class="w-44"
                            @update:model-value="
                                (mode: string) =>
                                    setStrip({ strip: mode }, ['strip', 'repositoryStrip', 'repositoryStripTotal', 'repositoryStripUrl'])
                            "
                        />
                    </div>
                    <Link :href="route('repositories.index')" class="text-sm text-muted-foreground hover:text-foreground">View all</Link>
                </div>
                <div v-if="page.props.repositoryStrip.length > 0" class="scrollbar-thin flex snap-x gap-4 overflow-x-auto pb-2">
                    <RepositoryCard v-for="repository in page.props.repositoryStrip" :key="repository.id" :repository="repository" />
                    <ViewAllCard v-if="moreRepositories > 0" :href="page.props.repositoryStripUrl" :more="moreRepositories" />
                </div>
                <p v-else class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">Nothing to show here yet.</p>
            </section>

            <!-- Latest errors and sync history -->
            <div class="grid gap-6 lg:grid-cols-2">
                <section class="space-y-3">
                    <div class="flex items-center justify-between gap-4">
                        <h2 class="flex items-center gap-2 text-lg font-semibold"><CircleAlert class="size-5" /> Latest errors</h2>
                        <Link :href="route('sync-runs.errors')" class="text-sm text-muted-foreground hover:text-foreground">View all</Link>
                    </div>
                    <ul v-if="page.props.latestErrors.length > 0" class="divide-y rounded-xl border bg-card">
                        <li v-for="run in page.props.latestErrors" :key="run.id" class="flex gap-3 p-3">
                            <Avatar class="size-7">
                                <AvatarImage v-if="run.target.avatar_url" :src="run.target.avatar_url" :alt="run.target.name" />
                                <AvatarFallback class="text-xs uppercase">{{ run.target.name.slice(0, 2) }}</AvatarFallback>
                            </Avatar>
                            <div class="min-w-0 flex-1 space-y-0.5">
                                <div class="flex flex-wrap items-center gap-2 text-sm">
                                    <Link :href="route('targets.show', { target: run.target.id })" class="font-medium hover:underline">{{
                                        run.target.name
                                    }}</Link>
                                    <span class="text-xs text-muted-foreground"
                                        >attempt {{ run.attempt }} · {{ formatRelative(run.started_at) }}</span
                                    >
                                    <Badge v-if="run.will_retry" variant="outline" class="text-xs">retrying</Badge>
                                </div>
                                <p class="break-words text-sm text-red-600 dark:text-red-400">{{ run.error }}</p>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="flex items-center justify-center gap-2 rounded-xl border border-dashed p-6 text-sm text-muted-foreground">
                        <CircleCheck class="size-4 text-emerald-500" /> No sync errors.
                    </p>
                </section>

                <section class="space-y-3">
                    <div class="flex items-center justify-between gap-4">
                        <h2 class="flex items-center gap-2 text-lg font-semibold"><History class="size-5" /> Sync history</h2>
                        <Link :href="route('sync-runs.index')" class="text-sm text-muted-foreground hover:text-foreground">View all</Link>
                    </div>
                    <ul v-if="page.props.history.length > 0" class="divide-y rounded-xl border bg-card">
                        <li v-for="run in page.props.history" :key="run.id" class="flex items-center gap-3 p-3 text-sm">
                            <Badge :class="['w-24 justify-center border-transparent capitalize', RUN_STATUS_CLASSES[run.status]]">
                                <LoaderCircle v-if="run.status === 'running'" class="size-3 animate-spin" />
                                {{ run.status }}
                            </Badge>
                            <div class="min-w-0 flex-1">
                                <Link :href="route('targets.show', { target: run.target.id })" class="block truncate font-medium hover:underline">
                                    {{ run.target.name }}
                                </Link>
                                <p
                                    v-if="cloneSummary(run)"
                                    :class="[
                                        'truncate text-xs',
                                        cloneSummary(run)!.failed ? 'text-red-600 dark:text-red-400' : 'text-muted-foreground',
                                    ]"
                                >
                                    {{ cloneSummary(run)!.text }}
                                </p>
                            </div>
                            <span class="hidden text-xs text-muted-foreground sm:inline">attempt {{ run.attempt }}</span>
                            <span class="w-20 text-right text-xs text-muted-foreground">
                                {{ run.repositories_count !== null ? `${formatCount(run.repositories_count)} repos` : '—' }}
                            </span>
                            <span class="w-16 text-right text-xs text-muted-foreground">{{ formatDuration(run.duration_ms) }}</span>
                            <span class="w-28 text-right text-xs text-muted-foreground">{{ formatRelative(run.started_at) }}</span>
                        </li>
                    </ul>
                    <p v-else class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">No syncs have run yet.</p>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
