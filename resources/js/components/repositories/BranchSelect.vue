<script setup lang="ts">
import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { GitBranch, LoaderCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * Picks the branch a repository is cloned from. The branch list is read from
 * GitHub (git ls-remote, no API quota) the first time the select opens.
 */
const props = defineProps<{
    repositoryId: number;
    /** The chosen branch; null follows the default branch. */
    modelValue: string | null;
    defaultBranch: string | null;
    disabled?: boolean;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>();

// Sentinel for "follow the default branch" (a select item can't have a null value).
const DEFAULT = '__default__';

const branches = ref<string[] | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);

async function load(): Promise<void> {
    if (branches.value !== null || loading.value) {
        return;
    }

    loading.value = true;
    error.value = null;

    try {
        const response = await fetch(route('repositories.branches', { repository: props.repositoryId }), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const body = await response.json();

        if (!response.ok) {
            throw new Error(body.message ?? 'Could not load the branches.');
        }

        branches.value = body.branches;
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Could not load the branches.';
    } finally {
        loading.value = false;
    }
}

const defaultLabel = computed(() => `Default branch (${props.defaultBranch ?? 'HEAD'})`);

// The default branch is offered once, as "follow the default"; the chosen branch
// stays listed even before the list loaded (or after it was deleted on GitHub).
const otherBranches = computed(() => {
    const list = (branches.value ?? []).filter((branch) => branch !== props.defaultBranch);

    return props.modelValue && !list.includes(props.modelValue) ? [props.modelValue, ...list] : list;
});

const missing = computed(() => branches.value !== null && props.modelValue !== null && !branches.value.includes(props.modelValue));

const label = computed(() => {
    if (props.modelValue === null) {
        return defaultLabel.value;
    }

    return missing.value ? `${props.modelValue} (not on GitHub)` : props.modelValue;
});

function onUpdate(value: unknown): void {
    if (value == null) {
        return;
    }

    const branch = value === DEFAULT ? null : String(value);

    if (branch !== props.modelValue) {
        emit('update:modelValue', branch);
    }
}
</script>

<template>
    <Select :model-value="modelValue ?? DEFAULT" :disabled="disabled" @update:model-value="onUpdate" @update:open="(open: boolean) => open && load()">
        <SelectTrigger class="h-9 w-60" aria-label="Branch to clone">
            <!-- The slot always renders an element, see SelectField. -->
            <SelectValue>
                <span class="flex min-w-0 items-center gap-2">
                    <GitBranch class="size-4 shrink-0 text-muted-foreground" />
                    <span :class="['truncate', missing && 'text-red-600 dark:text-red-400']">{{ label }}</span>
                </span>
            </SelectValue>
        </SelectTrigger>
        <SelectContent>
            <SelectGroup>
                <SelectItem :value="DEFAULT">{{ defaultLabel }}</SelectItem>
                <SelectItem v-for="branch in otherBranches" :key="branch" :value="branch">
                    <span class="font-mono text-xs">{{ branch }}</span>
                </SelectItem>
                <div v-if="loading" class="flex items-center gap-2 px-2 py-1.5 text-sm text-muted-foreground">
                    <LoaderCircle class="size-4 animate-spin" />
                    Loading branches…
                </div>
                <p v-else-if="error" class="max-w-60 px-2 py-1.5 text-sm text-red-600 dark:text-red-400">{{ error }}</p>
                <p v-else-if="branches !== null && otherBranches.length === 0" class="px-2 py-1.5 text-sm text-muted-foreground">
                    No other branches on GitHub.
                </p>
            </SelectGroup>
        </SelectContent>
    </Select>
</template>
