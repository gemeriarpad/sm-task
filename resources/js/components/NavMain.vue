<script setup lang="ts">
import { SidebarGroup, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    items: NavItem[];
}>();

const page = usePage<SharedData>();

// The item with the longest path prefix of the current path (its href or one of its
// activeFor paths), so "Targets" stays active on /targets/5 and "Errors"
// (/sync-runs/errors) wins over "Sync history".
const activeHref = computed(() => {
    const path = page.url.split('?')[0];

    return props.items
        .flatMap((item) => [item.href, ...(item.activeFor ?? [])].map((prefix) => ({ href: item.href, prefix })))
        .filter(({ prefix }) => path === prefix || path.startsWith(`${prefix}/`))
        .sort((a, b) => b.prefix.length - a.prefix.length)[0]?.href;
});
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarMenu>
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton as-child :is-active="item.href === activeHref">
                    <Link :href="item.href">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
