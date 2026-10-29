<?php // backend/app/Enums/RecapThreadStatus.php
namespace App\Enums;

enum RecapThreadStatus: string
{
    case OPEN = 'open';
    case RESOLVED = 'resolved';
    case DISMISSED = 'dismissed';
}
