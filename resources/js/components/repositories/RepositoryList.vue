<script setup lang="ts">
import CloneBadge from '@/components/repositories/CloneBadge.vue';
import DeleteCloneDialog from '@/components/repositories/DeleteCloneDialog.vue';
import { ColumnFilter, SelectField, TablePagination } from '@/components/table/parts';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatCount, formatDate, formatDateTime, formatRelative } from '@/lib/format';
import type { Option, Paginated, RepositoryListItem, RepositoryListOptions, RepositoryQuery } from '@/types';
import { Link, router } from '@inertiajs/vue3';
import { Archive, CircleDot, Download, ExternalLink, Eye, EyeOff, GitFork, PowerOff, RefreshCw, Star, Trash2 } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';

/**
 * A searchable, filterable and paginated repository list. The server does the
 * filtering (see RepositoryListQuery); this component only drives the query string.
 */
const props = withDefaults(
    defineProps<{
        repositories: Paginated<RepositoryListItem>;
        query: RepositoryQuery;
        options: RepositoryListOptions;
        /** URL the list lives on (filters are applied as its query string). */
        url: string;
        /** Show the owning target on each repository (lists spanning targets). */
        showTarget?: boolean;
        /** Text shown when nothing is stored yet (as opposed to nothing matching the filters). */
        emptyText?: string;
        /** Whether anything is stored at all; picks the empty state. */
        hasAny?: boolean;
    }>(),
    { showTarget: false, emptyText: 'No repositories synced yet.', hasAny: true },
);

// Sentinel for a select's "All" row (an empty value would show the placeholder).
const ALL = '__all__';

// Local, editable copy of the query, seeded from the server echo.
const filters = reactive({ ...props.query });

watch(
    () => props.query,
    (query) => Object.assign(filters, query),
);

const toggleOptions = (noun: string): Option[] => [
    { value: ALL, label: `All ${noun}` },
    { value: 'hide', label: `Hide ${noun}` },
    { value: 'only', label: `Only ${noun}` },
];

const excludedOptions: Option[] = [
    { value: ALL, label: 'Hide excluded' },
    { value: 'only', label: 'Only excluded' },
    { value: 'include', label: 'Include excluded' },
];

/** Exclude a repository (hidden, frozen by syncs, never cloned) or include it again. */
function toggleExcluded(repository: RepositoryListItem): void {
    const url = route(repository.excluded_at ? 'repositories.include' : 'repositories.exclude', { repository: repository.id });
    const options = { preserveScroll: true, preserveState: true, only: ['repositories', 'target', 'totals', 'options'] };

    if (repository.excluded_at) {
        router.delete(url, options);
    } else {
        router.post(url, {}, options);
    }
}

/** Queue a sync that clones the repository, or updates its clone. */
function cloneNow(repository: RepositoryListItem): void {
    router.post(
        route('repositories.clone.refresh', { repository: repository.id }),
        {},
        { preserveScroll: true, preserveState: true, only: ['repositories', 'target'] },
    );
}

/** Already queued or being cloned: "Clone now" would do nothing. */
const cloneBusy = (repository: RepositoryListItem): boolean => repository.clone.status === 'queued' || repository.clone.status === 'cloning';

// The repository whose clone the confirm dialog is about to delete.
const deletingClone = ref<RepositoryListItem | null>(null);
const deleteCloneOpen = computed({
    get: () => deletingClone.value !== null,
    set: (open: boolean) => {
        if (!open) {
            deletingClone.value = null;
        }
    },
});

// Row action icons, styled alike (tooltips name them).
const ICON_ACTION =
    'inline-flex size-8 items-center justify-center rounded-md text-muted-foreground opacity-60 transition hover:bg-accent hover:text-foreground hover:opacity-100 focus-visible:opacity-100 disabled:pointer-events-none disabled:opacity-30';
const ICON_ACTION_DANGER = 'hover:bg-destructive/10 hover:text-destructive';

const languageOptions = computed<Option[]>(() => [{ value: ALL, label: 'All languages' }, ...props.options.languages]);
const targetOptions = computed<Option[]>(() => [{ value: ALL, label: 'All targets' }, ...(props.options.targets ?? [])]);

/**
 * Reload the list with the given query changes, resetting to page 1 unless a page
 * is given. Empty values are dropped from the URL.
 */
function navigate(changes: Partial<RepositoryQuery> & { page?: number; per_page?: number }): void {
    const { page, per_page: perPage, ...query } = changes;
    Object.assign(filters, query);

    const params: Record<string, string | number> = {
        ...filters,
        page: page ?? 1,
        per_page: perPage ?? props.repositories.per_page,
    };

    for (const key of Object.keys(params)) {
        if (params[key] === '' || params[key] === ALL) {
            delete params[key];
        }
    }

    router.get(props.url, params, {
        only: ['repositories', 'query'],
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

const fromSelect = (value: string): string => (value === ALL ? '' : value);

const hasFilters = computed(
    () =>
        filters.search !== '' ||
        filters.language !== '' ||
        filters.archived !== '' ||
        filters.fork !== '' ||
        filters.missing !== '' ||
        filters.excluded !== '' ||
        filters.target !== '',
);

function resetFilters(): void {
    navigate({ search: '', language: '', archived: '', fork: '', missing: '', excluded: '', target: '' });
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <!-- Toolbar: search, sort and filters -->
        <div class="flex flex-wrap items-center gap-2">
            <ColumnFilter
                :model-value="filters.search"
                placeholder="Search name or description"
                class="h-9 w-72 text-sm"
                @update:model-value="(value: string) => (filters.search = value)"
                @commit="(value: string) => navigate({ search: value })"
            />
            <SelectField
                :model-value="filters.sort"
                :options="options.sorts"
                class="h-9 w-44"
                @update:model-value="(value: string) => navigate({ sort: value })"
            />
            <SelectField
                v-if="options.targets"
                :model-value="filters.target || ALL"
                :options="targetOptions"
                class="h-9 w-44"
                @update:model-value="(value: string) => navigate({ target: fromSelect(value) })"
            />
            <SelectField
                :model-value="filters.language || ALL"
                :options="languageOptions"
                class="h-9 w-44"
                @update:model-value="(value: string) => navigate({ language: fromSelect(value) })"
            />
            <SelectField
                :model-value="filters.archived || ALL"
                :options="toggleOptions('archived')"
                class="h-9 w-40"
                @update:model-value="(value: string) => navigate({ archived: fromSelect(value) as RepositoryQuery['archived'] })"
            />
            <SelectField
                :model-value="filters.fork || ALL"
                :options="toggleOptions('forks')"
                class="h-9 w-36"
                @update:model-value="(value: string) => navigate({ fork: fromSelect(value) as RepositoryQuery['fork'] })"
            />
            <SelectField
                :model-value="filters.missing || ALL"
                :options="toggleOptions('removed')"
                class="h-9 w-40"
                @update:model-value="(value: string) => navigate({ missing: fromSelect(value) as RepositoryQuery['missing'] })"
            />
            <SelectField
                :model-value="filters.excluded || ALL"
                :options="excludedOptions"
                class="h-9 w-40"
                @update:model-value="(value: string) => navigate({ excluded: fromSelect(value) as RepositoryQuery['excluded'] })"
            />
            <Button v-if="hasFilters" variant="ghost" size="sm" @click="resetFilters">Reset filters</Button>
        </div>

        <!-- Repository list -->
        <div class="overflow-hidden rounded-xl border bg-card">
            <ul v-if="repositories.data.length > 0" class="divide-y">
                <li
                    v-for="repository in repositories.data"
                    :key="repository.id"
                    :class="['flex items-start gap-3 p-4', (repository.missing_at || repository.excluded_at) && 'bg-muted/40']"
                >
                    <div class="min-w-0 flex-1 space-y-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <Link
                                v-if="showTarget && repository.target"
                                :href="route('targets.show', { target: repository.target.id })"
                                class="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                            >
                                <Avatar class="size-5">
                                    <AvatarImage
                                        v-if="repository.target.avatar_url"
                                        :src="repository.target.avatar_url"
                                        :alt="repository.target.name"
                                    />
                                    <AvatarFallback class="text-[10px] uppercase">{{ repository.target.name.slice(0, 2) }}</AvatarFallback>
                                </Avatar>
                            </Link>
                            <Link
                                :href="route('repositories.show', { repository: repository.id })"
                                :class="[
                                    'font-medium hover:underline',
                                    repository.missing_at ? 'text-muted-foreground line-through' : 'text-foreground',
                                ]"
                            >
                                {{ repository.full_name }}
                            </Link>
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
                            <Badge v-if="repository.excluded_at" variant="outline">
                                <EyeOff class="size-3" />
                                Excluded
                            </Badge>
                            <Badge v-if="repository.target?.status === 'inactive'" variant="outline" class="text-muted-foreground">
                                <PowerOff class="size-3" />
                                Inactive target
                            </Badge>
                        </div>
                        <p v-if="repository.description" class="line-clamp-2 text-sm text-muted-foreground">{{ repository.description }}</p>
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                            <span v-if="repository.language" class="font-medium text-foreground">{{ repository.language }}</span>
                            <span class="inline-flex items-center gap-1" title="Stars">
                                <Star class="size-3.5" />
                                {{ formatCount(repository.stargazers_count) }}
                            </span>
                            <span class="inline-flex items-center gap-1" title="Forks">
                                <GitFork class="size-3.5" />
                                {{ formatCount(repository.forks_count) }}
                            </span>
                            <span class="inline-flex items-center gap-1" title="Open issues (GitHub includes pull requests)">
                                <CircleDot class="size-3.5" />
                                {{ formatCount(repository.open_issues_count) }}
                            </span>
                            <span title="Last updated on GitHub">Updated {{ formatDate(repository.github_updated_at) }}</span>
                            <span :title="`Last seen by a sync: ${formatDateTime(repository.last_seen_at)}`"
                                >Synced {{ formatRelative(repository.last_seen_at) }}</span
                            >
                            <CloneBadge :clone="repository.clone" />
                        </div>
                    </div>

                    <!-- Row actions -->
                    <div class="flex shrink-0 items-center gap-0.5">
                        <button
                            v-if="repository.clone.requestable"
                            type="button"
                            :class="ICON_ACTION"
                            :disabled="cloneBusy(repository)"
                            :title="
                                cloneBusy(repository)
                                    ? 'Waiting for a sync to clone it'
                                    : repository.clone.available
                                      ? 'Update the clone now'
                                      : 'Clone now'
                            "
                            :aria-label="repository.clone.available ? 'Update the clone now' : 'Clone now'"
                            @click="cloneNow(repository)"
                        >
                            <RefreshCw :class="['size-4', repository.clone.status === 'cloning' && 'animate-spin']" />
                        </button>
                        <a
                            v-if="repository.clone.available"
                            :href="route('repositories.download', { repository: repository.id })"
                            :class="ICON_ACTION"
                            title="Download the clone as a zip"
                            aria-label="Download zip"
                        >
                            <Download class="size-4" />
                        </a>
                        <button
                            v-if="repository.clone.available"
                            type="button"
                            :class="[ICON_ACTION, ICON_ACTION_DANGER]"
                            :disabled="repository.clone.status === 'cloning'"
                            title="Delete the local clone"
                            aria-label="Delete clone"
                            @click="deletingClone = repository"
                        >
                            <Trash2 class="size-4" />
                        </button>
                        <a
                            v-if="!repository.missing_at"
                            :href="repository.html_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            :class="ICON_ACTION"
                            title="Open on GitHub"
                            aria-label="Open on GitHub"
                        >
                            <ExternalLink class="size-4" />
                        </a>
                        <button
                            type="button"
                            :class="ICON_ACTION"
                            :title="repository.excluded_at ? 'Include again' : 'Exclude: hide, stop syncing and cloning'"
                            :aria-label="repository.excluded_at ? 'Include again' : 'Exclude'"
                            @click="toggleExcluded(repository)"
                        >
                            <Eye v-if="repository.excluded_at" class="size-4" />
                            <EyeOff v-else class="size-4" />
                        </button>
                    </div>
                </li>
            </ul>
            <div v-else class="flex flex-col items-center justify-center gap-1 py-16 text-sm text-muted-foreground">
                <slot v-if="!hasAny" name="empty">{{ emptyText }}</slot>
                <template v-else>
                    No repositories match the current filters.
                    <Button variant="link" size="sm" @click="resetFilters">Reset filters</Button>
                </template>
            </div>

            <TablePagination
                v-if="repositories.total > 0"
                :pagination="repositories"
                :per-page-options="[10, 25, 50, 100]"
                class="border-t px-4 py-3"
                @update:page="(p: number) => navigate({ page: p })"
                @update:per-page="(n: number) => navigate({ per_page: n })"
            />
        </div>

        <DeleteCloneDialog
            v-if="deletingClone"
            v-model:open="deleteCloneOpen"
            :repository="deletingClone"
            :clone-enabled="deletingClone.clone.enabled"
        />
    </div>
</template>
