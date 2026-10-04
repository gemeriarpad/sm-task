<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { formatCount } from '@/lib/format';
import type { TargetDetail } from '@/types';
import { useForm } from '@inertiajs/vue3';
import { LoaderCircle, PowerOff } from 'lucide-vue-next';
import { watch } from 'vue';

/**
 * Deactivate a target: it is no longer synced, and the user picks which data to
 * keep. Kept data stays browsable; reactivating syncs whatever was dropped again.
 */
const props = defineProps<{ target: TargetDetail }>();
const open = defineModel<boolean>('open', { required: true });

const form = useForm<{ keep_repositories: boolean; keep_clones: boolean; keep_history: boolean }>({
    keep_repositories: true,
    keep_clones: true,
    keep_history: true,
});

// A clone is only reachable through its repository.
watch(
    () => form.keep_repositories,
    (keep) => {
        if (!keep) {
            form.keep_clones = false;
        }
    },
);

// Start over each time the dialog opens.
watch(open, (isOpen) => {
    if (isOpen) {
        form.reset();
        form.clearErrors();
    }
});

function submit(): void {
    form.post(route('targets.deactivate', { target: props.target.id }), {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Deactivate {{ target.name }}?</DialogTitle>
                <DialogDescription>
                    The target is no longer synced, by the schedule or by hand, until you reactivate it. Choose what to keep in the meantime.
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-3">
                <Label class="flex items-start gap-3 font-normal">
                    <Checkbox v-model:checked="form.keep_repositories" class="mt-0.5" />
                    <span>
                        Keep the repositories
                        <span class="block text-xs text-muted-foreground"
                            >{{ formatCount(target.stored_count) }} stored, still browsable while inactive.</span
                        >
                    </span>
                </Label>
                <Label :class="['flex items-start gap-3 font-normal', !form.keep_repositories && 'opacity-50']">
                    <Checkbox v-model:checked="form.keep_clones" :disabled="!form.keep_repositories" class="mt-0.5" />
                    <span>
                        Keep the local clones
                        <span class="block text-xs text-muted-foreground">
                            {{ formatCount(target.local_clones_count) }} on disk{{
                                form.keep_repositories ? '' : ', removed with their repositories'
                            }}.
                            <template v-if="target.missing_clones_count > 0">
                                {{ formatCount(target.missing_clones_count) }} belong to repositories removed from GitHub and can't be cloned again.
                            </template>
                        </span>
                    </span>
                </Label>
                <Label class="flex items-start gap-3 font-normal">
                    <Checkbox v-model:checked="form.keep_history" class="mt-0.5" />
                    <span>
                        Keep the sync history
                        <span class="block text-xs text-muted-foreground"
                            >{{ formatCount(target.runs_count) }} sync {{ target.runs_count === 1 ? 'run' : 'runs' }} and the last error.</span
                        >
                    </span>
                </Label>
            </div>

            <InputError :message="(form.errors as Record<string, string>).target" />

            <DialogFooter class="gap-2">
                <Button variant="ghost" @click="open = false">Cancel</Button>
                <Button :disabled="form.processing" @click="submit">
                    <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                    <PowerOff v-else class="size-4" />
                    Deactivate
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
