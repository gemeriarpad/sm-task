<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import BranchSelect from '@/components/repositories/BranchSelect.vue';
import DeleteCloneDialog from '@/components/repositories/DeleteCloneDialog.vue';
import FileTree from '@/components/repositories/FileTree.vue';
import { SelectField } from '@/components/table/parts';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useLiveSync } from '@/composables/useLiveSync';
import AppLayout from '@/layouts/AppLayout.vue';
import { CLONE_STATUS_CLASSES, CLONE_STATUS_LABELS } from '@/lib/cloneStatus';
import { formatCount, formatDate, formatDateTime, formatRelative, formatSize } from '@/lib/format';
import { isInProgress } from '@/lib/targetStatus';
import type { BreadcrumbItem, FilePreview, Option, RepositoryClone, RepositoryListItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    Archive,
    CircleAlert,
    CircleDot,
    Download,
    ExternalLink,
    Eye,
    EyeOff,
    FileText,
    GitFork,
    HardDrive,
    LoaderCircle,
    PowerOff,
    RefreshCw,
    Star,
    Trash2,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const page = usePage<{
    repository: RepositoryListItem & {
        last_seen_at: string | null;
        created_at: string | null;
        size_kb: number | null;
        default_branch: string | null;
    };
    clone: RepositoryClone;
    cloneModes: Option[];
}>();
// Reactive: re-reads on each (partial) Inertia visit, including live reloads.
const repository = computed(() => page.props.repository);
const clone = computed(() => page.props.clone);
const target = computed(() => repository.value.target!);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Targets', href: route('targets.index') },
    { title: target.value.name, href: route('targets.show', { target: target.value.id }) },
    { title: repository.value.name, href: route('repositories.show', { repository: repository.value.id }) },
]);

const stats = computed(() => [
    { label: 'Stars', value: formatCount(repository.value.stargazers_count), icon: Star },
    { label: 'Forks', value: formatCount(repository.value.forks_count), icon: GitFork },
    { label: 'Open issues', value: formatCount(repository.value.open_issues_count), icon: CircleDot, hint: 'GitHub includes open pull requests' },
    { label: 'Size on GitHub', value: formatSize(repository.value.size_kb), icon: HardDrive },
]);

// --- Cloning ----------------------------------------------------------------------

// Cloning runs as part of the target's sync.
const targetSyncing = computed(() => isInProgress(clone.value.target_status));
const targetInactive = computed(() => clone.value.target_status === 'inactive');
const cloneBusy = computed(() => clone.value.status === 'cloning' || (clone.value.status === 'queued' && targetSyncing.value));

// Reload when this repository's clone or its target's sync changes (Reverb), or
// poll while the target syncs.
useLiveSync({
    only: ['repository', 'clone'],
    pollWhile: () => cloneBusy.value || targetSyncing.value,
    events: ['.repository.clone.updated', '.target.sync.updated'],
    filter: (payload) =>
        payload.repository_id !== undefined ? payload.repository_id === repository.value.id : payload.target_id === target.value.id,
});

const modeOptions = computed<Option[]>(() =>
    page.props.cloneModes.map((mode) =>
        mode.value === 'inherit' ? { ...mode, label: `${mode.label} (${clone.value.target_clones ? 'on' : 'off'})` } : mode,
    ),
);

function toggleExcluded(): void {
    const url = route(repository.value.excluded_at ? 'repositories.include' : 'repositories.exclude', { repository: repository.value.id });

    if (repository.value.excluded_at) {
        router.delete(url, { preserveScroll: true });
    } else {
        router.post(url, {}, { preserveScroll: true });
    }
}

function setMode(mode: string): void {
    router.patch(route('repositories.clone.update', { repository: repository.value.id }), { mode }, { preserveScroll: true });
}

function setBranch(branch: string | null): void {
    router.patch(route('repositories.clone.update', { repository: repository.value.id }), { branch }, { preserveScroll: true });
}

function cloneNow(): void {
    router.post(route('repositories.clone.refresh', { repository: repository.value.id }), {}, { preserveScroll: true });
}

const canCloneNow = computed(
    () => clone.value.enabled && !cloneBusy.value && !repository.value.missing_at && !repository.value.excluded_at && !targetInactive.value,
);

const deletingClone = ref(false);

// --- File preview -----------------------------------------------------------------

const selected = ref<string | null>(null);
const preview = ref<FilePreview | null>(null);
const previewState = ref<'idle' | 'loading' | 'error'>('idle');

async function selectFile(path: string): Promise<void> {
    selected.value = path;
    previewState.value = 'loading';

    try {
        const response = await fetch(route('repositories.file', { repository: repository.value.id, path }), {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        preview.value = await response.json();
        previewState.value = 'idle';
    } catch {
        preview.value = null;
        previewState.value = 'error';
    }
}

// A new clone/update can change every file: start the browser over.
watch(
    () => clone.value.commit,
    () => {
        selected.value = null;
        preview.value = null;
    },
);
</script>

<template>
    <Head :title="repository.full_name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex w-full flex-col gap-6 p-4">
            <!-- Header -->
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <Link
                            :href="route('targets.show', { target: target.id })"
                            class="inline-flex items-center gap-2 text-muted-foreground hover:text-foreground"
                        >
                            <Avatar class="size-6">
                                <AvatarImage v-if="target.avatar_url" :src="target.avatar_url" :alt="target.name" />
                                <AvatarFallback class="text-[10px] uppercase">{{ target.name.slice(0, 2) }}</AvatarFallback>
                            </Avatar>
                            {{ target.name }}
                        </Link>
                        <span class="text-muted-foreground">/</span>
                        <h2 class="text-xl font-semibold tracking-tight">{{ repository.name }}</h2>
                        <Badge v-if="repository.fork" variant="outline">
                            <GitFork class="size-3" />
                            Fork
                        </Badge>
                        <Badge
                            v-if="repository.archived"
                            class="border-transparent bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300"
                        >
                            <Archive class="size-3" />
                            Archived
                        </Badge>
                        <Badge v-if="repository.missing_at" class="border-transparent bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300">
                            Removed from GitHub {{ formatDate(repository.missing_at) }}
                        </Badge>
                    </div>
                    <p v-if="repository.description" class="max-w-3xl text-sm text-muted-foreground">{{ repository.description }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <Button variant="outline" @click="toggleExcluded">
                        <Eye v-if="repository.excluded_at" class="size-4" />
                        <EyeOff v-else class="size-4" />
                        {{ repository.excluded_at ? 'Include again' : 'Exclude' }}
                    </Button>
                    <Button variant="outline" as-child>
                        <a :href="repository.html_url" target="_blank" rel="noopener noreferrer">
                            <ExternalLink class="size-4" />
                            GitHub
                        </a>
                    </Button>
                </div>
            </div>

            <div v-if="repository.excluded_at" class="flex items-start gap-2 rounded-xl border bg-muted/40 p-3 text-sm text-muted-foreground">
                <EyeOff class="mt-0.5 size-4 shrink-0" />
                Excluded {{ formatDate(repository.excluded_at) }}: hidden from lists and the dashboard, no longer updated by syncs and never cloned.
                An existing clone is kept.
            </div>

            <!-- Metadata -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div v-for="stat in stats" :key="stat.label" class="rounded-xl border bg-card p-4" :title="stat.hint">
                    <div class="flex items-center gap-2 text-sm text-muted-foreground">
                        <component :is="stat.icon" class="size-4" />
                        {{ stat.label }}
                    </div>
                    <p class="mt-1 text-2xl font-semibold">{{ stat.value }}</p>
                </div>
            </div>

            <dl class="grid gap-x-8 gap-y-3 rounded-xl border bg-card p-4 text-sm sm:grid-cols-2">
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Language</dt>
                    <dd>{{ repository.language ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Last pushed</dt>
                    <dd>{{ formatDateTime(repository.pushed_at, '—') }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Updated on GitHub</dt>
                    <dd>{{ formatDateTime(repository.github_updated_at, '—') }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Last seen by a sync</dt>
                    <dd>{{ formatDateTime(repository.last_seen_at, '—') }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">First synced</dt>
                    <dd>{{ formatDateTime(repository.created_at, '—') }}</dd>
                </div>
            </dl>

            <!-- Local clone -->
            <section class="space-y-4 rounded-xl border bg-card p-4">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex flex-wrap items-center gap-3">
                        <h3 class="font-semibold">Local clone</h3>
                        <Badge v-if="clone.status" :class="['border-transparent', CLONE_STATUS_CLASSES[clone.status]]">
                            <LoaderCircle v-if="cloneBusy" class="size-3 animate-spin" />
                            {{ CLONE_STATUS_LABELS[clone.status] }}
                        </Badge>
                        <Badge v-else variant="outline">Not cloned</Badge>
                        <span v-if="clone.commit" class="font-mono text-xs text-muted-foreground" :title="clone.commit">
                            {{ clone.cloned_branch ?? repository.default_branch ?? 'HEAD' }} @ {{ clone.commit.slice(0, 7) }}
                        </span>
                        <span v-if="clone.cloned_at" class="text-xs text-muted-foreground">updated {{ formatRelative(clone.cloned_at) }}</span>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <SelectField :model-value="clone.mode" :options="modeOptions" class="h-9 w-56" @update:model-value="setMode" />
                        <BranchSelect
                            :repository-id="repository.id"
                            :model-value="clone.branch"
                            :default-branch="repository.default_branch"
                            :disabled="!!repository.missing_at"
                            @update:model-value="setBranch"
                        />
                        <Button
                            variant="outline"
                            :disabled="!canCloneNow"
                            :title="`Runs a sync of ${target.name}, which ${clone.available ? 'updates' : 'clones'} this repository`"
                            @click="cloneNow"
                        >
                            <LoaderCircle v-if="cloneBusy" class="size-4 animate-spin" />
                            <RefreshCw v-else class="size-4" />
                            {{ clone.available ? 'Update now' : 'Clone now' }}
                        </Button>
                        <Button v-if="clone.available" as-child>
                            <a :href="route('repositories.download', { repository: repository.id })">
                                <Download class="size-4" />
                                Download zip
                            </a>
                        </Button>
                        <Button
                            v-if="clone.available"
                            variant="outline"
                            class="text-red-600 hover:text-red-700 dark:text-red-400"
                            :disabled="cloneBusy || targetSyncing"
                            :title="cloneBusy || targetSyncing ? `Wait for the sync of ${target.name} to finish` : undefined"
                            @click="deletingClone = true"
                        >
                            <Trash2 class="size-4" />
                            Delete clone
                        </Button>
                    </div>
                </div>
                <InputError :message="page.props.errors?.branch ?? page.props.errors?.mode" />
                <p v-if="targetInactive" class="flex items-center gap-2 text-sm text-muted-foreground">
                    <PowerOff class="size-4 shrink-0" />
                    {{ target.name }} is inactive: nothing is cloned or updated until the target is reactivated.
                </p>
                <p v-else-if="clone.status === 'queued' && targetSyncing" class="text-sm text-muted-foreground">
                    Waiting for the running sync of {{ target.name }} to get to this repository.
                </p>
                <p v-else-if="clone.status === 'queued'" class="text-sm text-muted-foreground">
                    The next sync of {{ target.name }} clones this repository.
                </p>
                <p v-if="!clone.enabled && !clone.available" class="text-sm text-muted-foreground">
                    Cloning is off for this repository. Choose "Always clone", or enable cloning on the target.
                </p>
                <p v-else-if="!clone.enabled && clone.available" class="text-sm text-muted-foreground">
                    Cloning is off: the local clone is kept as it is but no longer updated.
                </p>
                <p v-if="clone.enabled && clone.available && clone.status === 'cloned'" class="text-xs text-muted-foreground">
                    Each sync of {{ target.name }} pulls the new commits, if there are any.
                </p>
                <p v-if="clone.too_large" class="text-sm text-amber-700 dark:text-amber-400">
                    Larger than the {{ clone.max_size_mb }} MB clone limit, so it won't be cloned.
                </p>
                <p v-if="repository.missing_at && clone.available" class="text-sm text-muted-foreground">
                    Removed from GitHub: the local clone is kept as it was.
                </p>
                <div
                    v-if="clone.error"
                    class="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-300"
                >
                    <CircleAlert class="mt-0.5 size-4 shrink-0" />
                    {{ clone.error }}
                </div>
            </section>

            <!-- Files -->
            <section v-if="clone.available" class="grid gap-4 lg:grid-cols-[minmax(240px,320px)_1fr]">
                <div class="max-h-[70vh] overflow-auto rounded-xl border bg-card p-2">
                    <FileTree :key="clone.commit ?? 'tree'" :repository-id="repository.id" :selected="selected" @select="selectFile" />
                </div>
                <div class="flex min-h-64 min-w-0 flex-col overflow-hidden rounded-xl border bg-card">
                    <template v-if="selected">
                        <div class="flex items-center justify-between gap-4 border-b px-4 py-2 text-sm">
                            <span class="truncate font-mono" :title="selected">{{ selected }}</span>
                            <span v-if="preview" class="shrink-0 text-xs text-muted-foreground">{{
                                formatSize(Math.ceil(preview.size / 1024))
                            }}</span>
                        </div>
                        <div v-if="previewState === 'loading'" class="flex flex-1 items-center justify-center text-sm text-muted-foreground">
                            <LoaderCircle class="mr-2 size-4 animate-spin" /> Loading…
                        </div>
                        <div v-else-if="previewState === 'error'" class="flex flex-1 items-center justify-center text-sm text-red-600">
                            Could not load this file.
                        </div>
                        <div v-else-if="preview?.binary" class="flex flex-1 items-center justify-center text-sm text-muted-foreground">
                            Binary file, download the zip to open it.
                        </div>
                        <template v-else-if="preview">
                            <pre class="max-h-[70vh] flex-1 overflow-auto p-4 font-mono text-xs leading-relaxed">{{ preview.content }}</pre>
                            <p v-if="preview.truncated" class="border-t px-4 py-2 text-xs text-muted-foreground">
                                Showing the first 512 KB only. Download the zip for the full file.
                            </p>
                        </template>
                    </template>
                    <div v-else class="flex flex-1 flex-col items-center justify-center gap-2 text-sm text-muted-foreground">
                        <FileText class="size-6" />
                        Select a file to preview it.
                    </div>
                </div>
            </section>
        </div>

        <DeleteCloneDialog v-model:open="deletingClone" :repository="repository" :clone-enabled="clone.enabled" />
    </AppLayout>
</template>
