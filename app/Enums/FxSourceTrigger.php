<?php

namespace App\Enums;

enum FxSourceTrigger: string
{
    case Webhook   = 'webhook';
    case Print     = 'print';
    case RepairJob = 'repair_job';
    case Bot       = 'bot';
    case Manual    = 'manual';
}
