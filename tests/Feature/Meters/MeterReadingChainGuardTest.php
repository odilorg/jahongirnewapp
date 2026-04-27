<?php

declare(strict_types=1);

namespace Tests\Feature\Meters;

use App\Exceptions\Meters\InvalidMeterReadingException;
use App\Models\Hotel;
use App\Models\Meter;
use App\Models\Utility;
use App\Models\UtilityUsage;
use App\Services\Meters\MeterReadingChainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1 chain-guard tests for the Meters / UtilityUsage feature.
 *
 * These pin the seven invariants that keep the reading chain honest:
 *  1. New reading auto-fills meter_previous from the latest prior reading.
 *  2. First-ever reading defaults meter_previous to 0.
 *  3. Normal reading rejects meter_latest < meter_previous unless reset.
 *  4. Reset reading is accepted with is_meter_reset=true.
 *  5. Backdated reading is rejected.
 *  6. Override toggle requires a non-empty reason.
 *  7. Drift in meter_previous (without override) is rejected.
 *
 * The guard lives in UtilityUsage::saving via MeterReadingChainService —
 * not just the Filament form — so these tests bypass Filament entirely
 * and exercise the model directly.
 */
final class MeterReadingChainGuardTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;
    private Utility $utility;
    private Meter $meter;

    protected function setUp(): void
    {
        parent::setUp();

        // No HotelFactory exists; the hotels table has many NOT NULL
        // columns accumulated across several migrations. The chain
        // guard only touches the id, so fill everything with
        // placeholders.
        $this->hotel = Hotel::create([
            'name'           => 'Test Hotel',
            'description'    => 'Test',
            'room_quantity'  => 1,
            'number_people'  => 1,
            'location'       => 'Test',
            'address'        => 'Test',
            'phone'          => '+0',
            'email'          => 'test@test.test',
            'website'        => 'https://test.test',
            'official_name'  => 'Test',
            'account_number' => '0',
            'bank_name'      => 'Test',
            'inn'            => '0',
            'bank_mfo'       => '0',
        ]);
        $this->utility = Utility::create(['name' => 'Tabiyy gaz']);
        $this->meter = Meter::create([
            'meter_serial_number'         => 'TEST-001',
            'utility_id'                  => $this->utility->id,
            'hotel_id'                    => $this->hotel->id,
            'sertificate_expiration_date' => now()->addYear(),
            'contract_number'             => 'C-1',
            'contract_date'               => now(),
        ]);
    }

    public function test_first_reading_defaults_meter_previous_to_zero(): void
    {
        $autoFill = app(MeterReadingChainService::class)->autoFillPrevious($this->meter->id);
        $this->assertSame(0, $autoFill);

        $reading = $this->buildReading([
            'meter_previous' => 0,
            'meter_latest'   => 1000,
            'usage_date'     => '2026-01-01',
        ]);
        $reading->save();

        $this->assertSame(1000, (int) $reading->fresh()->meter_difference);
    }

    public function test_auto_fill_returns_latest_reading_for_existing_meter(): void
    {
        $this->seedReading(['usage_date' => '2026-01-01', 'meter_previous' => 0, 'meter_latest' => 1000]);
        $this->seedReading(['usage_date' => '2026-02-01', 'meter_previous' => 1000, 'meter_latest' => 1250]);

        $autoFill = app(MeterReadingChainService::class)->autoFillPrevious($this->meter->id);

        $this->assertSame(1250, $autoFill, 'auto-fill should use the most recent meter_latest');
    }

    public function test_normal_reading_rejects_latest_below_previous_when_not_reset(): void
    {
        $this->seedReading(['usage_date' => '2026-01-01', 'meter_previous' => 0, 'meter_latest' => 1000]);

        $this->expectException(InvalidMeterReadingException::class);
        $this->expectExceptionMessage('Сброс счётчика');

        $this->buildReading([
            'usage_date'     => '2026-02-01',
            'meter_previous' => 1000,
            'meter_latest'   => 50, // regression — would silently double-bill the customer
        ])->save();
    }

    public function test_reset_reading_is_accepted_when_flag_is_set(): void
    {
        $this->seedReading(['usage_date' => '2026-01-01', 'meter_previous' => 0, 'meter_latest' => 39119]);

        $reset = $this->buildReading([
            'usage_date'     => '2026-02-01',
            'meter_previous' => 0,
            'meter_latest'   => 753,
            'is_meter_reset' => true,
        ]);
        $reset->save();

        $this->assertTrue($reset->fresh()->is_meter_reset);
        $this->assertSame(753, (int) $reset->fresh()->meter_difference);
    }

    public function test_reset_flag_without_actual_reset_is_rejected(): void
    {
        $this->seedReading(['usage_date' => '2026-01-01', 'meter_previous' => 0, 'meter_latest' => 1000]);

        $this->expectException(InvalidMeterReadingException::class);
        $this->expectExceptionMessage('Сброс счётчика отмечен');

        $this->buildReading([
            'usage_date'     => '2026-02-01',
            'meter_previous' => 1000,
            'meter_latest'   => 1500, // not a reset — but flag is set
            'is_meter_reset' => true,
        ])->save();
    }

    public function test_backdated_reading_is_rejected(): void
    {
        $this->seedReading(['usage_date' => '2026-01-01', 'meter_previous' => 0, 'meter_latest' => 1000]);
        $this->seedReading(['usage_date' => '2026-02-01', 'meter_previous' => 1000, 'meter_latest' => 1250]);

        $this->expectException(InvalidMeterReadingException::class);
        $this->expectExceptionMessage('Дата показания');

        $this->buildReading([
            'usage_date'     => '2026-01-15', // earlier than the latest
            'meter_previous' => 1250,
            'meter_latest'   => 1300,
        ])->save();
    }

    public function test_same_date_as_latest_reading_is_rejected(): void
    {
        $this->seedReading(['usage_date' => '2026-01-01', 'meter_previous' => 0, 'meter_latest' => 1000]);
        $this->seedReading(['usage_date' => '2026-02-01', 'meter_previous' => 1000, 'meter_latest' => 1250]);

        $this->expectException(InvalidMeterReadingException::class);

        $this->buildReading([
            'usage_date'     => '2026-02-01', // duplicate-date attempt
            'meter_previous' => 1250,
            'meter_latest'   => 1500,
        ])->save();
    }

    public function test_override_requires_non_empty_reason(): void
    {
        $this->seedReading(['usage_date' => '2026-01-01', 'meter_previous' => 0, 'meter_latest' => 1000]);

        $this->expectException(InvalidMeterReadingException::class);
        $this->expectExceptionMessage('причину');

        $this->buildReading([
            'usage_date'                     => '2026-02-01',
            'meter_previous'                 => 950, // doesn't match prior 1000
            'meter_latest'                   => 1100,
            'meter_previous_overridden'      => true,
            'meter_previous_override_reason' => '   ', // whitespace doesn't count
        ])->save();
    }

    public function test_override_with_reason_allows_drift_from_prior_latest(): void
    {
        $this->seedReading(['usage_date' => '2026-01-01', 'meter_previous' => 0, 'meter_latest' => 1000]);

        $reading = $this->buildReading([
            'usage_date'                     => '2026-02-01',
            'meter_previous'                 => 950, // doesn't match prior — but override toggle is on
            'meter_latest'                   => 1100,
            'meter_previous_overridden'      => true,
            'meter_previous_override_reason' => 'Operator entered wrong value last month — corrected',
        ]);
        $reading->save();

        $fresh = $reading->fresh();
        $this->assertTrue($fresh->meter_previous_overridden);
        $this->assertSame('Operator entered wrong value last month — corrected', $fresh->meter_previous_override_reason);
        $this->assertSame(150, (int) $fresh->meter_difference);
    }

    public function test_drift_in_meter_previous_without_override_is_rejected(): void
    {
        // The single most important regression: this is exactly the
        // pattern that produced the 7 drift rows the audit found.
        $this->seedReading(['usage_date' => '2026-01-01', 'meter_previous' => 0, 'meter_latest' => 37705]);

        $this->expectException(InvalidMeterReadingException::class);
        $this->expectExceptionMessage('не совпадает');

        $this->buildReading([
            'usage_date'     => '2026-02-01',
            'meter_previous' => 38533, // operator typed an estimate of a missing month
            'meter_latest'   => 39119,
            // no override toggle, no reason — so this is now blocked
        ])->save();
    }

    public function test_meter_difference_is_recomputed_on_save_even_if_caller_passed_wrong_value(): void
    {
        $reading = $this->buildReading([
            'usage_date'       => '2026-01-01',
            'meter_previous'   => 0,
            'meter_latest'     => 500,
            'meter_difference' => 999_999, // intentional lie
        ]);
        $reading->save();

        $this->assertSame(500, (int) $reading->fresh()->meter_difference, 'guard recomputes regardless of input');
    }

    public function test_first_reading_with_nonzero_previous_requires_override(): void
    {
        $this->expectException(InvalidMeterReadingException::class);
        $this->expectExceptionMessage('первое показание');

        $this->buildReading([
            'usage_date'     => '2026-01-01',
            'meter_previous' => 1000, // not 0 — and no override
            'meter_latest'   => 1500,
        ])->save();
    }

    private function buildReading(array $overrides): UtilityUsage
    {
        return new UtilityUsage(array_merge([
            'utility_id' => $this->utility->id,
            'meter_id'   => $this->meter->id,
            'hotel_id'   => $this->hotel->id,
        ], $overrides));
    }

    private function seedReading(array $overrides): UtilityUsage
    {
        $reading = $this->buildReading($overrides);
        $reading->save();

        return $reading;
    }
}
