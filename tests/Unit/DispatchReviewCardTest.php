<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Phase 1.7.2 — End-of-tour TripAdvisor review-card instructions in
 * driver/guide dispatch templates.
 *
 * Pins the safety/compliance rules for what can and cannot appear in
 * the supplier-facing review block. Violations would risk reputation
 * (drivers pressuring guests, guests feeling solicited for ratings).
 */
final class DispatchReviewCardTest extends TestCase
{
    private function loadTemplate(string $key): string
    {
        $templates = require base_path('config/inquiry_templates.php');
        return (string) $templates[$key];
    }

    /** @test */
    public function driver_dispatch_includes_tripadvisor_review_instruction(): void
    {
        $tpl = $this->loadTemplate('driver_dispatch_uz');
        $this->assertStringContainsString('TripAdvisor review', $tpl);
        $this->assertStringContainsString('QR', $tpl);
    }

    /** @test */
    public function guide_dispatch_includes_tripadvisor_review_instruction(): void
    {
        $tpl = $this->loadTemplate('guide_dispatch_uz');
        $this->assertStringContainsString('TripAdvisor review', $tpl);
        $this->assertStringContainsString('QR', $tpl);
    }

    /** @test */
    public function driver_dispatch_does_not_solicit_5_stars(): void
    {
        $tpl = $this->loadTemplate('driver_dispatch_uz');
        // Block must include a "do not ask for 5 stars" guidance.
        // We assert it explicitly contains the negation so future
        // edits that drop the guidance fail loudly.
        $this->assertMatchesRegularExpression('/5 юлдуз сўраманг|5 yulduz so\'?ramang|5 stars/u', $tpl);
        // And must NOT phrase it as a request to bring 5 stars.
        $this->assertStringNotContainsString('5 yulduz olib kel', $tpl);
        $this->assertStringNotContainsString('please give us 5 stars', $tpl);
    }

    /** @test */
    public function guide_dispatch_does_not_solicit_5_stars(): void
    {
        $tpl = $this->loadTemplate('guide_dispatch_uz');
        $this->assertMatchesRegularExpression('/5 юлдуз сўраманг|5 yulduz so\'?ramang|5 stars/u', $tpl);
        $this->assertStringNotContainsString('5 yulduz olib kel', $tpl);
        $this->assertStringNotContainsString('please give us 5 stars', $tpl);
    }

    /** @test */
    public function dispatch_blocks_do_not_mention_incentive_or_bonus(): void
    {
        foreach (['driver_dispatch_uz', 'guide_dispatch_uz'] as $key) {
            $tpl = strtolower($this->loadTemplate($key));
            // Russian/Uzbek/English bonus/incentive terms — none should appear
            $this->assertStringNotContainsString('bonus', $tpl);
            $this->assertStringNotContainsString('бонус', $tpl);
            $this->assertStringNotContainsString('incentive', $tpl);
            $this->assertStringNotContainsString('reward', $tpl);
            $this->assertStringNotContainsString('премия', $tpl);
        }
    }

    /** @test */
    public function review_card_url_placeholder_present_in_both_templates(): void
    {
        foreach (['driver_dispatch_uz', 'guide_dispatch_uz'] as $key) {
            $tpl = $this->loadTemplate($key);
            $this->assertStringContainsString('{review_card_url}', $tpl,
                "Template {$key} must carry the {review_card_url} placeholder");
        }
    }

    /** @test */
    public function tripadvisor_review_url_config_resolves(): void
    {
        $url = config('services.tripadvisor.review_url');
        $this->assertIsString($url);
        $this->assertStringContainsString('tripadvisor.com', $url);
    }

    /** @test */
    public function review_card_url_config_resolves(): void
    {
        $url = config('services.tripadvisor.review_card_url');
        $this->assertIsString($url);
        $this->assertNotEmpty($url);
    }
}
