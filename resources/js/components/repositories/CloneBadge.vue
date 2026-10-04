<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { CLONE_STATUS_CLASSES, CLONE_STATUS_LABELS, cloneModeText } from '@/lib/cloneStatus';
import type { RepositoryCloneSummary } from '@/types';
import { HardDrive, LoaderCircle } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * A repository's clone state for lists and cards: the status (with the chosen
 * branch, if any) and the clone setting it comes from.
 */
const props = defineProps<{ clone: RepositoryCloneSummary }>();

const label = computed(() => (props.clone.status ? CLONE_STATUS_LABELS[props.clone.status] : 'Not cloned'));
const mode = computed(() => cloneModeText(props.clone.mode, props.clone.enabled));
</script>

<template>
    <span class="inline-flex items-center gap-1.5" :title="`Clone setting: ${mode}`">
        <Badge v-if="clone.status" :class="['border-transparent font-medium', CLONE_STATUS_CLASSES[clone.status]]">
            <LoaderCircle v-if="clone.status === 'cloning'" class="size-3 animate-spin" />
            <HardDrive v-else class="size-3" />
            {{ label }}
            <span v-if="clone.branch" class="font-mono font-normal">· {{ clone.branch }}</span>
        </Badge>
        <Badge v-else variant="outline" class="font-medium text-muted-foreground">
            <HardDrive class="size-3" />
            {{ label }}
        </Badge>
        <span class="text-muted-foreground">{{ mode }}</span>
    </span>
</template>
