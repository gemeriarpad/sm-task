<script setup lang="ts">
import type { Component } from 'vue';

defineProps<{
    title: string;
    description?: string;
    /** Optional icon (a Lucide component), shown inline before the title. */
    icon?: Component;
}>();
</script>

<template>
    <header class="space-y-0.5">
        <div class="flex items-center gap-2">
            <component :is="icon" v-if="icon" class="size-5 shrink-0 text-muted-foreground" />
            <h2 class="text-xl font-semibold tracking-tight">
                {{ title }}
            </h2>
        </div>
        <p v-if="description" class="text-sm text-muted-foreground">
            {{ description }}
        </p>
    </header>
</template>
