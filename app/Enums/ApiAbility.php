<?php

namespace App\Enums;

/**
 * What an API token may do. A token gets one or both when it is created.
 */
enum ApiAbility: string
{
    /** Read targets, repositories and sync status. */
    case Read = 'read';
    /** Trigger syncs and poll their status, e.g. from a CI job or another backend. */
    case Sync = 'sync';

    /**
     * Human readable label for the UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::Read => 'Read',
            self::Sync => 'Sync',
        };
    }

    /**
     * What the ability allows, shown next to its checkbox.
     */
    public function description(): string
    {
        return match ($this) {
            self::Read => 'List and view targets, repositories and sync status.',
            self::Sync => 'Trigger syncs and poll their status.',
        };
    }
}
