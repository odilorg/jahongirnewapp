<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Models\TourProduct;
use App\Services\Agent\TourCatalogReader;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Contract tests for the Phase-2 read-only tour tools. Load-bearing invariant:
 * an unresolved quote is manual_quote_needed, NEVER a guessed price.
 */
class TourCatalogReaderTest extends TestCase
{
    use DatabaseTransactions;

    private function reader(): TourCatalogReader
    {
        return new TourCatalogReader();
    }

    /** Yurt-style: direction "default" present, tiers GLOBAL (direction-agnostic). */
    private function makeYurt(): TourProduct
    {
        $p = TourProduct::create([
            'slug' => 'test-yurt-'.uniqid(), 'title' => 'Test Yurt &amp; Aydarkul | Jahongir',
            'region' => 'samarkand', 'tour_type' => 'private',
            'duration_days' => 2, 'duration_nights' => 1, 'starting_from_usd' => 145, 'is_active' => true,
            'includes' => "• Transport\n• 1 night yurt", 'excludes' => "• Entrance fees",
            'highlights' => ['transport only — no English guide', 'cold desert nights'],
        ]);
        $p->directions()->create(['code' => 'default', 'name' => 'Default', 'is_active' => true, 'sort_order' => 0]);
        foreach ([[1, 286], [2, 176], [3, 145]] as [$g, $pp]) {
            $p->priceTiers()->create([
                'group_size' => $g, 'price_per_person_usd' => $pp, 'tour_type' => 'private',
                'tour_product_direction_id' => null, 'is_active' => true,
            ]);
        }

        return $p->fresh(TourCatalogReader::EAGER);
    }

    /** Day-trip style: tiers bound to the "default" direction, private only. */
    private function makeDaytrip(): TourProduct
    {
        $p = TourProduct::create([
            'slug' => 'test-daytrip-'.uniqid(), 'title' => 'Test Daytrip', 'region' => 'samarkand',
            'tour_type' => 'private', 'duration_days' => 1, 'starting_from_usd' => 48, 'is_active' => true,
        ]);
        $dir = $p->directions()->create(['code' => 'default', 'name' => 'Default', 'is_active' => true, 'sort_order' => 0]);
        foreach ([[1, 108], [2, 60], [3, 48]] as [$g, $pp]) {
            $p->priceTiers()->create([
                'group_size' => $g, 'price_per_person_usd' => $pp, 'tour_type' => 'private',
                'tour_product_direction_id' => $dir->id, 'is_active' => true,
            ]);
        }

        return $p->fresh(TourCatalogReader::EAGER);
    }

    private function makeNoTierCustom(): TourProduct
    {
        return TourProduct::create([
            'slug' => 'test-custom-'.uniqid(), 'title' => 'Test 10-Day Custom', 'region' => 'uzbekistan',
            'tour_type' => 'private', 'duration_days' => 10, 'is_active' => true,
        ]);
    }

    // ── quote-calculate ────────────────────────────────────────────────

    public function test_quote_resolves_exact_tier(): void
    {
        $p = $this->makeYurt();
        $r = $this->reader()->quote($p->slug, 2, 'default', 'private');

        $this->assertTrue($r['resolvable']);
        $this->assertFalse($r['manual_quote_needed']);
        $this->assertSame(176.0, $r['matched_tier']['price_per_person_usd']);
        $this->assertSame(352.0, $r['matched_tier']['total_usd']);
        $this->assertTrue($r['matched_tier']['is_exact_match']);
    }

    public function test_quote_falls_to_largest_tier_for_big_group(): void
    {
        $p = $this->makeYurt();
        $r = $this->reader()->quote($p->slug, 6, 'default', 'private');

        $this->assertTrue($r['resolvable']);
        $this->assertSame(3, $r['matched_tier']['group_size']);   // generous: 6 → g3 rate
        $this->assertSame(145.0, $r['matched_tier']['price_per_person_usd']);
        $this->assertFalse($r['matched_tier']['is_exact_match']);
    }

    public function test_group_request_on_private_only_tour_is_manual_quote(): void
    {
        $p = $this->makeDaytrip();
        $r = $this->reader()->quote($p->slug, 2, 'default', 'group');

        $this->assertFalse($r['resolvable']);
        $this->assertTrue($r['manual_quote_needed']);
        $this->assertSame('no_group_tiers', $r['reason']);
        $this->assertNull($r['matched_tier']);
    }

    public function test_no_tier_custom_tour_is_manual_quote(): void
    {
        $p = $this->makeNoTierCustom();
        $r = $this->reader()->quote($p->slug, 4, 'default', 'private');

        $this->assertFalse($r['resolvable']);
        $this->assertTrue($r['manual_quote_needed']);
        $this->assertSame('no_tiers_custom_tour', $r['reason']);
    }

    public function test_unknown_tour_is_manual_quote(): void
    {
        $r = $this->reader()->quote('does-not-exist-xyz', 2, 'default', 'private');

        $this->assertFalse($r['resolvable']);
        $this->assertSame('tour_not_found', $r['reason']);
    }

    public function test_invalid_party_size_does_not_guess(): void
    {
        $p = $this->makeYurt();
        $r = $this->reader()->quote($p->slug, 0, 'default', 'private');

        $this->assertFalse($r['resolvable']);
        $this->assertSame('invalid_party_size', $r['reason']);
    }

    public function test_direction_bound_tiers_resolve_via_default(): void
    {
        $p = $this->makeDaytrip();
        $r = $this->reader()->quote($p->slug, 2, 'default', 'private');

        $this->assertTrue($r['resolvable']);
        $this->assertSame(60.0, $r['matched_tier']['price_per_person_usd']);
    }

    // ── tour-prices ────────────────────────────────────────────────────

    public function test_prices_returns_tiers_and_flags(): void
    {
        $p = $this->makeYurt();
        $r = $this->reader()->prices($p->slug);

        $this->assertCount(3, $r['tiers']);
        $this->assertFalse($r['has_group_tiers']);
        $this->assertFalse($r['manual_quote']);
        $this->assertSame(352.0, $r['tiers'][1]['total_for_group']);
        $this->assertSame('GLOBAL', $r['tiers'][0]['direction']);
    }

    public function test_prices_unknown_tour_is_null(): void
    {
        $this->assertNull($this->reader()->prices('nope-xyz'));
    }

    // ── tour-catalog ───────────────────────────────────────────────────

    public function test_catalog_lists_active_and_flags_manual_quote(): void
    {
        $yurt = $this->makeYurt();
        $custom = $this->makeNoTierCustom();

        $cat = $this->reader()->catalog();
        $bySlug = collect($cat['tours'])->keyBy('slug');

        $this->assertTrue($bySlug[$yurt->slug]['has_tiers']);
        $this->assertFalse($bySlug[$yurt->slug]['manual_quote']);
        $this->assertFalse($bySlug[$custom->slug]['has_tiers']);
        $this->assertTrue($bySlug[$custom->slug]['manual_quote']);
        // HTML entity + SEO suffix cleaned in title
        $this->assertStringContainsString('Test Yurt & Aydarkul', $bySlug[$yurt->slug]['title']);
    }

    // ── command wiring (CLI returns valid JSON) ────────────────────────

    public function test_quote_command_emits_json(): void
    {
        $p = $this->makeYurt();
        Artisan::call('agent:quote-calculate', ['--tour' => $p->slug, '--party' => '2', '--compact' => true]);
        $out = json_decode(trim(Artisan::output()), true);

        $this->assertIsArray($out);
        $this->assertTrue($out['resolvable']);
        // JSON numbers carry no PHP type → loose compare (352 == 352.0).
        $this->assertEquals(352.0, $out['matched_tier']['total_usd']);
    }
}
