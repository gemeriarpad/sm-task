<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/vue3';
import { CircleAlert, FolderGit2, History, KeyRound, LayoutGrid, Target } from 'lucide-vue-next';
import AppLogo from './AppLogo.vue';
const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
        icon: LayoutGrid,
    },
    {
        title: 'Targets',
        href: '/targets',
        icon: Target,
    },
    {
        title: 'Repositories',
        href: '/repositories',
        icon: FolderGit2,
    },
    {
        title: 'Sync history',
        href: '/sync-runs',
        icon: History,
    },
    {
        title: 'Errors',
        href: '/sync-runs/errors',
        icon: CircleAlert,
        // Its "Failed jobs" tab lives at its own URL.
        activeFor: ['/failed-jobs'],
    },
    {
        title: 'API tokens',
        href: '/api-tokens',
        icon: KeyRound,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="route('dashboard')">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
