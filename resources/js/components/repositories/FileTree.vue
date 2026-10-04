<script setup lang="ts">
import type { TreeEntry } from '@/types';
import { ChevronRight, File, Folder, FolderOpen, LoaderCircle } from 'lucide-vue-next';
import { onMounted, ref } from 'vue';

/**
 * One folder level of a cloned repository. Sub-folders load lazily when opened,
 * so even large repositories stay fast.
 */
const props = withDefaults(
    defineProps<{
        repositoryId: number;
        /** Folder path relative to the repository root ('' for the root). */
        path?: string;
        depth?: number;
        /** Currently previewed file. */
        selected?: string | null;
    }>(),
    { path: '', depth: 0, selected: null },
);

const emit = defineEmits<{ select: [path: string] }>();

const entries = ref<TreeEntry[] | null>(null);
const failed = ref(false);
const open = ref<Set<string>>(new Set());

onMounted(async () => {
    try {
        const response = await fetch(route('repositories.tree', { repository: props.repositoryId, path: props.path }), {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        entries.value = (await response.json()).entries;
    } catch {
        failed.value = true;
    }
});

function toggle(entry: TreeEntry): void {
    if (entry.type === 'file') {
        emit('select', entry.path);

        return;
    }

    const next = new Set(open.value);
    if (next.has(entry.path)) {
        next.delete(entry.path);
    } else {
        next.add(entry.path);
    }
    open.value = next;
}
</script>

<template>
    <div
        v-if="entries === null && !failed"
        class="flex items-center gap-2 py-1 text-xs text-muted-foreground"
        :style="{ paddingLeft: `${depth * 16 + 8}px` }"
    >
        <LoaderCircle class="size-3 animate-spin" /> Loading…
    </div>
    <div v-else-if="failed" class="py-1 text-xs text-red-600" :style="{ paddingLeft: `${depth * 16 + 8}px` }">Could not load this folder.</div>
    <ul v-else role="group">
        <li v-if="entries!.length === 0" class="py-1 text-xs text-muted-foreground" :style="{ paddingLeft: `${depth * 16 + 8}px` }">Empty folder</li>
        <li v-for="entry in entries" :key="entry.path">
            <button
                type="button"
                :class="[
                    'flex w-full items-center gap-1.5 rounded-md py-1 pr-2 text-left text-sm hover:bg-accent',
                    selected === entry.path && 'bg-accent font-medium',
                ]"
                :style="{ paddingLeft: `${depth * 16 + 8}px` }"
                :aria-expanded="entry.type === 'dir' ? open.has(entry.path) : undefined"
                @click="toggle(entry)"
            >
                <ChevronRight
                    v-if="entry.type === 'dir'"
                    :class="['size-3.5 shrink-0 text-muted-foreground transition-transform', open.has(entry.path) && 'rotate-90']"
                />
                <span v-else class="w-3.5 shrink-0" />
                <FolderOpen v-if="entry.type === 'dir' && open.has(entry.path)" class="size-4 shrink-0 text-sky-600" />
                <Folder v-else-if="entry.type === 'dir'" class="size-4 shrink-0 text-sky-600" />
                <File v-else class="size-4 shrink-0 text-muted-foreground" />
                <span class="truncate">{{ entry.name }}</span>
            </button>
            <FileTree
                v-if="entry.type === 'dir' && open.has(entry.path)"
                :repository-id="repositoryId"
                :path="entry.path"
                :depth="depth + 1"
                :selected="selected"
                @select="(path: string) => emit('select', path)"
            />
        </li>
    </ul>
</template>
