<?php

namespace App\Enums;

enum AuditScope: string
{
    case SYSTEM = 'system';   // handover-audit — whole deployed product
    case FEATURE = 'feature'; // feature-audit — one feature's checklist + surface
}
