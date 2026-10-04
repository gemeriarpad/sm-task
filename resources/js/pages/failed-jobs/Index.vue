<script setup lang="ts">
import ErrorTabs from '@/components/ErrorTabs.vue';
import Heading from '@/components/Heading.vue';
import DataTable from '@/components/table/DataTable.vue';
import type { RowAction } from '@/components/table/RowActions.vue';
import RowActions from '@/components/table/RowActions.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime, formatRelative } from '@/lib/format';
import type { BreadcrumbItem, DataTableColumn, FailedJobItem, Paginated } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { CircleAlert, LoaderCircle, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const page = usePage<{ jobs: Paginated<FailedJobItem> }>();
const jobs = computed(() => page.props.jobs);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Errors', href: route('sync-runs.errors') },
    { title: 'Failed jobs', href: route('failed-jobs.index') },
];

const columns: DataTableColumn[] = [
    { key: 'actions', label: 'Actions', sortable: false, class: 'w-0 text-center' },
    { key: 'failed_at', label: 'Failed', sortable: false, class: 'whitespace-nowrap' },
    { key: 'job', label: 'Job', sortable: false, class: 'whitespace-nowrap' },
    { key: 'message', label: 'Error', sortable: false, class: 'w-full min-w-64' },
    { key: 'attempts', label: 'Attempts', sortable: false, class: 'text-right' },
    { key: 'queue', label: 'Queue', sortable: false },
];

function rowActions(row: FailedJobItem): RowAction[] {
    return [
        { action: 'retry', onSelect: () => router.post(route('failed-jobs.retry', { uuid: row.uuid }), {}, { preserveScroll: true }) },
        { action: 'forget', onSelect: () => (forgetting.value = row) },
    ];
}

const forgetting = ref<FailedJobItem | null>(null);
const forgetOpen = computed({
    get: () => forgetting.value !== null,
    set: (open: boolean) => {
        if (!open) {
            forgetting.value = null;
        }
    },
});
const forgetProcessing = ref(false);

function forget(): void {
    if (!forgetting.value) {
        return;
    }

    router.delete(route('failed-jobs.destroy', { uuid: forgetting.value.uuid }), {
        preserveScroll: true,
        onStart: () => (forgetProcessing.value = true),
        onFinish: () => {
            forgetProcessing.value = false;
            forgetting.value = null;
        },
    });
}
</script>

<template>
    <Head title="Failed jobs" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex w-full flex-col gap-6 p-4">
            <Heading
                title="Errors"
                description="Queue jobs that failed for good: all attempts used, or failed on purpose. Retry puts a job back on its queue; Forget deletes it."
                :icon="CircleAlert"
            />
            <ErrorTabs current="jobs" />

            <DataTable :columns="columns" :rows="jobs.data" :pagination="jobs" data-key="jobs" empty-text="No failed jobs.">
                <template #cell-actions="{ row }">
                    <RowActions :actions="rowActions(row)" />
                </template>

                <template #cell-failed_at="{ row }">
                    <span class="text-muted-foreground" :title="formatDateTime(row.failed_at)">{{ formatRelative(row.failed_at) }}</span>
                </template>

                <template #cell-job="{ row }">
                    <span class="font-medium">{{ row.job }}</span>
                    <Link
                        v-if="row.target"
                        :href="route('targets.show', { target: row.target.id })"
                        class="block text-xs text-muted-foreground hover:underline"
                    >
                        {{ row.target.name }}
                    </Link>
                </template>

                <template #cell-message="{ row }">
                    <p class="whitespace-normal break-words text-red-600 dark:text-red-400">{{ row.message }}</p>
                </template>

                <template #cell-attempts="{ row }">
                    <span class="text-muted-foreground">{{ row.attempts ?? '—' }}</span>
                </template>

                <template #cell-queue="{ row }">
                    <span class="whitespace-nowrap text-muted-foreground">{{ row.connection }} / {{ row.queue }}</span>
                </template>
            </DataTable>
        </div>

        <Dialog v-model:open="forgetOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Forget this failed job?</DialogTitle>
                    <DialogDescription>
                        {{ forgetting?.job }}{{ forgetting?.target ? ` for ${forgetting.target.name}` : '' }} is deleted without running again. Its
                        error stays in the logs.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <Button variant="ghost" @click="forgetOpen = false">Cancel</Button>
                    <Button variant="destructive" :disabled="forgetProcessing" @click="forget">
                        <LoaderCircle v-if="forgetProcessing" class="size-4 animate-spin" />
                        <Trash2 v-else class="size-4" />
                        Forget job
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
