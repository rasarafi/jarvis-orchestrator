<?php

namespace App\Enums;

enum AuditGate: int
{
    case ALIVE = 0;
    case SUBSTANCE = 1;
    case EXPERIENCE = 2;
}
