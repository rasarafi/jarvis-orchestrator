<?php

namespace App\Enums;

enum TaskPriority: string
{
    case URGENT = 'urgent';
    case HIGH = 'high';
    case MEDIUM = 'medium';
    case LOW = 'low';

    /**
     * Get SQL CASE statement for priority ordering
     * SINGLE SOURCE OF TRUTH for priority ordering logic
     */
    public static function orderingCase(): string
    {
        return "CASE
            WHEN priority = '" . self::URGENT->value . "' THEN 1
            WHEN priority = '" . self::HIGH->value . "' THEN 2
            WHEN priority = '" . self::MEDIUM->value . "' THEN 3
            WHEN priority = '" . self::LOW->value . "' THEN 4
            ELSE 5
        END";
    }

    /**
     * Get numeric order value for this priority
     */
    public function orderValue(): int
    {
        return match($this) {
            self::URGENT => 1,
            self::HIGH => 2,
            self::MEDIUM => 3,
            self::LOW => 4,
        };
    }
}
