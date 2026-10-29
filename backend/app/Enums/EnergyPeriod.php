<?php

namespace App\Enums;

enum EnergyPeriod: string
{
    case MORNING = 'morning';
    case MIDDAY = 'midday';
    case AFTERNOON = 'afternoon';
    case EVENING = 'evening';

    public static function fromHour(int $hour): self
    {
        return match (true) {
            $hour >= 6 && $hour < 10 => self::MORNING,
            $hour >= 10 && $hour < 14 => self::MIDDAY,
            $hour >= 14 && $hour < 18 => self::AFTERNOON,
            default => self::EVENING,
        };
    }

    public static function current(): self
    {
        return self::fromHour((int) now()->format('G'));
    }

    public function label(): string
    {
        return match ($this) {
            self::MORNING => 'Morning — hard/creative work',
            self::MIDDAY => 'Midday — meetings/collaboration',
            self::AFTERNOON => 'Afternoon — routine/admin tasks',
            self::EVENING => 'Evening — light work/planning',
        };
    }
}
