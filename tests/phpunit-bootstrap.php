<?php

// Remove the cached config so phpunit.xml <env> overrides (DB_DATABASE,
// CACHE_DRIVER, SESSION_DRIVER) take effect at boot time.
//
// config:cache bakes in production values (DB_DATABASE=jahongir) which
// silently override phpunit's injected env vars. Deleting it here is
// safe: php-fpm and queue workers never read this file between test runs,
// and it is rebuilt by the deploy script on every release.
@unlink(__DIR__.'/../bootstrap/cache/config.php');

require __DIR__.'/../vendor/autoload.php';
