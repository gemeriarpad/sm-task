import type { Option } from './ui';

export type TargetType = 'user' | 'organization';

export type TargetStatus = 'queued' | 'syncing' | 'synced' | 'failed' | 'inactive';

export type RepositoryScope = 'sources' | 'all';

/** A target row in the targets table. */
export type TargetListItem = {
    id: number;
    name: string;
    type: TargetType;
    avatar_url: string | null;
    status: TargetStatus;
    deactivated_at: string | null;
    last_synced_at: string | null;
    last_failed_at: string | null;
    last_error: string | null;
    /** Stored repositories still on GitHub, excluded ones not counted. */
    repositories_count: number;
    /** Repositories GitHub returned (within the scope) in the last sync; null before the first. */
    github_repositories_count: number | null;
    excluded_count: number;
    clones_enabled_count: number;
    clones_cloned_count: number;
};

/** A target as loaded into the edit form (only the settings are editable). */
export type TargetFormData = {
    id: number;
    name: string;
    type: TargetType;
    avatar_url: string | null;
    status: TargetStatus;
    clone_repositories: boolean;
    repository_scope: RepositoryScope;
};

/** Counts the edit form uses to explain what changing a setting does. */
export type TargetEditImpact = {
    /** Stored forks, deleted when the scope goes back to sources only. */
    forks_count: number;
    fork_clones_count: number;
    /** Repositories following the target's clone setting. */
    inherit_count: number;
    inherit_clones_count: number;
    /** Repositories set to always clone, whatever the target says. */
    always_count: number;
};

export type TargetOptions = {
    types: Option[];
    statuses: Option[];
    scopes: Option[];
};

/** A GitHub account as returned by the target check endpoint. */
export type GitHubAccount = {
    id: number;
    login: string;
    type: TargetType;
    name: string | null;
    avatar_url: string;
    html_url: string;
    public_repos: number;
};

/** A target as shown on its own page. */
export type TargetDetail = TargetListItem & {
    repositories_count: number;
    missing_count: number;
    excluded_count: number;
    repository_scope: RepositoryScope;
    clone_repositories: boolean;
    /** Every stored repository, including removed and excluded ones. */
    stored_count: number;
    /** Repositories with a clone on disk. */
    local_clones_count: number;
    /** Clones of repositories removed from GitHub: they can't be cloned again. */
    missing_clones_count: number;
    runs_count: number;
};

/** A repository in the target page's repository list. */
export type RepositoryListItem = {
    id: number;
    name: string;
    full_name: string;
    description: string | null;
    html_url: string;
    language: string | null;
    stargazers_count: number;
    forks_count: number;
    open_issues_count: number;
    fork: boolean;
    archived: boolean;
    github_updated_at: string | null;
    pushed_at: string | null;
    /** When a sync last saw the repository on GitHub. */
    last_seen_at: string | null;
    missing_at: string | null;
    excluded_at: string | null;
    clone: RepositoryCloneSummary;
    /** The owning target, only included on lists spanning several targets. */
    target: { id: number; name: string; avatar_url: string | null; status: TargetStatus } | null;
};

/** The clone state shown on repository lists and cards. */
export type RepositoryCloneSummary = {
    mode: CloneMode;
    /** Whether the repository is cloned, after applying its mode and the target setting. */
    enabled: boolean;
    status: CloneStatus | null;
    /** The chosen branch; null follows the default branch. */
    branch: string | null;
    cloned_branch: string | null;
    /** A local clone exists (cloned once and not deleted since). */
    available: boolean;
    /** "Clone now" / "Update now" can queue a sync for it. */
    requestable: boolean;
};

/** A three-way filter: all (''), only matching, or hide matching. */
export type ToggleFilter = '' | 'only' | 'hide';

/** The active repository list query, echoed by the server. */
export type RepositoryQuery = {
    search: string;
    sort: string;
    language: string;
    archived: ToggleFilter;
    fork: ToggleFilter;
    missing: ToggleFilter;
    /** Excluded repositories: hidden (''), 'only' or 'include'. */
    excluded: '' | 'only' | 'include';
    /** Target id filter (repositories page only). */
    target: string;
};

/** Select options of a repository list toolbar. */
export type RepositoryListOptions = {
    sorts: Option[];
    languages: Option[];
    targets?: Option[];
};

/** A target card on the dashboard. */
export type DashboardTarget = Pick<TargetListItem, 'id' | 'name' | 'type' | 'avatar_url' | 'status' | 'last_synced_at' | 'last_error'> & {
    repositories_count: number;
};

export type SyncRunStatus = 'running' | 'succeeded' | 'failed';

/** One sync job attempt. */
export type SyncRunItem = {
    id: number;
    attempt: number;
    status: SyncRunStatus;
    started_at: string;
    finished_at: string | null;
    duration_ms: number | null;
    repositories_count: number | null;
    /** Clones made or updated by the run. */
    clones_count: number | null;
    clone_errors_count: number | null;
    /** Clones left queued for the next sync because the run ran out of time. */
    clones_deferred_count: number | null;
    error: string | null;
    will_retry: boolean;
    target: { id: number; name: string; avatar_url: string | null };
};

/** A repository whose wanted clone failed or was skipped as too large. */
export type CloneErrorItem = {
    id: number;
    full_name: string;
    size_kb: number | null;
    clone_status: 'failed' | 'skipped';
    /** The branch the clone was asked for (the default branch unless one was picked). */
    clone_branch: string | null;
    /** What went wrong, written for users. */
    clone_error: string | null;
    target: { id: number; name: string; avatar_url: string | null };
};

/** Filter options of the clone errors table. */
export type CloneErrorOptions = {
    targets: Option[];
    kinds: Option[];
};

/** Filter options of the sync history and error tables. */
export type SyncRunOptions = {
    targets: Option[];
    statuses: Option[];
    retrying: Option[];
};

export type DashboardStats = {
    targets: number;
    repositories: number;
    missing: number;
    statuses: Record<TargetStatus, number>;
};

/** Queue worker health, see App\Services\QueueMonitor. */
export type QueueHealthInfo = {
    worker: 'running' | 'busy' | 'stopped';
    last_heartbeat_at: string | null;
    /** Seconds the last heartbeat job waited in the queue. */
    delay_seconds: number | null;
    pending_jobs: number;
    oldest_pending_job_at: string | null;
    /** Jobs a worker is running; null when the queue driver can't tell. */
    busy_jobs: number | null;
    failed_jobs_24h: number;
};

/** A row of the queue's failed_jobs table. */
export type FailedJobItem = {
    id: number;
    uuid: string;
    connection: string;
    queue: string;
    job: string;
    target: { id: number; name: string } | null;
    attempts: number | null;
    /** What went wrong, written for users (never the raw exception). */
    message: string;
    failed_at: string;
};

/** The scheduled resync of all targets, see App\Services\SyncSchedule. */
export type SyncScheduleInfo = {
    expression: string;
    /** E.g. "Every 15 minutes"; null for an invalid expression. */
    description: string | null;
    /** Null for an invalid expression. */
    next_run_at: string | null;
    scheduler_running: boolean;
    last_heartbeat_at: string | null;
};

export type CloneMode = 'inherit' | 'always' | 'never';

export type CloneStatus = 'queued' | 'cloning' | 'cloned' | 'failed' | 'skipped';

/** Cloning state of a repository. */
export type RepositoryClone = {
    mode: CloneMode;
    /** The chosen branch; null follows the default branch. */
    branch: string | null;
    /** The branch the local clone holds. */
    cloned_branch: string | null;
    /** Whether the repository is cloned, after applying its mode and the target setting. */
    enabled: boolean;
    target_clones: boolean;
    status: CloneStatus | null;
    commit: string | null;
    cloned_at: string | null;
    error: string | null;
    /** Whether a clone exists on disk (browsable and downloadable). */
    available: boolean;
    too_large: boolean;
    max_size_mb: number;
    /** Cloning runs as part of the target's sync. */
    target_status: TargetStatus;
};

/** An entry of a cloned repository's folder. */
export type TreeEntry = {
    name: string;
    path: string;
    type: 'dir' | 'file';
    size: number | null;
};

/** A file preview from a cloned repository. */
export type FilePreview = {
    path: string;
    size: number;
    binary: boolean;
    truncated: boolean;
    content: string | null;
};
