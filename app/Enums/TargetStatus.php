<?php

namespace App\Enums;

enum TargetStatus: string
{
    /** A sync job has been dispatched and is waiting for a worker. */
    case Queued = 'queued';

    /** A worker is currently synchronizing the repositories. */
    case Syncing = 'syncing';

    /** The last synchronization finished successfully. */
    case Synced = 'synced';

    /** The last synchronization attempt failed, see the target's last error. */
    case Failed = 'failed';

    /** Deactivated: never synced until it is reactivated; kept data stays browsable. */
    case Inactive = 'inactive';

    /**
     * Human readable label for the UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Syncing => 'Syncing',
            self::Synced => 'Synced',
            self::Failed => 'Failed',
            self::Inactive => 'Inactive',
        };
    }

    /**
     * Whether a synchronization is currently queued or running.
     */
    public function isInProgress(): bool
    {
        return in_array($this, [self::Queued, self::Syncing], true);
    }
}
