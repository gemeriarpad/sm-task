import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    /** More paths (and their subpaths) that highlight this item. */
    activeFor?: string[];
}

export interface SharedData {
    [key: string]: unknown;
    auth: Auth;
}

export interface User {
    id: number;
    name: string;
    username: string;
    avatar?: string;
    created_at: string;
    updated_at: string;
}

export * from './apiToken';
export * from './target';
export * from './ui';
