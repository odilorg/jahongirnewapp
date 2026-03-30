<?php

namespace App\Enums;

enum FxSyncPushStatus: string
{
    case Pending = 'pending';
    case Pushing = 'pushing';
    case Pushed  = 'pushed';
    case Failed  = 'failed';
}
