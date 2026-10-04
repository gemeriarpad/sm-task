<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import TargetCreateForm from '@/components/targets/TargetCreateForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem, TargetOptions } from '@/types';
import { Head } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';

defineProps<{ options: TargetOptions }>();

const indexUrl = route('targets.index');

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Targets', href: indexUrl },
    { title: 'New target', href: route('targets.create') },
];
</script>

<template>
    <Head title="New target" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full space-y-6 p-4">
            <Heading title="New target" description="Add a GitHub user or organization to synchronize." :icon="Plus" />
            <TargetCreateForm :options="options" :submit-url="route('targets.store')" :cancel-url="indexUrl" />
        </div>
    </AppLayout>
</template>
