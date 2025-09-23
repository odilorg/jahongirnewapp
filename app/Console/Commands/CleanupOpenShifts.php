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
        $this->info('Cleaning up orphaned open shifts...');

        // Find shifts that have been open for more than 24 hours
        $orphanedShifts = CashierShift::where('status', ShiftStatus::OPEN)
            ->where('opened_at', '<', now()->subDay())
            ->get();

        if ($orphanedShifts->isEmpty()) {
            $this->info('No orphaned open shifts found.');
            return 0;
        }

        $this->info("Found {$orphanedShifts->count()} orphaned open shifts:");

        foreach ($orphanedShifts as $shift) {
            $this->line("Shift ID: {$shift->id}, User: {$shift->user_id}, Drawer: {$shift->cash_drawer_id}, Opened: {$shift->opened_at}");
        }

        if ($this->confirm('Do you want to close these orphaned shifts?')) {
            foreach ($orphanedShifts as $shift) {
                $shift->update([
                    'status' => ShiftStatus::CLOSED,
                    'closed_at' => now(),
                    'notes' => ($shift->notes ?? '') . "\n[AUTO-CLOSED] Orphaned shift cleanup on " . now()->format('Y-m-d H:i:s'),
                ]);
            }

            $this->info("Successfully closed {$orphanedShifts->count()} orphaned shifts.");
        }

        return 0;
    }
}
