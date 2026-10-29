<?php

namespace App\Enums;

enum ProjectGroup: string
{
    case MFB = 'mfb';
    case FA = 'fa';
    case FREELANCE = 'freelance';
    case PERSONAL = 'personal';

    public function label(): string
    {
        return match ($this) {
            self::MFB => 'MFB',
            self::FA => 'FA',
            self::FREELANCE => 'Freelance',
            self::PERSONAL => 'Self',
        };
    }
}
