<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AssertProductionConfig extends Command
{
    protected $signature = 'app:assert-production-config
                            {--silent : Suppress output, only use exit code}';

    protected $description = 'Validate all required production config values are set correctly';

    public function handle(): int
    {
        $errors = [];

        // Use config() which works with both cached and uncached config.
        // env() returns null when config is cached — never use it here.

        $appEnv = config('app.env', '');
        if (empty($appEnv)) {
            $errors[] = 'app.env is empty';
        }

        $appKey = config('app.key', '');
        if (empty($appKey)) {
            $errors[] = 'app.key is empty';
        }

        // DB credentials
        $dbUser = config('database.connections.mysql.username', '');
        if (empty($dbUser)) {
            $errors[] = 'DB username is empty';
        } elseif ($dbUser === 'forge') {
            $errors[] = 'DB username is still "forge" (Laravel default — .env not loaded?)';
        }

        $dbName = config('database.connections.mysql.database', '');
        if (empty($dbName)) {
            $errors[] = 'DB database is empty';
        }

        $dbHost = config('database.connections.mysql.host', '');
        if (empty($dbHost)) {
            $errors[] = 'DB host is empty';
        }

        // Bot tokens
        $cashierToken = config('services.cashier_bot.token', '');
        if (empty($cashierToken)) {
            $errors[] = 'services.cashier_bot.token is empty';
        }

        $ownerToken = config('services.owner_alert_bot.token', '');
        if (empty($ownerToken)) {
            $errors[] = 'services.owner_alert_bot.token is empty';
        }

        // Database connectivity
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $errors[] = 'Database not reachable: ' . $e->getMessage();
        }

        if (!empty($errors)) {
            if (!$this->option('silent')) {
                foreach ($errors as $error) {
                    $this->error($error);
                }
                $this->line('');
                $this->error(count($errors) . ' production config check(s) failed.');
            }
            return self::FAILURE;
        }

        if (!$this->option('silent')) {
            $this->info('All production config checks passed.');
        }
        return self::SUCCESS;
    }
}
