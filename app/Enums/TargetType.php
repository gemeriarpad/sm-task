<?php

namespace App\Enums;

enum TargetType: string
{
    case User = 'user';
    case Organization = 'organization';

    /**
     * Human readable label for the UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::User => 'User',
            self::Organization => 'Organization',
        };
    }
}
