<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useForm } from '@inertiajs/vue3';
import { LoaderCircle, Trash2 } from 'lucide-vue-next';
import { watch } from 'vue';

/**
 * Delete one repository's local clone. Its clone setting stays, so with cloning
 * enabled the next sync clones it again.
 */
const props = defineProps<{
    repository: { id: number; full_name: string; missing_at: string | null };
    /** Whether cloning is enabled for the repository (its mode and the target setting). */
    cloneEnabled: boolean;
}>();
const open = defineModel<boolean>('open', { required: true });

const form = useForm({});

watch(open, (isOpen) => {
    if (isOpen) {
        form.clearErrors();
    }
});

function submit(): void {
    form.delete(route('repositories.clone.destroy', { repository: props.repository.id }), {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Delete the clone of {{ repository.full_name }}?</DialogTitle>
                <DialogDescription>Deletes the local files. The repository and its clone setting stay.</DialogDescription>
            </DialogHeader>

            <p v-if="repository.missing_at" class="text-sm text-amber-700 dark:text-amber-400">
                The repository was removed from GitHub, so it can't be cloned again.
            </p>
            <p v-else-if="cloneEnabled" class="text-sm">
                Cloning is on for this repository, so the next sync clones it again. Set it to "Never clone" to keep the space free.
            </p>
            <p v-else class="text-sm">Cloning is off for this repository, so it isn't cloned again.</p>

            <InputError :message="(form.errors as Record<string, string>).clone" />

            <DialogFooter class="gap-2">
                <Button variant="ghost" @click="open = false">Cancel</Button>
                <Button variant="destructive" :disabled="form.processing" @click="submit">
                    <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                    <Trash2 v-else class="size-4" />
                    Delete clone
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
