<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { SelectField } from '@/components/table/parts';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { formatCount } from '@/lib/format';
import { isInProgress } from '@/lib/targetStatus';
import type { RepositoryScope, TargetEditImpact, TargetFormData, TargetOptions } from '@/types';
import { Link, useForm } from '@inertiajs/vue3';
import { ExternalLink, Info, LoaderCircle, SquarePen, TriangleAlert, Undo2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * Edit a target's settings. The GitHub account and type are fixed; each changed
 * setting explains what saving will do, and saving a change that deletes data asks
 * for confirmation first.
 */
const props = defineProps<{
    target: TargetFormData;
    impact: TargetEditImpact;
    options: TargetOptions;
    cancelUrl: string;
}>();

const form = useForm({
    clone_repositories: props.target.clone_repositories,
    repository_scope: props.target.repository_scope,
});

const plural = (count: number, one: string, many: string): string => `${formatCount(count)} ${count === 1 ? one : many}`;
const typeLabel = computed(() => props.options.types.find((o) => o.value === props.target.type)?.label ?? props.target.type);

const scopeChanged = computed(() => form.repository_scope !== props.target.repository_scope);
const cloneChanged = computed(() => form.clone_repositories !== props.target.clone_repositories);

// Going back to sources only deletes the stored forks (and their clones).
const deletesForks = computed(() => scopeChanged.value && form.repository_scope === 'sources' && props.impact.forks_count > 0);
const forksText = computed(
    () =>
        plural(props.impact.forks_count, 'stored fork', 'stored forks') +
        (props.impact.fork_clones_count > 0 ? ` and ${plural(props.impact.fork_clones_count, 'local clone', 'local clones')}` : ''),
);

const syncQueued = computed(() => scopeChanged.value || (cloneChanged.value && form.clone_repositories));
const inactive = computed(() => props.target.status === 'inactive');
const busy = computed(() => isInProgress(props.target.status));

const confirming = ref(false);

function submit(): void {
    if (!form.isDirty || form.processing) {
        return;
    }

    if (deletesForks.value && !confirming.value) {
        confirming.value = true;

        return;
    }

    confirming.value = false;
    form.patch(route('targets.update', { target: props.target.id }), { preserveScroll: true });
}
</script>

<template>
    <form class="space-y-6" @submit.prevent="submit">
        <!-- The account (fixed) -->
        <div class="flex flex-wrap items-center gap-3 rounded-xl border bg-card p-4 lg:max-w-2xl">
            <Avatar class="size-12 shadow-sm ring-1 ring-black/10 dark:ring-white/15">
                <AvatarImage v-if="target.avatar_url" :src="target.avatar_url" :alt="target.name" />
                <AvatarFallback class="uppercase">{{ target.name.slice(0, 2) }}</AvatarFallback>
            </Avatar>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium">{{ target.name }}</span>
                    <Badge variant="outline">{{ typeLabel }}</Badge>
                </div>
                <p class="text-xs text-muted-foreground">
                    The GitHub account and type can't be changed. To track another account, delete this target and add a new one.
                </p>
            </div>
            <a
                :href="`https://github.com/${target.name}`"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
            >
                GitHub
                <ExternalLink class="size-3.5" />
            </a>
        </div>

        <!-- Scope -->
        <div class="grid gap-2 sm:max-w-sm">
            <Label for="repository_scope">Repositories</Label>
            <SelectField
                id="repository_scope"
                :model-value="form.repository_scope"
                :options="options.scopes"
                class="w-full"
                @update:model-value="(value: string) => (form.repository_scope = value as RepositoryScope)"
            />
            <p class="text-xs text-muted-foreground">Only the target's own public repositories are synced; choose whether forks count.</p>
            <InputError :message="form.errors.repository_scope" />
        </div>
        <p v-if="deletesForks" class="flex items-start gap-2 text-sm text-amber-700 dark:text-amber-400 lg:max-w-2xl">
            <TriangleAlert class="mt-0.5 size-4 shrink-0" />
            Saving queues a sync that deletes the {{ forksText }}.
        </p>
        <p v-else-if="scopeChanged && form.repository_scope === 'all'" class="flex items-start gap-2 text-sm text-muted-foreground lg:max-w-2xl">
            <Info class="mt-0.5 size-4 shrink-0" />
            Saving queues a sync that adds the account's public forks.
        </p>
        <p v-else-if="scopeChanged" class="flex items-start gap-2 text-sm text-muted-foreground lg:max-w-2xl">
            <Info class="mt-0.5 size-4 shrink-0" />
            Saving queues a sync. No forks are stored, so nothing is deleted.
        </p>

        <!-- Cloning -->
        <div class="space-y-1 lg:max-w-2xl">
            <Label for="clone_repositories" class="flex items-center gap-3">
                <Checkbox id="clone_repositories" v-model:checked="form.clone_repositories" />
                <span>Clone repositories locally</span>
            </Label>
            <p class="pl-8 text-xs text-muted-foreground">
                Keeps a shallow git clone of every repository, updated on each sync. Individual repositories can override this on their page.
            </p>
            <InputError :message="form.errors.clone_repositories" />
        </div>
        <p v-if="cloneChanged && form.clone_repositories" class="flex items-start gap-2 text-sm text-muted-foreground lg:max-w-2xl">
            <Info class="mt-0.5 size-4 shrink-0" />
            <span>
                Saving queues a sync that clones the {{ plural(impact.inherit_count, 'repository', 'repositories') }} following this setting.
                <template v-if="impact.always_count > 0">
                    {{ plural(impact.always_count, 'repository is', 'repositories are') }} set to "Always clone" already.
                </template>
            </span>
        </p>
        <p v-else-if="cloneChanged" class="flex items-start gap-2 text-sm text-muted-foreground lg:max-w-2xl">
            <Info class="mt-0.5 size-4 shrink-0" />
            <span>
                <template v-if="impact.inherit_clones_count > 0">
                    The {{ plural(impact.inherit_clones_count, 'existing clone is', 'existing clones are') }} kept as they are but no longer updated.
                    "Delete all clones" on the target page removes them.
                </template>
                <template v-else>Nothing is cloned yet, so nothing changes on disk.</template>
                <template v-if="impact.always_count > 0">
                    {{ plural(impact.always_count, 'repository', 'repositories') }} set to "Always clone" keep being cloned.
                </template>
            </span>
        </p>

        <!-- When the changes apply -->
        <p v-if="syncQueued && inactive" class="text-sm text-muted-foreground lg:max-w-2xl">
            The target is inactive: nothing is synced until it is reactivated, the changes apply then.
        </p>
        <p v-else-if="syncQueued && busy" class="text-sm text-muted-foreground lg:max-w-2xl">
            A sync is already queued or running: the changes apply with it if it hasn't got to them yet, otherwise with the next sync.
        </p>

        <div class="flex items-center justify-between gap-3 border-t border-border pt-4">
            <Button as-child variant="ghost">
                <Link :href="cancelUrl">
                    <Undo2 class="size-4" />
                    Cancel
                </Link>
            </Button>
            <Button type="submit" :disabled="!form.isDirty || form.processing">
                <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                <SquarePen v-else class="size-4" />
                Save changes
            </Button>
        </div>

        <Dialog v-model:open="confirming">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete the forks of {{ target.name }}?</DialogTitle>
                    <DialogDescription>
                        Syncing sources only removes the {{ forksText }} with the next sync. Switch back to "incl. forks" later to sync them again.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <Button variant="ghost" @click="confirming = false">Cancel</Button>
                    <Button variant="destructive" @click="submit">Save and delete forks</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </form>
</template>
