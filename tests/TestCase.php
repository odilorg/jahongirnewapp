<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Override to retry migrate:fresh on InnoDB deadlock (SQLSTATE 40001 / error 1213).
     *
     * The production app shares the same MySQL server as the test DB. Under
     * load, InnoDB can deadlock between the production app's open transactions
     * and the `db:wipe` DROP TABLE statement. A 2-second back-off clears the
     * contention in virtually all cases.
     *
     * All other behaviour is identical to the parent implementation.
     */
    protected function refreshTestDatabase(): void
    {
        if (! RefreshDatabaseState::$migrated) {
            $this->runMigrateFreshWithRetry();
            $this->app[Kernel::class]->setArtisan(null);
            RefreshDatabaseState::$migrated = true;
        }

        $this->beginDatabaseTransaction();
    }

    private function runMigrateFreshWithRetry(int $maxAttempts = 3): void
    {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $this->artisan('migrate:fresh', $this->migrateFreshUsing());
                return;
            } catch (QueryException $e) {
                if ($attempt < $maxAttempts && str_contains($e->getMessage(), '1213')) {
                    sleep(2);
                    continue;
                }
                throw $e;
            }
        }
    }
}
