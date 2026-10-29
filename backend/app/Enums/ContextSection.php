<?php

namespace App\Enums;

enum ContextSection: string
{
    case ARCHITECTURE = 'architecture';
    case FEATURES = 'features';
    case CHANGELOG = 'changelog';
    case TODOS = 'todos';
    case BUGS = 'bugs';
    case SPENDING = 'spending';
    case DECISIONS = 'decisions';
}
