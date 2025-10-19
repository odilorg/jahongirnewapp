<?php

namespace App\Enums;

use Carbon\Carbon;

enum ReportPeriod: string
{
    case TODAY = 'today';
    case YESTERDAY = 'yesterday';
    case THIS_WEEK = 'this_week';
    case LAST_WEEK = 'last_week';
    case THIS_MONTH = 'this_month';
    case LAST_MONTH = 'last_month';
    case THIS_QUARTER = 'this_quarter';
    case LAST_QUARTER = 'last_quarter';
    case THIS_YEAR = 'this_year';
    case LAST_YEAR = 'last_year';
    case CUSTOM = 'custom';

    /**
     * Get the date range for this period
     *
     * @return array{0: Carbon, 1: Carbon}
     * @throws \Exception
     */
    public function getDateRange(): array
    {
        return match($this) {
            self::TODAY => [
                Carbon::today(),
                Carbon::today()->endOfDay()
            ],
            self::YESTERDAY => [
                Carbon::yesterday(),
                Carbon::yesterday()->endOfDay()
            ],
            self::THIS_WEEK => [
                Carbon::now()->startOfWeek(),
                Carbon::now()->endOfWeek()
            ],
            self::LAST_WEEK => [
                Carbon::now()->subWeek()->startOfWeek(),
                Carbon::now()->subWeek()->endOfWeek()
            ],
            self::THIS_MONTH => [
                Carbon::now()->startOfMonth(),
                Carbon::now()->endOfMonth()
            ],
            self::LAST_MONTH => [
                Carbon::now()->subMonth()->startOfMonth(),
                Carbon::now()->subMonth()->endOfMonth()
            ],
            self::THIS_QUARTER => [
                Carbon::now()->startOfQuarter(),
                Carbon::now()->endOfQuarter()
            ],
            self::LAST_QUARTER => [
                Carbon::now()->subQuarter()->startOfQuarter(),
                Carbon::now()->subQuarter()->endOfQuarter()
            ],
            self::THIS_YEAR => [
                Carbon::now()->startOfYear(),
                Carbon::now()->endOfYear()
            ],
            self::LAST_YEAR => [
                Carbon::now()->subYear()->startOfYear(),
                Carbon::now()->subYear()->endOfYear()
            ],
            self::CUSTOM => throw new \Exception('Custom period requires explicit dates')
        };
    }

    /**
     * Get a human-readable label for this period
     */
    public function getLabel(): string
    {
        return match($this) {
            self::TODAY => 'Today',
            self::YESTERDAY => 'Yesterday',
            self::THIS_WEEK => 'This Week',
            self::LAST_WEEK => 'Last Week',
            self::THIS_MONTH => 'This Month',
            self::LAST_MONTH => 'Last Month',
            self::THIS_QUARTER => 'This Quarter',
            self::LAST_QUARTER => 'Last Quarter',
            self::THIS_YEAR => 'This Year',
            self::LAST_YEAR => 'Last Year',
            self::CUSTOM => 'Custom Period',
        };
    }
}
