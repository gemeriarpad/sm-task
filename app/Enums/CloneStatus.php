<?php

namespace App\Enums;

enum CloneStatus: string
{
    case Queued = 'queued';
    case Cloning = 'cloning';
    case Cloned = 'cloned';
    case Failed = 'failed';
    /** Not cloned on purpose, e.g. larger than the configured limit. */
    case Skipped = 'skipped';
}
