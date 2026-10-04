<script setup lang="ts">
import RepositoryList from '@/components/repositories/RepositoryList.vue';
import DeactivateTargetDialog from '@/components/targets/DeactivateTargetDialog.vue';
import DeleteClonesDialog from '@/components/targets/DeleteClonesDialog.vue';
import DeleteTargetDialog from '@/components/targets/DeleteTargetDialog.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useLiveSync } from '@/composables/useLiveSync';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount, formatDate, formatDateTime } from '@/lib/format';
import { isInProgress, STATUS_CLASSES } from '@/lib/targetStatus';
import type { BreadcrumbItem, Paginated, RepositoryListItem, RepositoryListOptions, RepositoryQuery, TargetDetail, TargetOptions } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    CircleAlert,
    Ellipsis,
    ExternalLink,
    HardDrive,
    History,
    LoaderCircle,
    Power,
    PowerOff,
    RefreshCw,
    SquarePen,
    Trash2,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

const page = usePage<{
    target: TargetDetail;
    repositories: Paginated<RepositoryListItem>;
    query: RepositoryQuery;
    options: RepositoryListOptions;
}>();
// Reactive: re-reads on each (partial) Inertia visit, including live reloads.
const target = computed(() => page.props.target);

const typeLabels: TargetOptions['types'] = [
    { value: 'user', label: 'User' },
    { value: 'organization', label: 'Organization' },
];
const statusLabel = computed(() => target.value.status.charAt(0).toUpperCase() + target.value.status.slice(1));

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Targets', href: route('targets.index') },
    { title: target.value.name, href: route('targets.show', { target: target.value.id }) },
]);

const syncing = computed(() => isInProgress(target.value.status));
const inactive = computed(() => target.value.status === 'inactive');

// Refresh the target and its repositories on sync and clone updates (Reverb), or
// poll while a sync is running when there is no live connection.
useLiveSync({
    only: ['target', 'repositories', 'options'],
    pollWhile: syncing,
    events: ['.target.sync.updated', '.repository.clone.updated'],
    filter: (payload) => payload.target_id === target.value.id,
});

function syncNow(): void {
    router.post(route('targets.sync', { target: target.value.id }), {}, { preserveScroll: true });
}

function reactivate(): void {
    router.post(route('targets.reactivate', { target: target.value.id }), {}, { preserveScroll: true });
}

const dialog = ref<'deactivate' | 'delete' | 'clones' | null>(null);
const dialogOpen = (name: NonNullable<typeof dialog.value>) =>
    computed({ get: () => dialog.value === name, set: (open: boolean) => (dialog.value = open ? name : null) });
const deactivateOpen = dialogOpen('deactivate');
const deleteOpen = dialogOpen('delete');
const clonesOpen = dialogOpen('clones');

// Removing data while the sync job writes it is refused by the server too.
const busyHint = computed(() => (target.value.status === 'syncing' ? 'Wait for the running sync to finish' : undefined));
</script>

<template>
    <Head :title="target.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex w-full flex-col gap-6 p-4">
            <!-- Target header -->
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-center gap-4">
                    <Avatar class="size-14 shadow-sm ring-1 ring-black/10 dark:ring-white/15">
                        <AvatarImage v-if="target.avatar_url" :src="target.avatar_url" :alt="target.name" />
                        <AvatarFallback class="uppercase">{{ target.name.slice(0, 2) }}</AvatarFallback>
                    </Avatar>
                    <div class="space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-xl font-semibold tracking-tight">{{ target.name }}</h2>
                            <Badge variant="outline">{{ typeLabels.find((t) => t.value === target.type)?.label }}</Badge>
                            <Badge :class="['border-transparent', STATUS_CLASSES[target.status]]">
                                <LoaderCircle v-if="syncing" class="size-3 animate-spin" />
                                {{ statusLabel }}
                            </Badge>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            Last synced: {{ formatDateTime(target.last_synced_at) }} · {{ formatCount(target.repositories_count) }}
                            {{ target.repositories_count === 1 ? 'repository' : 'repositories' }}
                            <template v-if="target.missing_count > 0">({{ formatCount(target.missing_count) }} removed from GitHub)</template>
                            <template v-if="target.excluded_count > 0">· {{ formatCount(target.excluded_count) }} excluded</template>
                            · {{ target.repository_scope === 'all' ? 'incl. forks' : 'sources only' }} · cloning
                            {{ target.clone_repositories ? 'on' : 'off' }}
                            <template v-if="target.clones_enabled_count > 0">
                                ({{ formatCount(target.clones_cloned_count) }} / {{ formatCount(target.clones_enabled_count) }} cloned)
                            </template>
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Button variant="outline" as-child>
                        <a :href="`https://github.com/${target.name}`" target="_blank" rel="noopener noreferrer">
                            <ExternalLink class="size-4" />
                            GitHub
                        </a>
                    </Button>
                    <Button variant="outline" as-child>
                        <Link :href="route('sync-runs.index', { filter: { target: target.id } })">
                            <History class="size-4" />
                            History
                        </Link>
                    </Button>
                    <Button variant="outline" as-child>
                        <Link :href="route('targets.edit', { target: target.id })">
                            <SquarePen class="size-4" />
                            Edit
                        </Link>
                    </Button>
                    <Button v-if="inactive" @click="reactivate">
                        <Power class="size-4" />
                        Reactivate
                    </Button>
                    <Button v-else :disabled="syncing" @click="syncNow">
                        <LoaderCircle v-if="syncing" class="size-4 animate-spin" />
                        <RefreshCw v-else class="size-4" />
                        {{ syncing ? 'Syncing…' : 'Sync now' }}
                    </Button>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button variant="outline" size="icon" aria-label="More actions">
                                <Ellipsis class="size-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-52">
                            <DropdownMenuItem v-if="!inactive" :disabled="!!busyHint" :title="busyHint" @select="dialog = 'deactivate'">
                                <PowerOff class="size-4" />
                                Deactivate…
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                :disabled="!!busyHint || target.local_clones_count === 0"
                                :title="busyHint ?? (target.local_clones_count === 0 ? 'Nothing is cloned' : undefined)"
                                @select="dialog = 'clones'"
                            >
                                <HardDrive class="size-4" />
                                Delete all clones…
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                :disabled="!!busyHint"
                                :title="busyHint"
                                class="text-red-600 focus:text-red-600 dark:text-red-400"
                                @select="dialog = 'delete'"
                            >
                                <Trash2 class="size-4" />
                                Delete target…
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>

            <!-- Deactivated -->
            <div v-if="inactive" class="flex items-start gap-2 rounded-xl border bg-muted/40 p-3 text-sm text-muted-foreground">
                <PowerOff class="mt-0.5 size-4 shrink-0" />
                Inactive since {{ formatDate(target.deactivated_at) }}: not synced (by the schedule or by hand) and nothing is cloned. The kept data
                stays browsable. Reactivate to sync again.
            </div>

            <!-- Most recent error -->
            <div
                v-if="target.last_error"
                class="flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-300"
            >
                <CircleAlert class="mt-0.5 size-4 shrink-0" />
                <div class="flex-1">
                    <p>{{ target.last_error }}</p>
                    <p v-if="target.last_failed_at" class="text-xs opacity-75">{{ formatDateTime(target.last_failed_at) }}</p>
                </div>
                <Link :href="route('sync-runs.errors', { filter: { target: target.id } })" class="shrink-0 text-xs font-medium hover:underline">
                    All errors
                </Link>
            </div>

            <RepositoryList
                :repositories="page.props.repositories"
                :query="page.props.query"
                :options="page.props.options"
                :url="route('targets.show', { target: target.id })"
                :has-any="target.repositories_count + target.missing_count + target.excluded_count > 0"
            >
                <template #empty>
                    <LoaderCircle v-if="syncing" class="mb-2 size-5 animate-spin" />
                    {{ syncing ? 'Fetching repositories from GitHub…' : 'No repositories synced yet.' }}
                </template>
            </RepositoryList>
        </div>

        <DeactivateTargetDialog v-model:open="deactivateOpen" :target="target" />
        <DeleteClonesDialog v-model:open="clonesOpen" :target="target" />
        <DeleteTargetDialog v-model:open="deleteOpen" :target="target" />
    </AppLayout>
</template>
