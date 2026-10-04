<script setup lang="ts">
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { X } from 'lucide-vue-next';
import type { HTMLAttributes } from 'vue';
import { computed, onBeforeUnmount } from 'vue';

/**
 * A debounced text filter for a table column. Keeps `v-model` live on every
 * keystroke but only emits `commit` once typing settles, so the parent can
 * re-query without firing on every character. A clear button appears on the
 * right whenever there is a value and commits the empty value immediately.
 */
const props = withDefaults(
    defineProps<{
        modelValue: string;
        /** Placeholder. */
        placeholder?: string;
        /** Idle time before `commit` fires, in ms. */
        delay?: number;
        type?: string;
        class?: HTMLAttributes['class'];
    }>(),
    { placeholder: 'Filter', delay: 400, type: 'text', class: 'h-8 text-xs' },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
    /** Fired once the input has settled (debounced) or is cleared. */
    commit: [value: string];
}>();

let timer: ReturnType<typeof setTimeout> | undefined;

const hasValue = computed(() => props.modelValue !== '' && props.modelValue != null);

function onInput(value: string | number): void {
    const next = String(value);
    emit('update:modelValue', next);
    clearTimeout(timer);
    timer = setTimeout(() => emit('commit', next), props.delay);
}

function clear(): void {
    clearTimeout(timer);
    emit('update:modelValue', '');
    emit('commit', '');
}

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div class="relative">
        <Input
            :model-value="modelValue"
            :type="type"
            :class="cn(props.class, hasValue && 'pr-7')"
            :placeholder="placeholder"
            @update:model-value="onInput"
        />
        <button
            v-if="hasValue"
            type="button"
            class="absolute inset-y-0 right-0 flex cursor-pointer items-center pr-2 text-muted-foreground hover:text-foreground"
            :aria-label="'Clear'"
            @click="clear"
        >
            <X class="size-3.5" />
        </button>
    </div>
</template>
