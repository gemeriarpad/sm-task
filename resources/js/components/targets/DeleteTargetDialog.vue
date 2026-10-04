<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatCount } from '@/lib/format';
import type { TargetDetail } from '@/types';
import { useForm } from '@inertiajs/vue3';
import { LoaderCircle, Trash2 } from 'lucide-vue-next';
import { computed, watch } from 'vue';

/**
 * Delete a target with everything stored for it. The user types the target's name
 * to confirm (checked again on the server).
 */
const props = defineProps<{ target: TargetDetail }>();
const open = defineModel<boolean>('open', { required: true });

const form = useForm({ confirm_name: '' });
const confirmed = computed(() => form.confirm_name === props.target.name);

// Start over each time the dialog opens.
watch(open, (isOpen) => {
    if (isOpen) {
        form.reset();
        form.clearErrors();
    }
});

function submit(): void {
    if (confirmed.value) {
        form.delete(route('targets.destroy', { target: props.target.id }), { onSuccess: () => (open.value = false) });
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Delete {{ target.name }}?</DialogTitle>
                <DialogDescription>This removes the target and everything stored for it. It can't be undone.</DialogDescription>
            </DialogHeader>

            <ul class="list-disc space-y-1 pl-5 text-sm">
                <li>{{ formatCount(target.stored_count) }} {{ target.stored_count === 1 ? 'repository' : 'repositories' }}</li>
                <li>
                    {{ formatCount(target.local_clones_count) }} local {{ target.local_clones_count === 1 ? 'clone' : 'clones' }}
                    <span v-if="target.missing_clones_count > 0" class="text-amber-700 dark:text-amber-400">
                        ({{ formatCount(target.missing_clones_count) }} of repositories removed from GitHub, which can't be cloned again)
                    </span>
                </li>
                <li>{{ formatCount(target.runs_count) }} sync {{ target.runs_count === 1 ? 'run' : 'runs' }}</li>
            </ul>
            <p class="text-sm text-muted-foreground">To keep the data and only stop syncing, deactivate the target instead.</p>

            <form class="grid gap-2" @submit.prevent="submit">
                <Label for="confirm_name">
                    Type <span class="font-mono font-semibold">{{ target.name }}</span> to confirm
                </Label>
                <Input id="confirm_name" v-model="form.confirm_name" autocomplete="off" />
                <InputError :message="form.errors.confirm_name ?? (form.errors as Record<string, string>).target" />
            </form>

            <DialogFooter class="gap-2">
                <Button variant="ghost" @click="open = false">Cancel</Button>
                <Button variant="destructive" :disabled="!confirmed || form.processing" @click="submit">
                    <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                    <Trash2 v-else class="size-4" />
                    Delete target
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
