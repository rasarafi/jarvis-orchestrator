<?php

namespace App\Enums;

enum TaskSource: string
{
    case JARVIS = 'jarvis';
    case KIRA = 'kira';
    case REMOTE = 'remote';
}
