<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import TargetSettingsForm from '@/components/targets/TargetSettingsForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem, TargetEditImpact, TargetFormData, TargetOptions } from '@/types';
import { Head } from '@inertiajs/vue3';
import { SquarePen } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    target: TargetFormData;
    impact: TargetEditImpact;
    options: TargetOptions;
}>();

const indexUrl = route('targets.index');

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Targets', href: indexUrl },
    { title: props.target.name, href: route('targets.show', { target: props.target.id }) },
    { title: 'Edit', href: route('targets.edit', { target: props.target.id }) },
]);
</script>

<template>
    <Head title="Edit target" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full space-y-6 p-4">
            <Heading title="Edit target" description="Change which repositories are synced and whether they are cloned." :icon="SquarePen" />
            <TargetSettingsForm :target="target" :impact="impact" :options="options" :cancel-url="indexUrl" />
        </div>
    </AppLayout>
</template>
