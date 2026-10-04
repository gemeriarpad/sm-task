<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { formatCount } from '@/lib/format';
import type { TargetDetail } from '@/types';
import { useForm } from '@inertiajs/vue3';
import { LoaderCircle, Trash2 } from 'lucide-vue-next';
import { watch } from 'vue';

/**
 * Delete every local clone of a target, e.g. to free disk space after turning
 * cloning off. The repositories themselves stay.
 */
const props = defineProps<{ target: TargetDetail }>();
const open = defineModel<boolean>('open', { required: true });

const form = useForm({});

watch(open, (isOpen) => {
    if (isOpen) {
        form.clearErrors();
    }
});

function submit(): void {
    form.delete(route('targets.clones.destroy', { target: props.target.id }), {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Delete all clones of {{ target.name }}?</DialogTitle>
                <DialogDescription>
                    Deletes {{ formatCount(target.local_clones_count) }} local {{ target.local_clones_count === 1 ? 'clone' : 'clones' }}. The
                    repositories and their settings stay.
                </DialogDescription>
            </DialogHeader>

            <ul class="list-disc space-y-1 pl-5 text-sm">
                <li v-if="target.missing_clones_count > 0" class="text-amber-700 dark:text-amber-400">
                    {{ formatCount(target.missing_clones_count) }} of them belong to repositories removed from GitHub: they can't be cloned again.
                </li>
                <li v-if="target.clones_enabled_count > 0">
                    Cloning is still on for {{ formatCount(target.clones_enabled_count) }}
                    {{ target.clones_enabled_count === 1 ? 'repository' : 'repositories' }}, so the next sync clones
                    {{ target.clones_enabled_count === 1 ? 'it' : 'them' }} again. Turn cloning off first to keep the space free.
                </li>
                <li v-else>Cloning is off, so nothing is cloned again.</li>
            </ul>

            <InputError :message="(form.errors as Record<string, string>).target" />

            <DialogFooter class="gap-2">
                <Button variant="ghost" @click="open = false">Cancel</Button>
                <Button variant="destructive" :disabled="form.processing || target.local_clones_count === 0" @click="submit">
                    <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                    <Trash2 v-else class="size-4" />
                    Delete clones
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
