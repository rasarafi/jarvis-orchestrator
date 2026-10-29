<?php

namespace App\Enums;

enum InterruptStatus: string
{
    case ACTIVE = 'active';
    case RESOLVED = 'resolved';

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }
}
