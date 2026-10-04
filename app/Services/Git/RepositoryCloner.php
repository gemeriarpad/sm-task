<?php

namespace App\Services\Git;

use App\Models\Repository;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Keeps shallow local clones of repositories, one folder per GitHub repository id
 * (stable across renames and transfers).
 */
class RepositoryCloner
{
    public function __construct(
        private readonly string $basePath,
        private readonly int $timeout = 540,
    ) {}

    /**
     * The folder holding the repository's clone.
     */
    public function path(Repository $repository): string
    {
        return rtrim($this->basePath, '/').'/'.$repository->github_id;
    }

    public function isCloned(Repository $repository): bool
    {
        return is_dir($this->path($repository).'/.git');
    }

    /**
     * Delete the repository's clone, if any.
     */
    public function delete(Repository $repository): void
    {
        File::deleteDirectory($this->path($repository));
    }

    /**
     * Delete every clone (e.g. after `migrate:fresh`). Only folders named like a
     * clone ("<github id>" or "<github id>.tmp-<random>") are touched, so a
     * misconfigured clone path can't wipe unrelated files.
     */
    public function purgeAll(): int
    {
        if (! is_dir($this->basePath)) {
            return 0;
        }

        $deleted = 0;

        foreach (File::directories($this->basePath) as $directory) {
            if (preg_match('/^\d+(\.tmp-[a-z0-9]+)?$/', basename($directory))) {
                File::deleteDirectory($directory);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Clone the repository, or bring an existing clone up to date. The clone holds
     * the repository's clone branch (its default branch unless another one was
     * chosen); a clone of a different branch is replaced. Returns the checked out
     * commit (null for an empty repository).
     *
     * @throws CloneException
     */
    public function cloneOrUpdate(Repository $repository): ?string
    {
        try {
            $this->isCloned($repository) && $repository->cloned_branch === $repository->cloneBranch()
                ? $this->update($repository)
                : $this->clone($repository);
        } catch (CloneException $e) {
            if ($repository->clone_branch !== null && $this->isMissingBranch($e)) {
                throw new CloneException("The branch \"{$repository->clone_branch}\" no longer exists on GitHub. Pick another branch.", previous: $e);
            }

            throw $e;
        }

        return $this->head($repository);
    }

    /**
     * The repository's branch names on GitHub, read with `git ls-remote` (no API
     * rate limit), sorted by name.
     *
     * @return list<string>
     *
     * @throws CloneException
     */
    public function branches(Repository $repository): array
    {
        $output = $this->git(['ls-remote', '--heads', $this->remoteUrl($repository)], timeout: 30)->output();

        return collect(explode("\n", $output))
            ->map(fn (string $line) => Str::after(trim($line), "\trefs/heads/"))
            ->filter(fn (string $branch) => $branch !== '' && ! str_contains($branch, "\t"))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * Shallow clone into a temporary folder first, so a failed or killed clone never
     * leaves a half-written repository behind (and an existing clone stays as it was).
     */
    private function clone(Repository $repository): void
    {
        $path = $this->path($repository);
        $temporary = $path.'.tmp-'.Str::lower(Str::random(8));

        File::ensureDirectoryExists(dirname($path));
        $this->deleteTemporaryFolders($repository);

        // An explicitly chosen branch is asked for by name; otherwise git clones the
        // remote's default branch (which also works for an empty repository).
        $branch = $repository->clone_branch !== null ? ['--branch', $repository->clone_branch] : [];

        try {
            $this->git(['clone', '--depth', '1', '--single-branch', '--no-tags', ...$branch, $this->remoteUrl($repository), $temporary]);
        } catch (CloneException $e) {
            File::deleteDirectory($temporary);

            throw $e;
        }

        File::deleteDirectory($path);
        File::moveDirectory($temporary, $path);
    }

    /**
     * Fetch the latest commit of the clone branch and check it out.
     */
    private function update(Repository $repository): void
    {
        $path = $this->path($repository);

        $this->git(['-C', $path, 'fetch', '--depth', '1', '--no-tags', 'origin', $repository->cloneBranch() ?: 'HEAD']);
        $this->git(['-C', $path, 'reset', '--hard', 'FETCH_HEAD']);
    }

    /**
     * Remove temporary folders a killed clone (e.g. a timed out job) left behind.
     */
    private function deleteTemporaryFolders(Repository $repository): void
    {
        foreach (File::glob($this->path($repository).'.tmp-*', GLOB_ONLYDIR) as $folder) {
            File::deleteDirectory($folder);
        }
    }

    /**
     * Whether git failed because the requested branch doesn't exist on the remote.
     */
    private function isMissingBranch(CloneException $e): bool
    {
        return (bool) preg_match('/Remote branch .+ not found|couldn\'t find remote ref/i', $e->output ?? '');
    }

    /**
     * The checked out commit, null when the repository has no commits yet.
     */
    private function head(Repository $repository): ?string
    {
        $result = Process::timeout(30)->run(['git', '-C', $this->path($repository), 'rev-parse', 'HEAD']);

        return $result->successful() ? trim($result->output()) : null;
    }

    /**
     * The public HTTPS URL; the full name is checked so it can't smuggle git options.
     */
    private function remoteUrl(Repository $repository): string
    {
        if (! preg_match('#^[A-Za-z0-9-]+/[A-Za-z0-9._-]+$#', $repository->full_name)) {
            throw new CloneException("Refusing to clone a repository with an unexpected name: {$repository->full_name}");
        }

        return "https://github.com/{$repository->full_name}.git";
    }

    /**
     * Run a git command (as an argument list, never through a shell).
     *
     * @param  list<string>  $arguments
     *
     * @throws CloneException
     */
    private function git(array $arguments, ?int $timeout = null): ProcessResult
    {
        $result = Process::timeout($timeout ?? $this->timeout)
            ->env(['GIT_TERMINAL_PROMPT' => '0'])
            ->run(['git', ...$arguments]);

        if ($result->failed()) {
            $output = trim($result->errorOutput() ?: $result->output());

            // git's output is for debugging; users get a plain message.
            Log::warning('Git command failed.', ['arguments' => $arguments, 'exit_code' => $result->exitCode(), 'output' => $output]);

            throw CloneException::fromGitOutput($output);
        }

        return $result;
    }
}
