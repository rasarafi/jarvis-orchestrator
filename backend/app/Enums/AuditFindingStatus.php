<?php

namespace App\Enums;

enum AuditFindingStatus: string
{
    case OPEN = 'open';
    case DISMISSED = 'dismissed';
    case CONVERTED = 'converted';
    case SUPPRESSED = 'suppressed';
}
