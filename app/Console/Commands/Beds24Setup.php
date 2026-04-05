<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Bootstrap Beds24 API v2 credentials from a one-time invite token.
 *
 * Usage:
 *   php artisan beds24:setup <invite-token>
 *
 * How to get an invite token:
 *   Beds24 → Account → Account Access → Developer API → Invite Tokens → Add
 */
class Beds24Setup extends Command
{
    protected $signature = 'beds24:setup {token : The one-time invite token from Beds24 Developer API}';
    protected $description = 'Bootstrap Beds24 API v2 refresh token from a Beds24 invite token';

    private const CACHE_KEY_REFRESH_TOKEN = 'beds24_rotated_refresh_token';
    private const CACHE_KEY = 'beds24_access_token';
    private const CACHE_KEY_FALLBACK = 'beds24_access_token_fallback';

    public function handle(): int
    {
        $inviteToken = $this->argument('token');

        $this->info('Exchanging Beds24 invite token for refresh token...');

        // Try both known base URLs for the setup endpoint
        $response = Http::withHeaders([
            'inviteToken' => $inviteToken,
            'accept'      => 'application/json',
        ])->timeout(15)->get('https://beds24.com/api/v2/authentication/setup');

        // Fallback: some Beds24 accounts use api.beds24.com subdomain
        if (!$response->successful() || empty($response->json('refreshToken'))) {
            $response = Http::withHeaders([
                'inviteToken' => $inviteToken,
                'accept'      => 'application/json',
            ])->timeout(15)->get('https://api.beds24.com/v2/authentication/setup');
        }

        $result = $response->json();

        if (!$response->successful() || empty($result['refreshToken'])) {
            $this->error('Failed: ' . ($result['error'] ?? json_encode($result)));
            $this->line('');
            $this->line('Make sure the invite token is freshly generated and used within seconds.');
            $this->line('Beds24 → Account → Account Access → Developer API → Invite Tokens → Add');
            return self::FAILURE;
        }

        $refreshToken = $result['refreshToken'];

        // Store in cache (60-day TTL — scheduler will keep it alive via daily rotation)
        Cache::put(self::CACHE_KEY_REFRESH_TOKEN, $refreshToken, now()->addDays(60));

        // Clear any stale access tokens so next request uses the new refresh token
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::CACHE_KEY_FALLBACK);

        $this->info('✓ Refresh token obtained and cached.');
        $this->line('');
        $this->warn('IMPORTANT: Also update BEDS24_API_V2_REFRESH_TOKEN in .env as a fallback:');
        $this->line($refreshToken);
        $this->line('');

        // Immediately verify by getting an access token
        $this->info('Verifying — fetching access token...');
        $verifyResp = Http::withHeaders([
            'refreshToken' => $refreshToken,
            'accept'       => 'application/json',
        ])->timeout(15)->get('https://beds24.com/api/v2/authentication/token');

        if (!$verifyResp->successful() || empty($verifyResp->json('token'))) {
            $verifyResp = Http::withHeaders([
                'refreshToken' => $refreshToken,
                'accept'       => 'application/json',
            ])->timeout(15)->get('https://api.beds24.com/v2/authentication/token');
        }

        $verifyResult = $verifyResp->json();

        if (!$verifyResp->successful() || empty($verifyResult['token'])) {
            $this->error('Access token fetch failed: ' . json_encode($verifyResult));
            return self::FAILURE;
        }

        $accessToken = $verifyResult['token'];
        $expiresIn   = $verifyResult['expiresIn'] ?? 86400;

        Cache::put(self::CACHE_KEY, $accessToken, now()->addSeconds($expiresIn - 300));
        Cache::put(self::CACHE_KEY_FALLBACK, $accessToken, now()->addSeconds($expiresIn + 7200));

        // Beds24 may rotate the refresh token even here
        if (!empty($verifyResult['refreshToken'])) {
            Cache::put(self::CACHE_KEY_REFRESH_TOKEN, $verifyResult['refreshToken'], now()->addDays(60));
            $this->line('Refresh token rotated after verification — new token cached.');
        }

        Log::info('beds24:setup completed — new refresh token bootstrapped');

        $this->info("✓ Access token valid for {$expiresIn}s. Beds24 API is ready.");
        return self::SUCCESS;
    }
}
