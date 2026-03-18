<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\BotEnvironment;
use PHPUnit\Framework\TestCase;

class BotEnvironmentTest extends TestCase
{
    // ──────────────────────────────────────────────
    // fromAppEnvironment — canonical mapping
    // ──────────────────────────────────────────────

    /** @test */
    public function production_maps_to_production(): void
    {
        $this->assertSame(
            BotEnvironment::Production,
            BotEnvironment::fromAppEnvironment('production')
        );
    }

    /** @test */
    public function staging_maps_to_staging(): void
    {
        $this->assertSame(
            BotEnvironment::Staging,
            BotEnvironment::fromAppEnvironment('staging')
        );
    }

    /** @test */
    public function local_maps_to_development(): void
    {
        $this->assertSame(
            BotEnvironment::Development,
            BotEnvironment::fromAppEnvironment('local')
        );
    }

    /** @test */
    public function testing_maps_to_development(): void
    {
        $this->assertSame(
            BotEnvironment::Development,
            BotEnvironment::fromAppEnvironment('testing')
        );
    }

    /** @test */
    public function unknown_env_maps_to_development(): void
    {
        $this->assertSame(
            BotEnvironment::Development,
            BotEnvironment::fromAppEnvironment('custom-env')
        );
    }

    /** @test */
    public function empty_string_maps_to_development(): void
    {
        $this->assertSame(
            BotEnvironment::Development,
            BotEnvironment::fromAppEnvironment('')
        );
    }

    // ──────────────────────────────────────────────
    // Enum basics
    // ──────────────────────────────────────────────

    /** @test */
    public function enum_values(): void
    {
        $this->assertSame('production', BotEnvironment::Production->value);
        $this->assertSame('staging', BotEnvironment::Staging->value);
        $this->assertSame('development', BotEnvironment::Development->value);
    }

    /** @test */
    public function labels(): void
    {
        $this->assertSame('Production', BotEnvironment::Production->label());
        $this->assertSame('Staging', BotEnvironment::Staging->label());
        $this->assertSame('Development', BotEnvironment::Development->label());
    }

    /** @test */
    public function colors(): void
    {
        $this->assertSame('danger', BotEnvironment::Production->color());
        $this->assertSame('warning', BotEnvironment::Staging->color());
        $this->assertSame('gray', BotEnvironment::Development->color());
    }
}
