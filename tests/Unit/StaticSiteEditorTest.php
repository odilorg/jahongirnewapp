<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\StaticSiteEditor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class StaticSiteEditorTest extends TestCase
{
    private StaticSiteEditor $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->editor = new StaticSiteEditor();
    }

    private function samplePage(
        int $ldPrice = 143,
        string $tbody = "<tr><td>1 person</td><td>\$286</td></tr>\n<tr><td>2 persons</td><td>\$176</td></tr>"
    ): string {
        return <<<HTML
<?php \$schema_json = '<script type="application/ld+json">{"@context":"https://schema.org","@type":"TouristTrip","name":"Yurt","offers":{"@type":"Offer","price":"{$ldPrice}","priceCurrency":"USD"},"provider":{"@type":"TravelAgency","name":"Jahongir Travel"}}</script>'; ?>
<?php \$faq_schema = '<script type="application/ld+json">{"@context":"https://schema.org","@type":"FAQPage","mainEntity":[{"@type":"Question","name":"q","acceptedAnswer":{"@type":"Answer","text":"from \$130 per person"}}]}</script>'; ?>
<html>
<body>
<table class="yurt-price-card">
<thead><tr><th>Group size</th><th>Price per person</th></tr></thead>
<tbody>
{$tbody}
</tbody>
</table>
</body>
</html>
HTML;
    }

    public function test_preflight_accepts_a_well_formed_page(): void
    {
        $errors = $this->editor->preflight($this->samplePage());
        $this->assertSame([], $errors);
    }

    public function test_preflight_rejects_already_converted_pages(): void
    {
        $converted = "<?php \$tour_pricing_slug = 'x'; ?>\n" . $this->samplePage();
        $errors = $this->editor->preflight($converted);
        $this->assertContains('already converted (tour_pricing_slug marker present)', $errors);
    }

    public function test_preflight_rejects_pages_with_multiple_yurt_price_cards(): void
    {
        $html = $this->samplePage() . '<table class="yurt-price-card"><tbody></tbody></table>';
        $errors = $this->editor->preflight($html);
        $this->assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'expected exactly one yurt-price-card')));
    }

    public function test_preflight_rejects_pages_with_no_yurt_price_card(): void
    {
        $html = str_replace('class="yurt-price-card"', 'class="other-table"', $this->samplePage());
        $errors = $this->editor->preflight($html);
        $this->assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'found 0')));
    }

    public function test_inject_loader_block_is_idempotent(): void
    {
        $html = $this->samplePage();
        $once  = $this->editor->injectLoaderBlock($html, 'yurt-camp-tour', 'sam-bukhara', 'private');
        $twice = $this->editor->injectLoaderBlock($once, 'yurt-camp-tour', 'sam-bukhara', 'private');
        $this->assertSame($once, $twice);
        $this->assertStringContainsString("\$tour_pricing_slug      = 'yurt-camp-tour';", $once);
        $this->assertStringContainsString("\$tour_pricing_direction = 'sam-bukhara';", $once);
    }

    public function test_replace_price_card_tbody_wraps_in_conditional_with_fallback(): void
    {
        $html = $this->samplePage();
        $out  = $this->editor->replacePriceCardTbody($html);

        $this->assertStringContainsString('if (!empty($tour_pricing_tiers)):', $out);
        $this->assertStringContainsString('tour_pricing_render_row', $out);
        $this->assertStringContainsString('<?php else: ?>', $out);
        // Fallback must contain the original rows verbatim
        $this->assertStringContainsString('<tr><td>1 person</td><td>$286</td></tr>', $out);
        $this->assertStringContainsString('<tr><td>2 persons</td><td>$176</td></tr>', $out);
    }

    public function test_replace_price_card_tbody_fails_on_multiple_tables(): void
    {
        $this->expectException(RuntimeException::class);
        $html = $this->samplePage() . '<table class="yurt-price-card"><tbody></tbody></table>';
        $this->editor->replacePriceCardTbody($html);
    }

    public function test_sync_offers_price_only_touches_schema_json_line(): void
    {
        $html = $this->samplePage(ldPrice: 40);
        $out  = $this->editor->syncOffersPrice($html, 75);

        // schema_json got updated
        $this->assertStringContainsString('"offers":{"@type":"Offer","price":"75"', $out);
        // faq_schema prose '$130' is untouched
        $this->assertStringContainsString('from $130 per person', $out);
        // No accidental extra changes to the schema block
        $this->assertSame(
            substr_count($html, '"offers":'),
            substr_count($out, '"offers":')
        );
    }

    public function test_sync_offers_price_is_idempotent_when_already_in_sync(): void
    {
        $html = $this->samplePage(ldPrice: 75);
        $out  = $this->editor->syncOffersPrice($html, 75);
        $this->assertSame($html, $out);
    }

    public function test_sync_offers_price_supports_decimal_values(): void
    {
        $html = $this->samplePage(ldPrice: 40);
        $out  = $this->editor->syncOffersPrice($html, 75.5);
        $this->assertStringContainsString('"price":"75.5"', $out);
    }
}
