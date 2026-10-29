<?php

namespace App\Enums;

enum TaskActor: string
{
    case USER = 'user';
    case KIRA = 'kira';
    case JARVIS = 'jarvis';
    case REMOTE = 'remote';
    case SYSTEM = 'system';
}
