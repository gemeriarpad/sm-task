import type { CloneMode, CloneStatus } from '@/types';

/** Labels of a repository's clone status, shared by lists, cards and the repository page. */
export const CLONE_STATUS_LABELS: Record<CloneStatus, string> = {
    queued: 'Clone queued',
    cloning: 'Cloning',
    cloned: 'Cloned',
    failed: 'Clone failed',
    skipped: 'Too large to clone',
};

/** Badge colours per clone status. */
export const CLONE_STATUS_CLASSES: Record<CloneStatus, string> = {
    queued: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
    cloning: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    cloned: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    failed: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    skipped: 'bg-muted text-muted-foreground',
};

/**
 * The clone setting in a few words. "Follows target" shows what the target's own
 * setting currently means for the repository.
 */
export function cloneModeText(mode: CloneMode, enabled: boolean): string {
    switch (mode) {
        case 'always':
            return 'always clone';
        case 'never':
            return 'never clone';
        default:
            return `follows target (${enabled ? 'on' : 'off'})`;
    }
}
