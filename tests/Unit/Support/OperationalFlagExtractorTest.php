<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\OperationalFlagExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pure-function extractor — unit-tested in isolation.
 * No DB, no HTTP, no Filament, no model. Trivial to extend.
 */
class OperationalFlagExtractorTest extends TestCase
{
    #[Test]
    public function null_input_returns_all_false(): void
    {
        $this->assertSame(
            ['dietary' => false, 'accessibility' => false, 'language' => false, 'occasion' => false],
            OperationalFlagExtractor::extract(null),
        );
    }

    #[Test]
    public function empty_string_returns_all_false(): void
    {
        $this->assertSame(
            ['dietary' => false, 'accessibility' => false, 'language' => false, 'occasion' => false],
            OperationalFlagExtractor::extract(''),
        );
    }

    #[Test]
    public function whitespace_only_returns_all_false(): void
    {
        $this->assertSame(
            ['dietary' => false, 'accessibility' => false, 'language' => false, 'occasion' => false],
            OperationalFlagExtractor::extract("   \n\t  "),
        );
    }

    #[DataProvider('positiveDietaryKeywords')]
    #[Test]
    public function dietary_keyword_matches(string $note): void
    {
        $this->assertTrue(OperationalFlagExtractor::extract($note)['dietary'], "Expected dietary=true for: {$note}");
    }

    public static function positiveDietaryKeywords(): array
    {
        return [
            ['Vegetarian'],
            ['vegan, no dairy'],
            ['Gluten free meals please'],
            ['Gluten-free'],
            ['Halal food required'],
            ['Kosher'],
            ['Has a nut allergy'],
            ['Allergic to shellfish'],
            ['Lactose intolerant'],
            ['Pescatarian'],
            ['allergique aux noix'],
        ];
    }

    #[DataProvider('positiveAccessibilityKeywords')]
    #[Test]
    public function accessibility_keyword_matches(string $note): void
    {
        $this->assertTrue(OperationalFlagExtractor::extract($note)['accessibility'], "Expected accessibility=true for: {$note}");
    }

    public static function positiveAccessibilityKeywords(): array
    {
        return [
            ['Wheelchair user'],
            ['Has mobility issues'],
            ['Elderly couple, slow walkers'],
            ['Uses crutches'],
            ['Hearing impaired'],
            ['Sight impaired guest'],
            ['Blind guest, traveling with assistance dog'],
        ];
    }

    #[DataProvider('positiveLanguageKeywords')]
    #[Test]
    public function language_keyword_matches(string $note): void
    {
        $this->assertTrue(OperationalFlagExtractor::extract($note)['language'], "Expected language=true for: {$note}");
    }

    public static function positiveLanguageKeywords(): array
    {
        return [
            ['French speaker, no English'],
            ['Spanish speaking guide preferred'],
            ['Need a guide who speaks German'],
            ['Italian-speaking guest'],
            ['Russian speaker'],
            ['Chinese speaking only'],
        ];
    }

    #[DataProvider('positiveOccasionKeywords')]
    #[Test]
    public function occasion_keyword_matches(string $note): void
    {
        $this->assertTrue(OperationalFlagExtractor::extract($note)['occasion'], "Expected occasion=true for: {$note}");
    }

    public static function positiveOccasionKeywords(): array
    {
        return [
            ['Celebrating their birthday tomorrow'],
            ['Honeymoon trip'],
            ['Anniversary couple'],
            ['Newlywed travelers'],
            ['Surprise for the wife'],
            ["c'est leur anniversaire"],
            ['Cumpleaños del cliente'],
            ['Wedding celebration'],
        ];
    }

    #[DataProvider('falsePositiveCases')]
    #[Test]
    public function false_positives_are_avoided(string $note, string $shouldNotMatch): void
    {
        $result = OperationalFlagExtractor::extract($note);
        $this->assertFalse(
            $result[$shouldNotMatch],
            "False positive on '{$note}' for flag '{$shouldNotMatch}'.",
        );
    }

    public static function falsePositiveCases(): array
    {
        return [
            // The classic — a hotel description should NOT flag the guest.
            ['Stays at a vegetarian-friendly hotel.', 'dietary'],
            ['Tour drops off at wheelchair-accessible building.', 'accessibility'],
            ['Wants to visit the celebration tower.', 'occasion'],
            // Negated allergies must NOT trigger dietary — the negative
            // lookbehinds in the extractor handle 'no'/'non-' prefixes.
            ['No allergies, no preferences, easy guest.', 'dietary'],
            ['No allergy at all.', 'dietary'],
            ['non-allergic guest', 'dietary'],
            // Random text without keywords.
            ['No allergies, no preferences, easy guest.', 'accessibility'],
            ['Will pay cash on arrival.', 'language'],
        ];
    }

    #[Test]
    public function multiple_categories_can_match_simultaneously(): void
    {
        $note = 'Vegetarian wheelchair user, French speaker, celebrating birthday';
        $result = OperationalFlagExtractor::extract($note);
        $this->assertTrue($result['dietary']);
        $this->assertTrue($result['accessibility']);
        $this->assertTrue($result['language']);
        $this->assertTrue($result['occasion']);
    }

    #[Test]
    public function case_insensitive_matching(): void
    {
        $this->assertTrue(OperationalFlagExtractor::extract('VEGAN')['dietary']);
        $this->assertTrue(OperationalFlagExtractor::extract('Wheelchair')['accessibility']);
        $this->assertTrue(OperationalFlagExtractor::extract('HONEYMOON')['occasion']);
    }
}
