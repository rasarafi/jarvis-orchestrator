<?php

namespace App\Enums;

enum RescheduleAction: string
{
    case PUSH_1HR = 'push_1hr';
    case PUSH_EVENING = 'push_evening';
    case PUSH_TOMORROW = 'push_tomorrow';
    case SKIP = 'skip';
    case TO_BACKLOG = 'to_backlog';
    case TO_TIME = 'to_time';
}
