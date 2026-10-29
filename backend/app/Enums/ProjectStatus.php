<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case ACTIVE = 'active';
    case PAUSED = 'paused';
    case MAINTENANCE = 'maintenance';
    case ARCHIVED = 'archived';

    /**
     * Check if project is active
     */
    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }

    /**
     * Check if project is in working state (active or maintenance)
     */
    public function isWorking(): bool
    {
        return in_array($this, [self::ACTIVE, self::MAINTENANCE]);
    }
}
