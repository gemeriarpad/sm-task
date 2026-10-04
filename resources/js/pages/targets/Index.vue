<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import TargetsTable from '@/components/table/TargetsTable.vue';
import { Button } from '@/components/ui/button';
import { useLiveSync } from '@/composables/useLiveSync';
import AppLayout from '@/layouts/AppLayout.vue';
import { isInProgress } from '@/lib/targetStatus';
import type { BreadcrumbItem, Paginated, TargetListItem, TargetOptions } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Plus, RefreshCw, Target } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage<{
    targets: Paginated<TargetListItem>;
    sort: string;
    direction: 'asc' | 'desc';
    filters: Record<string, string>;
    options: TargetOptions;
}>();
// Reactive: re-reads on each (partial) Inertia visit so the table updates.
const targets = computed(() => page.props.targets);
const sort = computed(() => page.props.sort);
const direction = computed(() => page.props.direction);
const filters = computed(() => page.props.filters);
const options = computed(() => page.props.options);

// Status badges update live on sync updates (Reverb), or by polling while a sync runs.
// Clone updates move the "X / Y cloned" counts.
useLiveSync({
    only: ['targets'],
    pollWhile: () => targets.value.data.some((target) => isInProgress(target.status)),
    events: ['.target.sync.updated', '.repository.clone.updated'],
});

function syncAll(): void {
    router.post(route('targets.sync-all'), {}, { preserveScroll: true });
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Targets', href: route('targets.index') }];
</script>

<template>
    <Head title="Targets" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex w-full flex-col gap-6 p-4">
            <div class="flex items-start justify-between gap-4">
                <Heading title="Targets" description="GitHub users and organizations whose public repositories are synchronized." :icon="Target" />
                <div class="flex items-center gap-2">
                    <Button variant="outline" :disabled="targets.total === 0" @click="syncAll">
                        <RefreshCw class="size-4" />
                        Sync all
                    </Button>
                    <Button as-child class="shadow-md transition-shadow hover:shadow-lg">
                        <Link :href="route('targets.create')">
                            <Plus class="size-5" />
                            New target
                        </Link>
                    </Button>
                </div>
            </div>
            <TargetsTable :targets="targets.data" :pagination="targets" :sort="sort" :direction="direction" :filters="filters" :options="options" />
        </div>
    </AppLayout>
</template>
