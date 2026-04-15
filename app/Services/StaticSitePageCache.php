<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Clears the nginx fastcgi cache for the jahongir-travel.uz static site.
 *
 * The vhost at /etc/nginx/sites-enabled/jahongir-travel.uz uses
 * fastcgi_cache with a 5-minute TTL, keyed at /var/cache/nginx/php/.
 * Any .php file edited under the static site serves stale bytecode
 * until the cache is purged or the TTL expires. Both the tour pricing
 * rollout and the auto-export scheduler use this helper so the purge
 * logic lives in exactly one place.
 */
class StaticSitePageCache
{
    private const CACHE_DIR = '/var/cache/nginx/php';

    /**
     * Purge every entry in the fastcgi cache directory.
     *
     * Returns true on success, false if the directory was missing or
     * the rm command returned non-zero. Never throws — callers should
     * treat cache purge as best-effort.
     */
    public function purge(): bool
    {
        if (! is_dir(self::CACHE_DIR)) {
            return false;
        }

        exec('rm -rf ' . escapeshellarg(self::CACHE_DIR) . '/* 2>/dev/null', $_, $rc);

        if ($rc !== 0) {
            Log::warning('Static site cache purge failed', ['dir' => self::CACHE_DIR, 'rc' => $rc]);

            return false;
        }

        return true;
    }
}
