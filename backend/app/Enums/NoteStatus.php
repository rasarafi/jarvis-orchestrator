<?php

namespace App\Enums;

enum NoteStatus: string
{
    case INBOX = 'inbox';
    case KEPT = 'kept';
    case ARCHIVED = 'archived';
}
