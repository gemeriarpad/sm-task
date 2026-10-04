<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';

const props = defineProps<{
    class?: HTMLAttributes['class'];
    containerClass?: HTMLAttributes['class'];
}>();
</script>

<template>
    <div :class="cn('relative w-full overflow-auto', props.containerClass)">
        <table :class="cn('w-full caption-bottom text-sm', props.class)">
            <slot />
        </table>
    </div>
</template>
