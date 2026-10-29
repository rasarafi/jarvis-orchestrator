<?php

namespace App\Enums;

enum FocusSessionStatus: string
{
    case ACTIVE = 'active';
    case PAUSED = 'paused';
    case ENDED = 'ended';

    /**
     * SINGLE SOURCE OF TRUTH for "session not yet ended" filter.
     */
    public static function openValues(): array
    {
        return [self::ACTIVE->value, self::PAUSED->value];
    }
}
