<?php

namespace App\Enums;

enum WorkSessionStatus: string
{
    case ACTIVE = 'active';
    case COMPLETED = 'completed';
    case ABANDONED = 'abandoned';

    /**
     * Check if session is still active
     */
    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }

    /**
     * Check if session is finished (completed or abandoned)
     */
    public function isFinished(): bool
    {
        return in_array($this, [self::COMPLETED, self::ABANDONED]);
    }
}
