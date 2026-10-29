<?php

namespace App\Enums;

enum SweepMatchStatus: string
{
    case PENDING = 'pending';
    case CONFIRMED = 'confirmed';
    case DISMISSED = 'dismissed';
}
