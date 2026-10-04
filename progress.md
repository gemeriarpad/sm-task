# Progress against task.md

Status as of 2026-10-04.

Every baseline item (1–7) is done and backed by tests (the counts are in the README's Tests section; the 4 incomplete tests are deliberate placeholders). Since the first review, the code has been cleaned up, and the REST API, GitHub conditional requests, monitoring, deployment configs, user-facing failure messages and demo data were added. `AI_USAGE.md`, the README's hand-in notes and the interval explanation are written. Nothing is committed yet.

## What's left before hand-in

1. **Nothing is committed.** The repo has only the `init` commit. The remote `gemeriarpad/sm-task` is set up.
2. **Decide what goes public.** `task.md` (the employer's brief) and `.claude/skills/*` are in the repo and would be published. `progress.md` too, unless it's ignored.
3. **Check the time in the hand-in notes.** The README and `AI_USAGE.md` say about 4 hours (233 minutes), from the sittings you gave; update it if you count the later work.

Public-repo safety was checked: `.env` is gitignored, `GITHUB_TOKEN` is empty in `.env.example`, and no tokens or secrets are in any file that would be committed.

## Baseline

| # | Requirement | Status | Where |
|---|---|---|---|
| 1 | **Target** with validation: name, type, status, last sync, last error | Done. Names are unique regardless of case (also enforced by the database), checked live while typing, and can be looked up on GitHub before saving. | [TargetRequest.php](app/Http/Requests/TargetRequest.php), [Target.php](app/Models/Target.php) |
| 2 | **GitHub integration class** | Done. A dedicated client, data objects for GitHub responses, and an exception that sorts errors into temporary and permanent. | [app/Services/GitHub/](app/Services/GitHub/) |
| 3 | **Local storage**, create/update, duplicate constraint | Done. Unique `github_id`, writes with `upsert` keyed on it, and all the suggested fields (`github_updated_at` is the external last-updated time). Disappearing repositories are fully handled (`last_seen_at`/`missing_at`), beyond the "short note" the task asks for. | [RepositorySynchronizer.php](app/Services/RepositorySynchronizer.php) |
| 4 | **Queued sync** + notes on duplicates, retries, timeouts, overlaps | All four are implemented, not just noted (see below). | [SyncTargetRepositories.php](app/Jobs/SyncTargetRepositories.php) |
| 5 | **Inertia/Vue UI**: list, add, trigger, browse, status/error, ≥2 search/filter/sort | Done, and well beyond: search, 4 sorts, 6 filters, pagination. | [resources/js/pages/](resources/js/pages/) |
| 6 | **Tests**: create target, faked sync, duplicates | Done, plus unit tests and 4 placeholders in [PlannedSyncScenariosTest.php](tests/Feature/Sync/PlannedSyncScenariosTest.php). Every test fakes GitHub (`Http::fake`) and blocks real HTTP calls. | [TargetTest.php](tests/Feature/TargetTest.php), [RepositorySynchronizerTest.php](tests/Feature/Sync/RepositorySynchronizerTest.php) |
| 7 | **AI_USAGE.md** | Done | [AI_USAGE.md](AI_USAGE.md) |

How the queue topics are handled:

- **Duplicate requests:** `queueSync()` skips targets already queued or syncing, and the job uses `ShouldBeUnique` per target.
- **Overlapping jobs:** `WithoutOverlapping` middleware per target.
- **Retries:** 3 tries with backoff. Rate limits release the job until GitHub's reset time without using up attempts, and permanent errors (like a 404) fail immediately.
- **Timeouts:** 10 s per HTTP request, 1800 s for the job with `failOnTimeout`, and `retry_after` raised above the job timeout.

## Optional ("if you have time")

| Area | Status |
|---|---|
| Scheduled sync | Done: `targets:sync`, configurable cron, heartbeat, dashboard bar with **Sync all now**. `composer dev` runs the scheduler. The README explains the hourly interval. |
| Pagination | Done: follows GitHub's `Link` header, 100 per page. |
| Reconciliation | Done: `missing_at`, never set after a partial sync, cleared when a repository comes back. |
| Reliability and tests | Done: error types, logging context, `sync_runs` history per attempt, plus monitoring (`/health`, scheduler and queue heartbeats, failed-job alerts, a Failed jobs page) and deployment configs in `deploy/`. |
| REST API | Done: `/api/v1` with Sanctum tokens (`read`/`sync` abilities, managed on an **API tokens** page), targets, repositories, a 202 sync trigger and a poll endpoint with `Retry-After`. One JSON error format and the status codes 401/403/404/409/422/429. See the README's REST API section. |
| README full-text search | Not done. |
| Multitenancy | Not done, by choice: a single seeded admin, and data isn't scoped per user. A shared-sync design (users follow targets with their own settings) was explored and dropped. |

## Done since the first review

- **Clean-up:**
  - The 5 follow-up migrations are folded into the create-table migrations (the schema is verified identical).
  - 8 unused npm packages and `laravel/sail` are removed.
  - Dead code and starter-kit leftovers are gone.
  - The CI lint workflow now checks instead of auto-fixing, and the tests workflow is trimmed.
  - A test that proved nothing is deleted.
  - Two small bugs are fixed: the sidebar highlight, and an extra reload on every page load.
- **REST API and API tokens page** (above), with 28 new tests.
- **GitHub conditional requests.** Each page's ETag is stored after a complete sync, and unchanged pages come back as 304 Not Modified: no body, no database writes and, with a token, no rate-limit cost. The invalidation rules (failed syncs, scope changes, excluded repositories, locally deleted rows) are in the README.
- **Monitoring and deployment:**
  - A public `GET /health` endpoint (200/503) covers the database, the scheduler and the queue workers; the failed-job count is informational.
  - A scheduled queue heartbeat job tells running, busy and stopped workers apart and measures the queue delay. The dashboard shows it.
  - Jobs that fail for good are logged to an `alerts` channel (its own file, optionally Slack).
  - A **Failed jobs** page has Retry and Forget.
  - `deploy/` holds Supervisor configs for the workers and Reverb, the crontab line and `deploy.sh`.
- **Dashboard:**
  - The repository strip shows 5 repositories plus a "View all" card linking to the matching list.
  - The horizontal strips have a themed scrollbar.
  - A schedule bar shows the next sync, warns when the scheduler isn't running, and has **Sync all now** with a confirmation.

Still open from the review, deferred:
- Merging the many separate count queries into one per page, and pushing the clone step's repository filter into SQL.
- Duplicated dialogs, avatars and sync-runs pages.
- Removing TanStack from the data table.
- Merging overlapping tests.
- Dashboard counts that disagree with the targets table.

## Technical expectations

- **Separation of responsibilities:** controller, then `Target::queueSync()`, then the job, then `RepositorySynchronizer`, then `GitHubClient`. Turning GitHub JSON into data lives in the `GitHubRepository`/`GitHubAccount` data objects. The API has its own controllers, form requests and resources, and shares the queries with the web UI.
- **Database:** foreign keys cascade on delete, unique indexes exist, and the README has an index table plus the indexes you'd add later.
- **Error handling:** users only see worded messages. Logs carry the target in Laravel's log context, and failed jobs stay in `failed_jobs`. API errors are JSON without internal details.

## Follow-up call topics to prepare

These don't all have to be implemented, but prepare an answer:

- **Transaction boundaries.** The sync isn't wrapped in one transaction, on purpose: a long run of HTTP calls inside a transaction holds locks and connections. Each page is one atomic `upsert`, and marking repositories missing only happens after the last page succeeds. A partial run is therefore safe, and the next run fixes it. Only [TargetLifecycle.php](app/Services/TargetLifecycle.php) uses `DB::transaction`.
- **Cache invalidation.** Implemented as GitHub conditional requests: the stored pages are a cache of GitHub's answers, invalidated by GitHub's ETags and by the local rules in the README (a failed sync keeps the old pages; pages are per scope; pages with excluded repositories are never cached; a 304 page whose rows are gone is refetched). The new pages are written in one transaction after all repository writes, which ties into transaction boundaries.
- **API authentication and authorization.** Sanctum tokens with per-route abilities (`read`, `sync`). Browser sessions don't authenticate the API, because Sanctum would give them every ability. Not covered: per-user data scoping and policies (no multitenancy), and token expiry.
- **Worker deployment and monitoring.** Implemented: Supervisor configs, a crontab line and `deploy.sh` with `queue:restart`, and `stopwaitsecs`/`retry_after` above the job timeout. `/health`, heartbeats, alerts and the Failed jobs page cover monitoring. Be ready to explain why `queue:restart` is needed, and what Horizon would replace with Redis.

## Risk: scope versus the 8-hour timebox

The task says to stop at 8 hours and that they'd "rather see a smaller, clean solution". This project goes well past the brief: cloning, a file browser, zip downloads, Reverb live updates, a dashboard, deactivate/delete flows, sync history pages, a REST API, conditional requests and monitoring. Two consequences:

- **The time-spent note needs to be honest.** If it took more than 8 hours, say so, and say which parts were extras.
- **You'll be questioned on all ~15k lines.** The task says "make sure you understand anything you submit". It's worth walking through the job, the synchronizer (including the ETag logic), the cloning code, the API and the monitoring before the call.

## Next

1. Read the interview preparation page (`../sm-task-interview/interview.html`) and walk through the sync flow in the code.
2. Decide about `task.md`, `.claude/skills` and `progress.md`, then commit and push.
