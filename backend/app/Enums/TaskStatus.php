<?php

namespace App\Enums;

enum TaskStatus: string
{
    case TODO = 'todo';
    case IN_PROGRESS = 'in_progress';
    case BLOCKED = 'blocked';
    case REVIEW = 'review';
    case DONE = 'done';

    /**
     * Get all "open" task statuses (not done)
     */
    public static function openStatuses(): array
    {
        return [
            self::TODO->value,
            self::IN_PROGRESS->value,
            self::BLOCKED->value,
            self::REVIEW->value,
        ];
    }

    /**
     * Check if this status represents an open task
     */
    public function isOpen(): bool
    {
        return in_array($this->value, self::openStatuses());
    }
}
