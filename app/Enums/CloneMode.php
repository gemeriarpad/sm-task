<?php

namespace App\Enums;

enum CloneMode: string
{
    /** Follow the target's "clone repositories" setting. */
    case Inherit = 'inherit';
    case Always = 'always';
    case Never = 'never';

    /**
     * Human readable label for the UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::Inherit => 'Follow target',
            self::Always => 'Always clone',
            self::Never => 'Never clone',
        };
    }
}
