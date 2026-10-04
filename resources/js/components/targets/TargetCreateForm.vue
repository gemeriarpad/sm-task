<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { SelectField } from '@/components/table/parts';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { GitHubAccount, RepositoryScope, TargetOptions } from '@/types';
import { Link } from '@inertiajs/vue3';
import { useForm } from 'laravel-precognition-vue-inertia';
import { CircleAlert, CircleCheck, CircleX, ExternalLink, LoaderCircle, Save, SearchCheck, Undo2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

/**
 * The form for adding a target. The GitHub name and type are fixed once added (the
 * edit page only changes the settings, see TargetSettingsForm).
 */
const props = defineProps<{
    options: TargetOptions;
    /** Where the form posts (the store url). */
    submitUrl: string;
    /** Back-link target (the index page). */
    cancelUrl: string;
}>();

// Precognition form: besides the usual Inertia state (isDirty/processing/errors/
// v-model) it adds form.validate('field') so the same TargetRequest rules apply
// live as the user fills the fields in.
const form = useForm('post', props.submitUrl, {
    name: '',
    type: '',
    clone_repositories: false,
    repository_scope: 'sources' as RepositoryScope,
});

// --- GitHub check ------------------------------------------------------------

type CheckState =
    | { status: 'idle' }
    | { status: 'checking' }
    | { status: 'found'; account: GitHubAccount }
    | { status: 'not_found' }
    | { status: 'error'; message: string };

const check = ref<CheckState>({ status: 'idle' });
const sameName = (a: string, b: string): boolean => a.trim().toLowerCase() === b.trim().toLowerCase();

// Saving needs a passing check of the name being saved.
const checkPassed = computed(() => check.value.status === 'found' && sameName(check.value.account.login, form.name));
const canSave = computed(() => !form.processing && checkPassed.value);

// github_id is only added by the transform below, so it isn't part of the typed form errors.
const githubIdError = computed(() => (form.errors as Record<string, string | undefined>).github_id);

const typeMismatch = computed(() => check.value.status === 'found' && form.type !== '' && check.value.account.type !== form.type);
const typeLabel = (value: string): string => props.options.types.find((o) => o.value === value)?.label ?? value;

// A result only applies to the name it was made for, so editing the name resets it.
watch(
    () => form.name,
    (name) => {
        if (check.value.status === 'found' && !sameName(check.value.account.login, name)) {
            check.value = { status: 'idle' };
        } else if (check.value.status === 'not_found' || check.value.status === 'error') {
            check.value = { status: 'idle' };
        }
    },
);

async function runCheck(): Promise<void> {
    check.value = { status: 'checking' };

    try {
        const response = await fetch(route('targets.check', { name: form.name.trim() }), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const body = await response.json();

        if (response.status === 422) {
            check.value = { status: 'error', message: body.errors?.name?.[0] ?? body.message };
        } else if (!response.ok) {
            check.value = { status: 'error', message: body.message ?? 'The GitHub check failed.' };
        } else {
            check.value = body.found ? { status: 'found', account: body.account } : { status: 'not_found' };
        }
    } catch {
        check.value = { status: 'error', message: 'The GitHub check failed, please try again.' };
    }
}

// Send the GitHub id and avatar along only when they belong to the name being saved.
form.transform((data) =>
    checkPassed.value && check.value.status === 'found'
        ? { ...data, github_id: check.value.account.id, avatar_url: check.value.account.avatar_url }
        : data,
);

function setType(value: string): void {
    form.type = value;
    form.validate('type');
}

function submit(): void {
    if (!canSave.value) {
        return;
    }

    form.submit({ preserveScroll: true });
}
</script>

<template>
    <form class="space-y-6" @submit.prevent="submit">
        <div class="grid gap-4 sm:grid-cols-2 lg:max-w-2xl">
            <div class="grid gap-2">
                <Label for="name">
                    GitHub name
                    <span aria-hidden="true" class="text-red-600">*</span>
                </Label>
                <div class="flex gap-2">
                    <Input id="name" v-model="form.name" autocomplete="off" placeholder="e.g. laravel" @blur="form.validate('name')" />
                    <Button type="button" variant="outline" :disabled="form.name.trim() === '' || check.status === 'checking'" @click="runCheck">
                        <LoaderCircle v-if="check.status === 'checking'" class="size-4 animate-spin" />
                        <SearchCheck v-else class="size-4" />
                        Check
                    </Button>
                </div>
                <InputError :message="form.errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="type">
                    Type
                    <span aria-hidden="true" class="text-red-600">*</span>
                </Label>
                <SelectField
                    id="type"
                    :model-value="form.type"
                    :options="options.types"
                    placeholder="Select a type"
                    class="w-full"
                    @update:model-value="setType"
                />
                <InputError :message="form.errors.type" />
            </div>
        </div>

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

        <!-- GitHub check result -->
        <div v-if="check.status === 'found'" class="space-y-3 rounded-xl border bg-card p-4 lg:max-w-2xl">
            <div class="flex items-center gap-3">
                <Avatar class="size-12 shadow-sm ring-1 ring-black/10 dark:ring-white/15">
                    <AvatarImage :src="check.account.avatar_url" :alt="check.account.login" />
                    <AvatarFallback class="uppercase">{{ check.account.login.slice(0, 2) }}</AvatarFallback>
                </Avatar>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <CircleCheck class="size-4 text-emerald-600" />
                        <span class="font-medium">{{ check.account.name ?? check.account.login }}</span>
                        <Badge variant="outline">{{ typeLabel(check.account.type) }}</Badge>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        @{{ check.account.login }} · {{ check.account.public_repos }} public
                        {{ check.account.public_repos === 1 ? 'repository' : 'repositories' }}
                    </p>
                </div>
                <a
                    :href="check.account.html_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                >
                    GitHub
                    <ExternalLink class="size-3.5" />
                </a>
            </div>
            <p v-if="typeMismatch" class="flex items-center gap-2 text-sm text-amber-700 dark:text-amber-400">
                <CircleAlert class="size-4 shrink-0" />
                GitHub reports this account as {{ typeLabel(check.account.type) }}, but {{ typeLabel(form.type) }} is selected.
            </p>
            <InputError :message="githubIdError" />
        </div>
        <p v-else-if="check.status === 'not_found'" class="flex items-center gap-2 text-sm text-red-600 dark:text-red-500">
            <CircleX class="size-4 shrink-0" />
            No GitHub user or organization named "{{ form.name.trim() }}" exists.
        </p>
        <p v-else-if="check.status === 'error'" class="flex items-center gap-2 text-sm text-red-600 dark:text-red-500">
            <CircleAlert class="size-4 shrink-0" />
            {{ check.message }}
        </p>

        <div class="flex items-center justify-between gap-3 border-t border-border pt-4">
            <Button as-child variant="ghost">
                <Link :href="cancelUrl">
                    <Undo2 class="size-4" />
                    Cancel
                </Link>
            </Button>
            <div class="flex items-center gap-3">
                <span v-if="!checkPassed" class="text-sm text-muted-foreground">Check the name on GitHub before saving.</span>
                <Button type="submit" :disabled="!canSave">
                    <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                    <Save v-else class="size-4" />
                    Create target
                </Button>
            </div>
        </div>
    </form>
</template>
