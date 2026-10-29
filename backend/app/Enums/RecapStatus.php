<?php // backend/app/Enums/RecapStatus.php
namespace App\Enums;

enum RecapStatus: string
{
    case OK = 'ok';
    case FAILED = 'failed';
}
