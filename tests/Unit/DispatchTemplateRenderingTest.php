<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Verify that all dispatch templates have their placeholders resolved
 * and no raw {tokens} leak into the final message.
 */
class DispatchTemplateRenderingTest extends TestCase
{
    private function loadTemplate(string $key): string
    {
        $templates = require base_path('config/inquiry_templates.php');

        $this->assertArrayHasKey($key, $templates, "Template '{$key}' not found in config/inquiry_templates.php");

        return $templates[$key];
    }

    private function extractPlaceholders(string $template): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $template, $matches);

        return array_unique($matches[1]);
    }

    /**
     * Standard replacements that cover all known placeholders across
     * all templates. If a template introduces a new placeholder, this
     * test will fail until it's added here — which is the point.
     */
    private function allReplacements(): array
    {
        return [
            '{reference}'                  => 'INQ-2026-000001',
            '{tour}'                       => 'Yurt Camp Tour',
            '{travel_date}'                => '18-Aprel, 2026',
            '{pickup_time}'                => '09:00',
            '{pickup_point}'               => 'Registan Plaza Hotel',
            '{dropoff_point}'              => 'Bukhara',
            '{pax}'                        => '2 kishi',
            '{customer_name}'              => 'Anna Franzoni',
            '{customer_name_with_country}' => 'Anna Franzoni (Italy)',
            '{customer_phone}'             => '+393209476278',
            '{driver_name}'                => 'Muhammad',
            '{driver_phone}'               => '+998901234567',
            '{guide_name}'                 => 'Mehroj',
            '{guide_phone}'                => '+998507774207',
            '{notes}'                      => '',
            '{name}'                       => 'Anna',
            '{date}'                       => 'April 18, 2026',
            '{price}'                      => '$450.00',
            '{link}'                       => 'https://pay2.octo.uz/pay/test',
            '{accommodation}'              => 'Sputnik Yurt Camp',
            '{stay_date}'                  => '18 апреля',
            '{nights}'                     => '1',
            '{guest_count}'                => '4',
            '{meal_plan}'                  => 'dinner + breakfast',
        ];
    }

    private function renderTemplate(string $key): string
    {
        $template = $this->loadTemplate($key);
        $replacements = $this->allReplacements();

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    public function test_driver_dispatch_uz_has_no_raw_placeholders(): void
    {
        $rendered = $this->renderTemplate('driver_dispatch_uz');
        $this->assertDoesNotMatchRegularExpression('/\{[a-z_]+\}/', $rendered,
            "Raw placeholder found in driver_dispatch_uz: {$rendered}");
    }

    public function test_guide_dispatch_uz_has_no_raw_placeholders(): void
    {
        $rendered = $this->renderTemplate('guide_dispatch_uz');
        $this->assertDoesNotMatchRegularExpression('/\{[a-z_]+\}/', $rendered,
            "Raw placeholder found in guide_dispatch_uz: {$rendered}");
    }

    public function test_accommodation_dispatch_ru_has_no_raw_placeholders(): void
    {
        $rendered = $this->renderTemplate('accommodation_dispatch_ru');
        $this->assertDoesNotMatchRegularExpression('/\{[a-z_]+\}/', $rendered,
            "Raw placeholder found in accommodation_dispatch_ru: {$rendered}");
    }

    public function test_wa_initial_has_no_raw_placeholders(): void
    {
        $rendered = $this->renderTemplate('wa_initial');
        $this->assertDoesNotMatchRegularExpression('/\{[a-z_]+\}/', $rendered);
    }

    public function test_wa_generate_and_send_has_no_raw_placeholders(): void
    {
        $rendered = $this->renderTemplate('wa_generate_and_send');
        $this->assertDoesNotMatchRegularExpression('/\{[a-z_]+\}/', $rendered);
    }

    public function test_driver_template_includes_guide_placeholder(): void
    {
        $template = $this->loadTemplate('driver_dispatch_uz');
        $this->assertStringContainsString('{guide_name}', $template);
        $this->assertStringContainsString('{guide_phone}', $template);
    }

    public function test_guide_template_includes_driver_placeholder(): void
    {
        $template = $this->loadTemplate('guide_dispatch_uz');
        $this->assertStringContainsString('{driver_name}', $template);
        $this->assertStringContainsString('{driver_phone}', $template);
    }

    public function test_accommodation_template_includes_driver_and_guide(): void
    {
        $template = $this->loadTemplate('accommodation_dispatch_ru');
        $this->assertStringContainsString('{driver_name}', $template);
        $this->assertStringContainsString('{guide_name}', $template);
    }
}
