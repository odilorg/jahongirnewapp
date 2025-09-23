<?php

namespace App\Console\Commands;

use App\Models\CashierShift;
use App\Enums\ShiftStatus;
use Illuminate\Console\Command;

class CleanupOpenShifts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shifts:cleanup-open';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up orphaned open shifts (shifts that have been open for more than 24 hours)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking for open shifts...');

        // Find all open shifts
        $openShifts = CashierShift::where('status', ShiftStatus::OPEN)->get();

        if ($openShifts->isEmpty()) {
            $this->info('No open shifts found.');
            return 0;
        }

        $this->info("Found {$openShifts->count()} open shifts:");

        foreach ($openShifts as $shift) {
            $this->line("Shift ID: {$shift->id}, User: {$shift->user_id}, Drawer: {$shift->cash_drawer_id}, Opened: {$shift->opened_at}");
        }

        // Check for duplicate constraint violations
        $duplicates = [];
        foreach ($openShifts as $shift) {
            $key = "{$shift->cash_drawer_id}-{$shift->user_id}-open";
            if (!isset($duplicates[$key])) {
                $duplicates[$key] = [];
            }
            $duplicates[$key][] = $shift;
        }

        $problematicShifts = [];
        foreach ($duplicates as $key => $shifts) {
            if (count($shifts) > 1) {
                $problematicShifts = array_merge($problematicShifts, $shifts);
                $this->warn("Found duplicate constraint violation for key '{$key}': " . count($shifts) . " shifts");
            }
        }

        // Find orphaned shifts (open for more than 24 hours)
        $orphanedShifts = $openShifts->filter(function ($shift) {
            return $shift->opened_at->lt(now()->subDay());
        });

        $shiftsToClose = array_unique(array_merge($problematicShifts, $orphanedShifts->toArray()));

        if (empty($shiftsToClose)) {
            $this->info('No problematic shifts found.');
            return 0;
        }

        $this->info("Found " . count($shiftsToClose) . " shifts that need to be closed:");
        foreach ($shiftsToClose as $shift) {
            $reason = in_array($shift, $problematicShifts) ? 'Duplicate constraint' : 'Orphaned (>24h)';
            $this->line("Shift ID: {$shift->id}, User: {$shift->user_id}, Drawer: {$shift->cash_drawer_id} - {$reason}");
        }

        if ($this->confirm('Do you want to close these shifts?')) {
            foreach ($shiftsToClose as $shift) {
                $reason = in_array($shift, $problematicShifts) ? 'Duplicate constraint cleanup' : 'Orphaned shift cleanup';
                $shift->update([
                    'status' => ShiftStatus::CLOSED,
                    'closed_at' => now(),
                    'notes' => ($shift->notes ?? '') . "\n[AUTO-CLOSED] {$reason} on " . now()->format('Y-m-d H:i:s'),
                ]);
            }

            $this->info("Successfully closed " . count($shiftsToClose) . " shifts.");
        }

        return 0;
    }
}
