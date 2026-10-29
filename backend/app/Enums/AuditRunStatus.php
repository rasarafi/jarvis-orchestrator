<?php

namespace App\Enums;

enum AuditRunStatus: string
{
    case RUNNING = 'running';
    case PASSED = 'passed';
    case FLAGGED = 'flagged';
    case BLOCKED = 'blocked';
}
