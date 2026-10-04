<script setup lang="ts">
import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { Option } from '@/types';
import type { HTMLAttributes } from 'vue';
import { computed } from 'vue';

/**
 * A thin wrapper over the shadcn-vue Select for simple option lists. Unlike a
 * native <select> it renders a themed, dark-mode-aware floating panel with
 * keyboard navigation. Pass options as {value,label}.
 */
const props = withDefaults(
    defineProps<{
        modelValue: string;
        options: Option[];
        /** Placeholder shown when nothing is selected. */
        placeholder?: string;
        /** `sm` for in-table filter rows, `default` for forms and toolbars. */
        size?: 'sm' | 'default';
        id?: string;
        class?: HTMLAttributes['class'];
    }>(),
    { placeholder: undefined, size: 'default', id: undefined, class: undefined },
);

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

function onUpdate(value: unknown): void {
    if (value != null) {
        emit('update:modelValue', String(value));
    }
}

// The currently-selected option. SelectValue normally mirrors the selected
// SelectItem's text, but the items live in SelectContent which isn't mounted
// until the panel first opens, so we render the label ourselves in the trigger.
// The slot must always render an element: radix-vue decides once, before mount,
// whether SelectValue has children, and if it doesn't it also teleports the
// selected item's text in, which would show the label twice.
const selected = computed(() => props.options.find((o) => o.value === props.modelValue));
</script>

<template>
    <Select :model-value="modelValue" @update:model-value="onUpdate">
        <SelectTrigger :id="id" :size="size" :class="props.class">
            <SelectValue :placeholder="placeholder">
                <span>{{ selected?.label }}</span>
            </SelectValue>
        </SelectTrigger>
        <SelectContent>
            <SelectGroup>
                <SelectItem v-for="opt in options" :key="opt.value" :value="opt.value">
                    {{ opt.label }}
                </SelectItem>
            </SelectGroup>
        </SelectContent>
    </Select>
</template>
