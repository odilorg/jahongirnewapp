<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TourPriceTier;
use App\Models\TourProduct;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Build and push the static-site tour pricing catalog.
 *
 * Flow:
 *   buildPayload()         — read DB, shape array
 *   renderPhpFile()        — var_export into a valid PHP return file
 *   writeLocalTempFile()   — stage under storage/app/tours-export
 *   pushToWebsite()        — scp to remote temp, php -l, atomic mv
 *
 * export() is the orchestrator called by both the artisan command today
 * and by any future model observer (Phase 8.3b-2).
 *
 * The export is pricing-only on purpose. Content fields (title, description,
 * highlights) stay in the static pages until we intentionally migrate them.
 */
class TourCatalogExportService
{
    /**
     * @param  array<int, string>|null  $onlySlugs  restrict to listed slugs, null = all
     * @return array{tours_count:int, bytes:int, generated_at:string, remote_path:string, pushed:bool, skipped:array<int,string>}
     */
    public function export(?array $onlySlugs = null, bool $dryRun = false): array
    {
        $payload = $this->buildPayload($onlySlugs);
        $php     = $this->renderPhpFile($payload);
        $tmp     = $this->writeLocalTempFile($php);

        $remotePath = rtrim((string) config('tour_export.remote.dir'), '/')
            . '/' . config('tour_export.remote.file');

        if ($dryRun) {
            @unlink($tmp);

            return [
                'tours_count' => count($payload['tours']),
                'bytes'       => strlen($php),
                'generated_at'=> $payload['generated_at'],
                'remote_path' => $remotePath,
                'pushed'      => false,
                'skipped'     => $payload['_skipped'],
                'rendered_php'=> $php,
            ];
        }

        try {
            $this->pushToWebsite($tmp);
        } finally {
            @unlink($tmp);
        }

        return [
            'tours_count' => count($payload['tours']),
            'bytes'       => strlen($php),
            'generated_at'=> $payload['generated_at'],
            'remote_path' => $remotePath,
            'pushed'      => true,
            'skipped'     => $payload['_skipped'],
        ];
    }

    /**
     * Query DB and assemble the catalog array.
     *
     * Tours with no price tiers are silently skipped (logged). Tours with
     * is_active=false are excluded entirely.
     *
     * @param  array<int, string>|null  $onlySlugs
     * @return array{schema_version:int, generated_at:string, tours:array<string, array<string, mixed>>, _skipped:array<int, string>}
     */
    public function buildPayload(?array $onlySlugs = null): array
    {
        $query = TourProduct::query()
            ->where('is_active', true)
            ->with([
                'priceTiers' => fn ($q) => $q->orderBy('group_size'),
                'priceTiers.direction',
            ])
            ->orderBy('slug');

        if ($onlySlugs !== null && $onlySlugs !== []) {
            $query->whereIn('slug', $onlySlugs);
        }

        $tours   = [];
        $skipped = [];

        foreach ($query->get() as $product) {
            if ($product->priceTiers->isEmpty()) {
                Log::info('Tour skipped from export (no tiers)', ['slug' => $product->slug]);
                $skipped[] = $product->slug;
                continue;
            }

            $tours[$product->slug] = [
                'is_active'         => (bool) $product->is_active,
                'currency'          => 'USD',
                'starting_from_usd' => $this->formatPrice($product->starting_from_usd),
                'last_updated_at'   => optional($product->updated_at)->toIso8601String(),
                'directions'        => $this->buildDirections($product->priceTiers),
            ];
        }

        return [
            'schema_version' => (int) config('tour_export.schema_version', 1),
            'generated_at'   => Carbon::now()->toIso8601String(),
            'tours'          => $tours,
            '_skipped'       => $skipped,
        ];
    }

    /**
     * Group tiers by direction code → tour type → ordered array of tiers.
     *
     * We intentionally DO NOT fabricate short codes. The `direction.code`
     * column (e.g. 'sam-bukhara', 'bukhara-sam', 'default') is the single
     * source of truth and flows through untouched.
     *
     * @param  iterable<TourPriceTier>  $tiers
     * @return array<string, array<string, array<int, array{group_size:int, price_per_person_usd:int|float}>>>
     */
    private function buildDirections(iterable $tiers): array
    {
        $out = [];

        foreach ($tiers as $tier) {
            $code = $tier->direction?->code ?? 'default';
            $type = $tier->tour_type;

            $out[$code][$type][] = [
                'group_size'           => (int) $tier->group_size,
                'price_per_person_usd' => $this->formatPrice($tier->price_per_person_usd),
            ];
        }

        // Ensure tier arrays are sorted ascending by group_size within each
        // (direction, type). The query already orders by group_size so this
        // is defensive only.
        foreach ($out as $code => $types) {
            foreach ($types as $type => $tierList) {
                usort(
                    $out[$code][$type],
                    fn (array $a, array $b): int => $a['group_size'] <=> $b['group_size']
                );
            }
        }

        return $out;
    }

    /**
     * Serialize price naturally: integer when whole dollars, float when cents present.
     *
     * Per decision: do not force int cast, serialize from DB as-is. Current
     * prices are whole USD but we stay ready for cent-level pricing without
     * a schema change.
     */
    private function formatPrice(mixed $value): int|float
    {
        if ($value === null) {
            return 0;
        }

        $f = (float) $value;

        return $f == (int) $f ? (int) $f : $f;
    }

    /**
     * Render the payload as a valid PHP return file with a banner.
     */
    public function renderPhpFile(array $payload): string
    {
        // _skipped is internal — not exported.
        unset($payload['_skipped']);

        $exported = var_export($payload, true);

        $banner = <<<'BANNER'
<?php

declare(strict_types=1);

// AUTOGENERATED by `php artisan tours:export-website-data`.
// DO NOT EDIT BY HAND — changes will be overwritten on next export.
// Source of truth: jahongirnewapp TourProduct / TourPriceTier / TourProductDirection.


BANNER;

        return $banner . 'return ' . $exported . ';' . PHP_EOL;
    }

    /**
     * Write the rendered PHP to a unique temp file under storage/app/tours-export.
     */
    public function writeLocalTempFile(string $php): string
    {
        $dir = (string) config('tour_export.local_temp_dir');

        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create local temp dir: {$dir}");
        }

        $path = $dir . '/tours.php.tmp.' . uniqid('', true);

        if (file_put_contents($path, $php) === false) {
            throw new RuntimeException("Failed to write local temp file: {$path}");
        }

        // Lint the local file before we even attempt to push it. Catches
        // any var_export corner case (there shouldn't be any, but cheap
        // insurance).
        $lint = new Process(['php', '-l', $path]);
        $lint->setTimeout((float) config('tour_export.process_timeout', 30));
        $lint->run();

        if (! $lint->isSuccessful()) {
            $err = trim($lint->getErrorOutput() ?: $lint->getOutput());
            @unlink($path);
            throw new RuntimeException("Rendered PHP failed local lint: {$err}");
        }

        return $path;
    }

    /**
     * Copy local file to remote temp, lint it on the remote host, then
     * atomically rename to the live path. Any failure throws and leaves
     * the live file untouched.
     */
    public function pushToWebsite(string $localTempPath): void
    {
        $cfg = config('tour_export');

        $key  = (string) $cfg['ssh']['key'];
        $host = (string) $cfg['ssh']['host'];
        $user = (string) $cfg['ssh']['user'];
        $port = (int) $cfg['ssh']['port'];

        if (! is_file($key)) {
            throw new RuntimeException(
                "SSH key missing at {$key}. See `php artisan tours:export-website-data --help` for provisioning steps."
            );
        }

        $remoteDir  = rtrim((string) $cfg['remote']['dir'], '/');
        $remoteFile = (string) $cfg['remote']['file'];
        $remoteLive = $remoteDir . '/' . $remoteFile;
        $remoteTmp  = $remoteLive . '.tmp.' . uniqid('', true);

        // 1. Ensure remote directory exists. Using a single-quoted remote
        // command so $HOME expands server-side.
        $this->runProcess([
            'ssh',
            '-i', $key,
            '-p', (string) $port,
            '-o', 'StrictHostKeyChecking=accept-new',
            '-o', 'BatchMode=yes',
            "{$user}@{$host}",
            "mkdir -p ~/{$remoteDir}",
        ], 'ensure remote dir');

        // 2. Copy to remote temp.
        $this->runProcess([
            'scp',
            '-i', $key,
            '-P', (string) $port,
            '-o', 'StrictHostKeyChecking=accept-new',
            '-o', 'BatchMode=yes',
            $localTempPath,
            "{$user}@{$host}:~/{$remoteTmp}",
        ], 'scp to remote temp');

        // 3. Lint on the remote server before going live.
        $this->runProcess([
            'ssh',
            '-i', $key,
            '-p', (string) $port,
            '-o', 'StrictHostKeyChecking=accept-new',
            '-o', 'BatchMode=yes',
            "{$user}@{$host}",
            "php -l ~/{$remoteTmp}",
        ], 'remote php -l');

        // 4. Atomic rename.
        $this->runProcess([
            'ssh',
            '-i', $key,
            '-p', (string) $port,
            '-o', 'StrictHostKeyChecking=accept-new',
            '-o', 'BatchMode=yes',
            "{$user}@{$host}",
            "mv ~/{$remoteTmp} ~/{$remoteLive}",
        ], 'atomic rename');
    }

    /**
     * @param  array<int, string>  $cmd
     */
    private function runProcess(array $cmd, string $label): void
    {
        $process = new Process($cmd);
        $process->setTimeout((float) config('tour_export.process_timeout', 30));
        $process->run();

        if (! $process->isSuccessful()) {
            $stderr = trim($process->getErrorOutput() ?: $process->getOutput());
            throw new RuntimeException("Tour export step failed [{$label}]: {$stderr}");
        }
    }
}
