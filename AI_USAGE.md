# AI usage

Almost all code, tests and docs were written by **Claude Code** (Anthropic, model Claude Opus 5.5, VS Code extension). I directed the work feature by feature, made the design decisions, tested in the browser and rejected what didn't fit. I can walk through any part of it.

## Tools

- **Claude Code**, in several sessions, sometimes two in parallel. Subagents explored the code and did one review pass.
- **Project skills** in `.claude/skills/`, copied from my other projects: `shadcn-vue`, `web-design-guidelines` and `writing-guidelines`.
- **Not AI:** the Laravel Vue starter kit, the shadcn-vue components, and the data table adapted from my own `laravel-vue-skeleton`.

## How I worked, and the important prompts

I pasted the brief one baseline item at a time. My standing instructions:

- **Ask before deciding.** "Dont make assumptions without asking." The assistant then listed the design choices for each feature, and I picked, in about 25 rounds.
- **No commits.**
- **No secrets in tracked files**, because the repository is public.

## Suggestions I changed or rejected

- **The first feature:** the assistant chose names, type auto-detection and lowercase logins, and deleted a file, all without asking. I undid that and made asking a rule.
- **A separate clone job:** merged into the sync job, so there's one code path.
- **Editing a target's GitHub account:** stopped. A target's account is now fixed.
- **Polling the dashboard:** replaced by Reverb events.
- **Multitenancy:** the assistant designed it; I dropped it for the timebox.

## How it was validated

- **Tests:** 295 PHPUnit tests (GitHub always faked, git checked with `Process::fake`). During development the suite also ran on PostgreSQL.
- **Static checks:** Pint, ESLint, Prettier and the build run in CI; `vue-tsc` (TypeScript) was run locally.
- **Manual testing:** every feature in the browser, plus live checks of `/health`, the API and `composer setup`.
- **Bugs caught in AI code:**
    - a scheduler heartbeat that never dispatched
    - wrong-token API requests that were never rate limited
    - the setup command answering its own prompts when there was no terminal
    - etc.
