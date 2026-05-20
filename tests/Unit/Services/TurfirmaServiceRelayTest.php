<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\TurfirmaService;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Covers the private `fetchDataFromApis` helper that drives auto-fill for
 * the Filament Contract → "Create Turfirma" inline form.
 *
 * The helper is private (kept private to avoid widening the surface for a
 * UI-only feature); reflection is used to invoke it directly. Tests focus
 * on response normalization — the part most likely to silently regress.
 */
class TurfirmaServiceRelayTest extends TestCase
{
    private function invokeFetch(string $tin): ?array
    {
        $m = new ReflectionMethod(TurfirmaService::class, 'fetchDataFromApis');
        $m->setAccessible(true);
        return $m->invoke(null, $tin);
    }

    /** @test */
    public function returns_null_when_relay_url_not_configured(): void
    {
        config()->set('services.tin_lookup.relay_url', null);
        $this->assertNull($this->invokeFetch('300965341'));
    }

    /** @test */
    public function didox_flat_payload_is_returned_as_is(): void
    {
        config()->set('services.tin_lookup.relay_url', 'http://relay');
        Http::fake([
            'relay/didox/info/*' => Http::response([
                'tin' => '300965341',
                'shortName' => '"JAXONGIR TRAVEL" OK',
                'name' => '"JAXONGIR TRAVEL" OILAVIY KORXONA',
                'address' => 'CHIROQCHI, 4  ',
                'mfo' => '00083',
                'account' => '20208000704734557001',
                'director' => 'JAXANGIROV ODILJON SHAKIROVICH',
            ], 200),
        ]);

        $out = $this->invokeFetch('300965341');

        $this->assertSame('"JAXONGIR TRAVEL" OK', $out['shortName']);
        $this->assertSame('00083', $out['mfo']);
        $this->assertSame('JAXANGIROV ODILJON SHAKIROVICH', $out['director']);
    }

    /** @test */
    public function falls_back_to_soliq_and_flattens_company_envelope(): void
    {
        config()->set('services.tin_lookup.relay_url', 'http://relay');
        Http::fake([
            'relay/didox/info/*' => Http::response(['error' => 'down'], 502),
            'relay/company/info/*' => Http::response([
                'company' => [
                    'tin' => '300965341',
                    'shortName' => '"JAXONGIR TRAVEL" OK',
                    'name' => '"JAXONGIR TRAVEL" OILAVIY KORXONA',
                    'streetName' => 'CHIROQCHI, 4',
                ],
                'director' => [
                    'lastName' => 'JAXANGIROV',
                    'firstName' => 'ODILJON',
                    'middleName' => 'SHAKIROVICH',
                ],
            ], 200),
        ]);

        $out = $this->invokeFetch('300965341');

        // Flattened to didox shape so the Turfirma::create mapping stays uniform.
        $this->assertSame('"JAXONGIR TRAVEL" OK', $out['shortName']);
        $this->assertSame('CHIROQCHI, 4', $out['address']);
        $this->assertSame('JAXANGIROV ODILJON SHAKIROVICH', $out['director']);
        $this->assertNull($out['mfo'], 'soliq endpoint has no bank info');
    }

    /** @test */
    public function returns_null_when_both_providers_fail(): void
    {
        config()->set('services.tin_lookup.relay_url', 'http://relay');
        Http::fake([
            'relay/didox/info/*' => Http::response(['error' => 'down'], 502),
            'relay/company/info/*' => Http::response(['error' => 'down'], 502),
        ]);

        $this->assertNull($this->invokeFetch('300965341'));
    }
}
