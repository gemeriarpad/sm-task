import type { SyncRunStatus, TargetStatus } from '@/types';

/** Badge colours per sync status, shared by the targets table and the target page. */
export const STATUS_CLASSES: Record<TargetStatus, string> = {
    queued: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
    syncing: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    synced: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    failed: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    inactive: 'bg-muted text-muted-foreground',
};

/** Whether a sync is queued or running for a target with this status. */
export const isInProgress = (status: TargetStatus): boolean => status === 'queued' || status === 'syncing';

/** Badge colours per sync run status, shared by the dashboard and the sync history. */
export const RUN_STATUS_CLASSES: Record<SyncRunStatus, string> = {
    running: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    succeeded: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    failed: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
};
