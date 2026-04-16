<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\BookingInquiry;
use App\Models\GygInboundEmail;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Dashboard widget showing GYG email pipeline health at a glance.
 *
 * Designed for the operator to spot issues without checking logs:
 * - Is the pipeline running? (last fetch timestamp)
 * - Is anything stuck? (pending counts)
 * - Does anything need attention? (needs_review / failed)
 */
class GygPipelineHealthWidget extends BaseWidget
{
    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $lastFetchAt   = GygInboundEmail::max('created_at');
        $pendingParse  = GygInboundEmail::where('processing_status', 'fetched')->count();
        $pendingApply  = GygInboundEmail::where('processing_status', 'parsed')
            ->whereIn('email_type', ['new_booking', 'cancellation', 'amendment'])
            ->count();
        $needsReview   = GygInboundEmail::where('processing_status', 'needs_review')->count();
        $failed        = GygInboundEmail::where('processing_status', 'failed')->count();
        $totalEmails   = GygInboundEmail::count();
        $totalInquiries = BookingInquiry::where('source', BookingInquiry::SOURCE_GYG)->count();

        $latestInquiry = BookingInquiry::where('source', BookingInquiry::SOURCE_GYG)
            ->latest('created_at')
            ->first(['reference', 'customer_name', 'travel_date']);

        // Last fetch health
        $fetchAge = $lastFetchAt ? now()->diffInMinutes($lastFetchAt) : null;
        $fetchLabel = $lastFetchAt
            ? \Carbon\Carbon::parse($lastFetchAt)->diffForHumans()
            : 'never';
        $fetchColor = match (true) {
            $fetchAge === null      => 'danger',
            $fetchAge > 30          => 'danger',
            $fetchAge > 15          => 'warning',
            default                 => 'success',
        };

        // Pipeline queue health (combined pending + stuck)
        $queueTotal = $pendingParse + $pendingApply;
        $queueColor = $queueTotal > 5 ? 'warning' : ($queueTotal > 0 ? 'info' : 'success');

        // Review / failure severity
        $attentionCount = $needsReview + $failed;
        $attentionColor = match (true) {
            $failed > 0        => 'danger',
            $needsReview > 0   => 'warning',
            default            => 'success',
        };

        // Latest inquiry label
        $latestLabel = $latestInquiry
            ? "{$latestInquiry->reference} · {$latestInquiry->customer_name}"
            : 'none yet';
        $latestDesc = $latestInquiry?->travel_date?->format('M j, Y');

        return [
            Stat::make('Last GYG fetch', $fetchLabel)
                ->description("{$totalEmails} emails total")
                ->color($fetchColor)
                ->icon('heroicon-o-envelope'),

            Stat::make('Pipeline queue', (string) $queueTotal)
                ->description("parse: {$pendingParse} · apply: {$pendingApply}")
                ->color($queueColor)
                ->icon('heroicon-o-queue-list'),

            Stat::make('Needs attention', (string) $attentionCount)
                ->description($failed > 0 ? "review: {$needsReview} · failed: {$failed}" : "review: {$needsReview}")
                ->color($attentionColor)
                ->icon('heroicon-o-exclamation-triangle')
                ->url($needsReview > 0 ? route('filament.admin.resources.booking-inquiries.index', ['tableFilters[source][values][0]' => 'gyg']) : null),

            Stat::make('GYG bookings', (string) $totalInquiries)
                ->description($latestLabel)
                ->color('info')
                ->icon('heroicon-o-ticket')
                ->url(route('filament.admin.resources.booking-inquiries.index', ['tableFilters[source][values][0]' => 'gyg'])),
        ];
    }
}
