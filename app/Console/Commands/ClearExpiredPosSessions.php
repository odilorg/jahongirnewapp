<?php

namespace App\Console\Commands;

use App\Models\TelegramPosSession;
use Illuminate\Console\Command;

class ClearExpiredPosSessions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'telegram:pos:clear-sessions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear expired Telegram POS bot sessions';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Clearing expired Telegram POS sessions...');
        
        $count = TelegramPosSession::expired()->delete();
        
        if ($count > 0) {
            $this->info("✅ Cleared {$count} expired session(s)");
        } else {
            $this->info('✅ No expired sessions found');
        }
        
        return Command::SUCCESS;
    }
}
