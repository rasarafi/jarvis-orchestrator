<?php

namespace App\Enums;

enum AuditFindingSeverity: string
{
    case CRITICAL = 'critical';
    case WARNING = 'warning';
    case INFO = 'info';
}
