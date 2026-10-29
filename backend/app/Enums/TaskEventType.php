<?php

namespace App\Enums;

enum TaskEventType: string
{
    case CREATED = 'created';
    case STATUS_CHANGED = 'status_changed';
    case EDITED = 'edited';
    case COMPLETED = 'completed';
    case REOPENED = 'reopened';
    case DELETED = 'deleted';
    case RESTORED = 'restored';
    case ARCHIVED = 'archived';
    case UNARCHIVED = 'unarchived';
    case DEPENDENCY_ADDED = 'dependency_added';
    case DEPENDENCY_REMOVED = 'dependency_removed';
}
