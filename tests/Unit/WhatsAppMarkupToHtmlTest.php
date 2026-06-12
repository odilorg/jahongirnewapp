<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\WhatsAppMarkupToHtml;
use PHPUnit\Framework\TestCase;

/**
 * The guest reminder is authored in WhatsApp markup and reused verbatim
 * for the email channel. These tests lock the markup→HTML conversion so
 * an email never renders literal `*` or a collapsed single line.
 */
class WhatsAppMarkupToHtmlTest extends TestCase
{
    /** @test */
    public function bold_markup_becomes_strong(): void
    {
        $html = WhatsAppMarkupToHtml::convert('*Pickup* at 09:00');
        $this->assertStringContainsString('<strong>Pickup</strong>', $html);
        $this->assertStringNotContainsString('*', $html);
    }

    /** @test */
    public function newlines_become_breaks(): void
    {
        $html = WhatsAppMarkupToHtml::convert("Line one\nLine two");
        $this->assertStringContainsString('<br', $html);
    }

    /** @test */
    public function bare_url_is_linkified(): void
    {
        $html = WhatsAppMarkupToHtml::convert('Map: https://maps.google.com/?q=Aydarkul');
        $this->assertStringContainsString('<a href="https://maps.google.com/?q=Aydarkul">', $html);
    }

    /** @test */
    public function untrusted_input_is_escaped_before_markup(): void
    {
        // A guest name containing HTML must not inject markup.
        $html = WhatsAppMarkupToHtml::convert('Hi <script>alert(1)</script>');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
