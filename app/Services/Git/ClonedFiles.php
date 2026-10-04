<?php

namespace App\Services\Git;

use App\Models\Repository;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

/**
 * Read-only access to a repository clone: directory listings, file previews and
 * zip downloads. Every path is resolved inside the clone; ".git" is not browsable.
 */
class ClonedFiles
{
    /**
     * Largest file preview, in bytes.
     */
    public const MAX_PREVIEW_BYTES = 512 * 1024;

    public function __construct(private readonly RepositoryCloner $cloner) {}

    /**
     * The entries of a directory: folders first, then files, alphabetically.
     *
     * @return list<array{name: string, path: string, type: 'dir'|'file', size: int|null}>
     *
     * @throws CloneException
     */
    public function list(Repository $repository, string $path = ''): array
    {
        $directory = $this->resolve($repository, $path);

        if (! is_dir($directory)) {
            throw new CloneException("Not a directory: {$path}");
        }

        $root = $this->root($repository);
        $entries = [];

        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
            /** @var SplFileInfo $entry */
            if ($directory === $root && $entry->getFilename() === '.git') {
                continue;
            }

            $isDirectory = $entry->isDir() && ! $entry->isLink();
            $entries[] = [
                'name' => $entry->getFilename(),
                'path' => ltrim(substr($entry->getPathname(), strlen($root)), '/'),
                'type' => $isDirectory ? 'dir' : 'file',
                'size' => $isDirectory ? null : ($entry->isLink() ? 0 : $entry->getSize()),
            ];
        }

        usort($entries, fn (array $a, array $b) => [$a['type'] !== 'dir', strtolower($a['name'])] <=> [$b['type'] !== 'dir', strtolower($b['name'])]);

        return $entries;
    }

    /**
     * A file's content for previewing; binary files and anything beyond the preview
     * limit are flagged instead of returned.
     *
     * @return array{path: string, size: int, binary: bool, truncated: bool, content: string|null}
     *
     * @throws CloneException
     */
    public function read(Repository $repository, string $path): array
    {
        $file = $this->resolve($repository, $path);

        if (! is_file($file)) {
            throw new CloneException("Not a file: {$path}");
        }

        $size = (int) filesize($file);
        $content = (string) file_get_contents($file, length: self::MAX_PREVIEW_BYTES);
        $binary = str_contains(substr($content, 0, 8000), "\0");

        return [
            'path' => ltrim($path, '/'),
            'size' => $size,
            'binary' => $binary,
            'truncated' => ! $binary && $size > self::MAX_PREVIEW_BYTES,
            // Invalid UTF-8 (or a multibyte character cut at the limit) is replaced.
            'content' => $binary ? null : mb_scrub($content, 'UTF-8'),
        ];
    }

    /**
     * Zip the whole clone, including .git so it unpacks into a working checkout.
     * Returns the path of a temporary zip file the caller should delete.
     *
     * @throws CloneException
     */
    public function zip(Repository $repository): string
    {
        $root = $this->root($repository);
        $zipPath = tempnam(sys_get_temp_dir(), 'repo-').'.zip';
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new CloneException('Could not create the zip file.');
        }

        $prefix = $repository->name.'/';
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($files as $file) {
            /** @var SplFileInfo $file */
            $relative = $prefix.ltrim(substr($file->getPathname(), strlen($root)), '/');

            if ($file->isLink()) {
                continue;
            }

            $file->isDir() ? $zip->addEmptyDir($relative) : $zip->addFile($file->getPathname(), $relative);
        }

        $zip->close();

        return $zipPath;
    }

    /**
     * The clone's root folder.
     *
     * @throws CloneException
     */
    private function root(Repository $repository): string
    {
        $root = realpath($this->cloner->path($repository));

        if ($root === false || ! $this->cloner->isCloned($repository)) {
            throw new CloneException('The repository has not been cloned.');
        }

        return $root;
    }

    /**
     * Resolve a path inside the clone, rejecting anything outside it (../, absolute
     * paths, symlinks pointing elsewhere) and the .git folder.
     *
     * @throws CloneException
     */
    private function resolve(Repository $repository, string $path): string
    {
        $root = $this->root($repository);
        $path = trim(str_replace('\\', '/', $path), '/');

        if (str_contains($path, "\0") || preg_match('#(^|/)\.git(/|$)#', $path)) {
            throw new CloneException("Invalid path: {$path}");
        }

        $resolved = realpath($path === '' ? $root : $root.'/'.$path);

        if ($resolved === false || ($resolved !== $root && ! str_starts_with($resolved, $root.'/'))) {
            throw new CloneException("Invalid path: {$path}");
        }

        return $resolved;
    }
}
