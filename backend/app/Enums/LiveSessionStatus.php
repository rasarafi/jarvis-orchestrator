<?php

namespace App\Enums;

enum LiveSessionStatus: string
{
    /** Transcript file modified within the last minute — Claude is actively producing output. */
    case WORKING = 'working';

    /** Last message is from the assistant and the file has gone quiet — it's the user's turn. */
    case WAITING = 'waiting';

    /** Last message is not from the assistant but the file has gone quiet — likely stuck. */
    case STALLED = 'stalled';
}
