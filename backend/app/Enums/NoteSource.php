<?php

namespace App\Enums;

enum NoteSource: string
{
    case REMOTE = 'remote';
    case DECK = 'deck';
    case CHAT = 'chat';
}
