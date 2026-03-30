<?php

namespace App\Console\Commands;

use App\Services\Fx\FxManagerApprovalService;
use Illuminate\Console\Command;

class ExpireManagerApprovals extends Command
{
    protected $signature   = 'fx:expire-approvals';
    protected $description = 'Expire FX manager approvals whose TTL has passed';

    public function handle(FxManagerApprovalService $service): int
    {
        $count = $service->expireStale();

        $this->info("Expired {$count} stale manager approval(s).");

        return self::SUCCESS;
    }
}
