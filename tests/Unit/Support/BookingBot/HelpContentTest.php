<?php

declare(strict_types=1);

namespace Tests\Unit\Support\BookingBot;

use App\Support\BookingBot\HelpContent;
use PHPUnit\Framework\TestCase;

final class HelpContentTest extends TestCase
{
    public function test_renders_non_empty_string(): void
    {
        $out = HelpContent::render();
        $this->assertIsString($out);
        $this->assertNotEmpty(trim($out));
    }

    public function test_fits_in_single_telegram_message(): void
    {
        // Telegram hard limit is 4096 chars per sendMessage.
        // We want a safety margin — reject if >4000.
        $out = HelpContent::render();
        $this->assertLessThan(
            4000,
            mb_strlen($out),
            'Help text must fit in one Telegram message.'
        );
    }

    public function test_contains_every_intent_keyword(): void
    {
        $out = HelpContent::render();

        // Anchor strings for each intent. We search case-insensitively
        // against the rendered body — this is the contract that /help
        // covers every supported command.
        $anchors = [
            'view'           => 'view bookings',
            'show'           => 'show one booking',
            'search'         => 'search guest',
            'availability'   => 'check availability',
            'create'         => 'create booking',
            'group'          => 'group booking',
            'modify'         => 'modify booking',
            'cancel'         => 'cancel booking',
        ];

        foreach ($anchors as $intent => $needle) {
            $this->assertStringContainsStringIgnoringCase(
                $needle,
                $out,
                "Missing section for intent: {$intent}"
            );
        }
    }

    public function test_every_create_example_includes_property(): void
    {
        $out = HelpContent::render();
        // Split into lines, find lines that start with "• book "
        // (the create-booking examples). Every one of them MUST
        // contain " at " followed by a known property alias — we
        // refuse to ship a /help where an example command is
        // ambiguous across properties.
        $lines = preg_split('/\R/u', $out) ?: [];
        $bookingLines = array_filter(
            $lines,
            static fn (string $l): bool => str_starts_with(trim($l), '• book '),
        );

        $this->assertNotEmpty($bookingLines, 'Expected at least one create-booking example.');

        foreach ($bookingLines as $line) {
            $this->assertMatchesRegularExpression(
                '/ at (jahongir hotel|premium|jahongir_hotel|jahongir_premium|jahongir premium|hotel)/i',
                $line,
                "Create-booking example missing property hint: {$line}"
            );
        }
    }

    public function test_contains_properties_section_with_aliases(): void
    {
        $out = HelpContent::render();
        $this->assertStringContainsStringIgnoringCase('Jahongir Hotel', $out);
        $this->assertStringContainsStringIgnoringCase('Jahongir Premium', $out);
        $this->assertStringContainsStringIgnoringCase('jahongir_hotel', $out);
        $this->assertStringContainsStringIgnoringCase('jahongir_premium', $out);
    }

    public function test_contains_common_mistakes_section(): void
    {
        $out = HelpContent::render();
        $this->assertStringContainsStringIgnoringCase('Common mistakes', $out);
        // The ❌/✅ paired example is the whole point of this block.
        $this->assertStringContainsString('❌', $out);
        $this->assertStringContainsString('✅', $out);
    }

    public function test_contains_important_rules_block(): void
    {
        $out = HelpContent::render();
        $this->assertStringContainsStringIgnoringCase('Important', $out);
        $this->assertStringContainsStringIgnoringCase('always specify the property', $out);
        $this->assertStringContainsStringIgnoringCase('today, tomorrow', $out);
        $this->assertStringContainsStringIgnoringCase('tel +998', $out);
    }

    public function test_is_plain_text_no_markdown_metacharacters(): void
    {
        // We deliberately send /help WITHOUT parse_mode, so raw * and
        // backticks would print literally. Operators also copy/paste
        // these rows into other chats — markup leaks. Assert the body
        // has no stray * or ` that could confuse either surface.
        $out = HelpContent::render();
        $this->assertStringNotContainsString('*', $out, 'Help text must not contain Markdown asterisks.');
        $this->assertStringNotContainsString('`', $out, 'Help text must not contain Markdown backticks.');
    }
}
