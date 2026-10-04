<?php

namespace App\Enums;

/**
 * Which of a target's own public repositories are synced.
 */
enum RepositoryScope: string
{
    /** Repositories the target created itself, without forks. */
    case Sources = 'sources';
    /** All of the target's own public repositories, including forks. */
    case All = 'all';

    /**
     * Human readable label for the UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::Sources => 'Own sources only (no forks)',
            self::All => 'Own repositories incl. forks',
        };
    }

    public function includesForks(): bool
    {
        return $this === self::All;
    }
}
