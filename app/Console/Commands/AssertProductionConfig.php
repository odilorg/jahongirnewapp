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

        // APP_ENV must be set
        $appEnv = env('APP_ENV', '');
        if (empty($appEnv)) {
            $errors[] = 'APP_ENV is empty';
        }

        // APP_KEY must be set
        $appKey = env('APP_KEY', '');
        if (empty($appKey)) {
            $errors[] = 'APP_KEY is empty';
        }

        // DB_USERNAME must be set and not the default forge placeholder
        $dbUser = env('DB_USERNAME', '');
        if (empty($dbUser)) {
            $errors[] = 'DB_USERNAME is empty';
        } elseif ($dbUser === 'forge') {
            $errors[] = 'DB_USERNAME is still set to the default "forge" placeholder';
        }

        // DB_DATABASE must be set
        $dbName = env('DB_DATABASE', '');
        if (empty($dbName)) {
            $errors[] = 'DB_DATABASE is empty';
        }

        // DB_HOST must be set
        $dbHost = env('DB_HOST', '');
        if (empty($dbHost)) {
            $errors[] = 'DB_HOST is empty';
        }

        // CASHIER_BOT_TOKEN must be set
        $cashierToken = env('CASHIER_BOT_TOKEN', '');
        if (empty($cashierToken)) {
            $errors[] = 'CASHIER_BOT_TOKEN is empty';
        }

        // Beds24 refresh token — accept either env key
        $beds24Token = env('BEDS24_API_V2_REFRESH_TOKEN', '') ?: env('BEDS24_REFRESH_TOKEN', '');
        if (empty($beds24Token)) {
            $errors[] = 'BEDS24_API_V2_REFRESH_TOKEN (or BEDS24_REFRESH_TOKEN) is empty';
        }

        // Owner alert bot token — accept either env key or config value
        $ownerToken = env('OWNER_ALERT_BOT_TOKEN', '') ?: config('services.owner_alert_bot.token', '');
        if (empty($ownerToken)) {
            $errors[] = 'OWNER_ALERT_BOT_TOKEN (or services.owner_alert_bot.token) is empty';
        }

        // Database connectivity check
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $errors[] = 'Database is not reachable: ' . $e->getMessage();
        }

        if (!empty($errors)) {
            foreach ($errors as $error) {
                $this->error($error);
            }
            $this->line('');
            $this->error(count($errors) . ' production config check(s) failed.');
            return self::FAILURE;
        }

        $this->info('All production config checks passed.');
        return self::SUCCESS;
    }
}
