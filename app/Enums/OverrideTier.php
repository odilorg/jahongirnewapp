<?php

namespace App\Enums;

enum OverrideTier: string
{
    case None    = 'none';
    case Cashier = 'cashier';  // within threshold — cashier self-approves with reason
    case Manager = 'manager';  // exceeds cashier threshold — requires manager approval via bot
    case Blocked = 'blocked';  // exceeds manager threshold — bot refuses, must escalate offline
}
