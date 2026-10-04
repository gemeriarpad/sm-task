<?php

namespace App\Concerns;

use Illuminate\Http\Request;

/**
 * Shared helpers for server-side DataTable endpoints: validated page size and
 * a whitelisted sort column + direction.
 */
trait DataTableQuery
{
    /**
     * The requested page size, restricted to the allowed options.
     *
     * @param  list<int>  $options
     */
    protected function perPage(Request $request, array $options = [10, 25, 50, 100, 250, 500], int $default = 100): int
    {
        $perPage = (int) $request->integer('per_page', $default);

        return in_array($perPage, $options, true) ? $perPage : $default;
    }

    /**
     * The requested sort key (restricted to $allowed) and direction. When no
     * explicit direction is requested, falls back to $defaultDirection (asc for
     * most tables; pass 'desc' for log/event feeds that want newest-first).
     *
     * @param  list<string>  $allowed
     * @return array{0: string, 1: string}
     */
    protected function sortParams(Request $request, array $allowed, string $default, string $defaultDirection = 'asc'): array
    {
        $sort = (string) $request->query('sort', '');

        if (! in_array($sort, $allowed, true)) {
            $sort = $default;
        }

        $requested = $request->query('direction');
        $direction = in_array($requested, ['asc', 'desc'], true) ? $requested : $defaultDirection;

        return [$sort, $direction];
    }

    /**
     * The active per-column filters: whitelisted keys with a non-empty value,
     * read from the `filter[<key>]` query array (matches the generic DataTable).
     *
     * @param  list<string>  $allowed
     * @return array<string, string>
     */
    protected function filters(Request $request, array $allowed): array
    {
        $raw = $request->query('filter');

        if (! is_array($raw)) {
            return [];
        }

        $filters = [];

        foreach ($allowed as $key) {
            $value = $raw[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $filters[$key] = trim($value);
            }
        }

        return $filters;
    }
}
