# GitHub Repository Sync

A Laravel 13 + Vue 3 (Inertia) app that keeps a local copy of the public repositories of GitHub users and organizations, synced by queued jobs. Built for the SportMate interview task
AI use is described in [`AI_USAGE.md`](AI_USAGE.md).

## Setup

Requirements: PHP 8.3+, Composer, Node.js 18+, and `git` for cloning.

```bash
composer setup   # install, .env, database, admin login, GitHub token, Reverb, npm, demo data
composer dev     # server, queue worker, scheduler, Reverb, Vite and logs together
```

`composer setup` checks what's missing and asks only about that. A GitHub token is checked against GitHub before it's saved, and the database credentials are tested before they're written. It's safe to run again, and without a terminal it only warns. Open http://127.0.0.1:8000 and log in with `ADMIN_USERNAME` / `ADMIN_PASSWORD` from `.env`. Restart `composer dev` after changing `.env`: running workers keep the old values.

| `.env`                                          | Purpose                                                                                         |
| ----------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| `GITHUB_TOKEN`                                  | Optional, no scopes needed. Raises GitHub's limit from 60 to 5,000 requests an hour.            |
| `GITHUB_SYNC_SCHEDULE`                          | Cron for the scheduled resync, hourly by default.                                               |
| `GITHUB_CLONE_MAX_SIZE_MB`, `GITHUB_CLONE_PATH` | Clone size limit (512 MB) and folder (`storage/app/clones`).                                    |
| `DB_CONNECTION`                                 | SQLite by default; PostgreSQL and MySQL 8 work too (`composer setup` asks for the credentials). |

## What it does

- **Targets:** add a GitHub user or organization. The name is validated live, checked on GitHub (Check button) and unique regardless of case. Adding one queues its first sync.
- **Sync:** a queued job pages through the target's public repositories, upserts them by GitHub id, and marks the ones GitHub no longer returns as missing. It runs from the UI, on a schedule, from `php artisan targets:sync`, or through the API.
- **Browsing:** search, sort, filters and pagination per target and across all targets. Every target shows its status and last error, and every sync attempt is kept in a history.
- **Extras beyond the brief:**
    - optional shallow clones with a file browser and zip download
    - live updates over Reverb (polling as a fallback)
    - a dashboard
    - deactivating and deleting targets
    - a REST API with tokens
    - GitHub ETags
    - `/health`, heartbeats and failed-job alerts
    - Supervisor and deploy configs in `deploy/`

## How it works

| Layer                            | Where                                                                                                                              |
| -------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| Controllers (web / API)          | `app/Http/Controllers`, `app/Http/Controllers/Api/V1`; validation in `app/Http/Requests`                                           |
| The one entry point for queueing | `Target::queueSync()`                                                                                                              |
| Queue job                        | `app/Jobs/SyncTargetRepositories.php`                                                                                              |
| Sync logic                       | `app/Services/RepositorySynchronizer.php`                                                                                          |
| GitHub boundary                  | `app/Services/GitHub/GitHubClient.php`; JSON mapped into data objects; `GitHubException` sorts errors into temporary and permanent |
| Cloning                          | `app/Services/CloneSynchronizer.php`, `app/Services/Git/`                                                                          |
| UI                               | `resources/js/pages` (Inertia pages), `resources/js/composables/useLiveSync.ts`                                                    |

**A sync, in short:**

1. `queueSync()` skips targets that are inactive or already queued or syncing, then dispatches the job.
2. The job takes a per-target lock and records a `sync_runs` row for the attempt.
3. The synchronizer follows GitHub's `Link` header, 100 repositories per page, and upserts each page by `github_id`.
4. Only after the last page succeeds does it mark repositories it didn't see as `missing_at`. A partial run never deletes or marks anything.
5. Repositories with cloning enabled are cloned or updated if they changed.
6. The target becomes `synced`, or `failed` with a user-facing `last_error`.

## Notes the brief asks for

- **Duplicate requests:** `queueSync()` skips targets already queued or syncing, and the job is `ShouldBeUnique` per target.
- **Overlapping jobs:** `WithoutOverlapping` per target. `ShouldBeUnique` stops a second job from being queued; `WithoutOverlapping` stops two from running at once.
- **Retries and failed jobs:**
    - 3 tries with backoff of 30 and 120 seconds.
    - Rate limits `release()` the job until GitHub's reset time, so they don't use up attempts.
    - Permanent errors (404, the wrong user/organization type) fail at once.
    - Final failures land in `failed_jobs` (Retry on the Failed jobs page, or `queue:retry`) and are logged to `storage/logs/alerts.log`.
- **Timeouts:** 10 s per HTTP request and 1,800 s per job (`failOnTimeout`; cloning is part of the job). The queue's `retry_after` of 1,860 s stays above the job timeout, so a running job is never handed to a second worker.
- **Disappearing repositories:** marked `missing_at` (shown with a badge) instead of deleted, because they may be private, renamed or only temporarily gone. The upsert clears the mark if a repository comes back. Next step: prune after N days.
- **Interval:** the scheduled sync runs hourly. Repository metadata changes slowly, and any target can be synced on demand. One sync costs one request per 100 repositories, so hourly fits the 60-requests-an-hour anonymous limit with a dozen targets, while 5-minute runs wouldn't. A sync with cloning can take up to 30 minutes, so an hourly run doesn't overlap the previous one.
- **Indexes:**
    - Unique `repositories.github_id` (the upsert key).
    - `repositories (target_id, missing_at)` for every list and the missing marking.
    - Unique `lower(targets.name)` and `targets.github_id`.
    - `sync_runs (status, started_at)` for the dashboard.
    - I would add `(target_id, pushed_at)` and similar for the sorts, and full-text search instead of `LIKE '%…%'`.
- **Errors:** users only see worded messages. Every log line of a sync carries the target in Laravel's log `Context`, so you can tell which sync failed and why.
- **Transactions:** a sync is deliberately not one transaction, because a long run of HTTP calls would hold locks. Each upsert batch is atomic, and the destructive steps run only after the last page succeeds.

## REST API

Bearer tokens (Sanctum) are created on the **API tokens** page, with the `read` and/or `sync` ability. Responses are `{"data": …}`; errors are always JSON `{"message": …}`.

| Endpoint                                                                                                    | Ability      |                                                                                                          |
| ----------------------------------------------------------------------------------------------------------- | ------------ | -------------------------------------------------------------------------------------------------------- |
| `GET /api/v1/targets`, `/targets/{id}`, `/targets/{id}/repositories`, `/repositories`, `/repositories/{id}` | read         | Paginated, with the UI's filters                                                                         |
| `POST /api/v1/targets/{id}/sync`                                                                            | sync         | **202** + `Location` to poll; repeating it doesn't queue a second sync; **409** for a deactivated target |
| `GET /api/v1/targets/{id}/sync`                                                                             | read or sync | Sync status, with `Retry-After: 5` while running                                                         |
| `POST /api/v1/sync`                                                                                         | sync         | Queues every active target                                                                               |

Status codes: 401 (no or bad token), 403 (missing ability), 404, 409, 422 (invalid query), 429.

Rate limits: 120 requests a minute per IP, counted before authentication so wrong tokens are limited too, and 60 a minute per token.

## Tests

`php artisan test`: 295 tests, plus 4 named placeholders for what I'd cover next (`PlannedSyncScenariosTest`).

- **GitHub** is always faked (`Http::fake`, with `preventStrayRequests`).
- **Git** commands are checked with `Process::fake`.
- **Coverage:** the baseline cases (creating targets, a faked sync, no duplicates, also at database level) plus retries, rate limits, pagination, missing marking, cloning, the API, monitoring and `composer setup`.

CI runs the tests and the linters.

## Hand-in notes

- **Time:** about 6 hours (357 minutes in three sittings), with most code written by Claude Code under my direction.
- **Done:**
    - all baseline items
    - from the optional list: scheduled sync, REST API, pagination, reconciliation, reliability
    - extras: cloning, live updates, dashboard, monitoring, ETags, deploy configs, `composer setup`
- **Not done:** README full-text search; multitenancy (designed, then dropped for time; there is a single seeded admin); API token expiry.
- **Known issues:** a `git` command that times out fails the whole sync attempt instead of only that repository; each zip download leaves an empty temp file behind.
- **Next:**
    1. Multitenancy (`user_id` on targets, policies).
    2. Split large syncs into per-page and per-clone jobs.
    3. Redis + Horizon instead of the database queue.
    4. Prune long-missing repositories.
- **Compromises:**
    - The scope is far beyond the brief.
    - The database queue.
    - Cloning runs serially inside the sync job.
    - `open_issues_count` includes pull requests, as GitHub reports it.
