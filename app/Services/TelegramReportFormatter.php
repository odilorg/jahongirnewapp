<?php

namespace App\Services;

use App\Models\CashierShift;
use App\Enums\Currency;
use App\Enums\ShiftStatus;
use App\Enums\TransactionType;
use Carbon\Carbon;

class TelegramReportFormatter
{
    /**
     * Format today's summary report
     */
    public function formatTodaySummary(array $data, string $lang): string
    {
        $message = "📊 " . strtoupper(__('telegram_pos.today_summary', [], $lang)) . "\n";
        $message .= __('telegram_pos.date', [], $lang) . ": " . $data['date']->format('M d, Y') . "\n\n";

        // Location
        $message .= "📍 " . __('telegram_pos.location', [], $lang) . ": " . $data['location'] . "\n\n";

        // Shifts section
        $message .= "🔢 " . strtoupper(__('telegram_pos.shifts', [], $lang)) . "\n";
        $message .= "• " . __('telegram_pos.open_shifts', [], $lang) . ": " . $data['shifts']['open'] . "\n";
        $message .= "• " . __('telegram_pos.closed_shifts', [], $lang) . ": " . $data['shifts']['closed'] . "\n";
        $message .= "• " . __('telegram_pos.under_review', [], $lang) . ": " . $data['shifts']['under_review'] . "\n";
        $message .= "• " . __('telegram_pos.total_shifts', [], $lang) . ": " . $data['shifts']['total'] . "\n\n";

        // Transactions section
        $message .= "💰 " . strtoupper(__('telegram_pos.transactions', [], $lang)) . "\n";
        $message .= "• " . __('telegram_pos.total_transactions', [], $lang) . ": " . $data['transactions']['total'] . "\n";
        $message .= "• " . __('telegram_pos.cash_in', [], $lang) . ": " . $data['transactions']['cash_in'] . "\n";
        $message .= "• " . __('telegram_pos.cash_out', [], $lang) . ": " . $data['transactions']['cash_out'] . "\n";
        $message .= "• " . __('telegram_pos.exchanges', [], $lang) . ": " . $data['transactions']['exchange'] . "\n\n";

        // Currency totals
        if (!empty($data['currency_totals'])) {
            $message .= "💵 " . strtoupper(__('telegram_pos.totals_by_currency', [], $lang)) . "\n";
            foreach ($data['currency_totals'] as $currencyCode => $amounts) {
                $currency = Currency::from($currencyCode);
                $netFormatted = $this->formatCurrency($currency, $amounts['net']);
                $message .= "• {$currency->value}: {$netFormatted} (" . __('telegram_pos.net', [], $lang) . ")\n";
            }
            $message .= "\n";
        }

        // Active cashiers
        $message .= "👥 " . __('telegram_pos.active_cashiers', [], $lang) . "\n";
        $message .= "• " . $data['active_cashiers'] . " " . __('telegram_pos.currently_working', [], $lang) . "\n\n";

        // Discrepancies
        if ($data['discrepancies'] > 0) {
            $message .= "⚠️ " . __('telegram_pos.discrepancies', [], $lang) . "\n";
            $message .= "• " . $data['discrepancies'] . " " . __('telegram_pos.shifts_flagged_review', [], $lang) . "\n\n";
        }

        // Top performer
        if ($data['top_performer']) {
            $message .= "🏆 " . __('telegram_pos.top_performer', [], $lang) . "\n";
            $message .= "• " . $data['top_performer']['name'] . " - ";
            $message .= $data['top_performer']['transaction_count'] . " " . __('telegram_pos.transactions', [], $lang) . "\n";
        }

        return $message;
    }

    /**
     * Format shift performance report
     */
    public function formatShiftPerformance(array $data, string $lang): string
    {
        $message = "👥 " . strtoupper(__('telegram_pos.shift_performance', [], $lang)) . "\n";
        $message .= __('telegram_pos.date', [], $lang) . ": " . $data['date']->format('M d, Y') . "\n\n";

        if (empty($data['shifts']) || $data['shifts']->isEmpty()) {
            return $message . __('telegram_pos.no_shifts_found', [], $lang);
        }

        $message .= "📊 " . __('telegram_pos.summary', [], $lang) . "\n";
        $message .= "• " . __('telegram_pos.total_shifts', [], $lang) . ": " . $data['total_shifts'] . "\n";
        $message .= "• " . __('telegram_pos.total_transactions', [], $lang) . ": " . $data['total_transactions'] . "\n";
        $message .= "• " . __('telegram_pos.avg_shift_duration', [], $lang) . ": " . $this->formatDuration($data['avg_duration']) . "\n\n";

        $message .= "═══════════════════\n\n";

        // List shifts
        $count = 1;
        foreach ($data['shifts'] as $shift) {
            $statusEmoji = $this->getStatusEmoji($shift['status']);

            $message .= "{$count}️⃣ " . __('telegram_pos.shift', [], $lang) . " #{$shift['shift_id']}\n";
            $message .= "👤 {$shift['cashier_name']}\n";
            $message .= "🕐 " . $shift['opened_at']->format('H:i');

            if ($shift['closed_at']) {
                $message .= " - " . $shift['closed_at']->format('H:i');
                $message .= " (" . $this->formatDuration($shift['duration_minutes']) . ")";
            } else {
                $message .= " - " . __('telegram_pos.ongoing', [], $lang);
            }
            $message .= "\n";

            $message .= "💰 {$shift['transaction_count']} " . __('telegram_pos.transactions', [], $lang) . "\n";

            // Currency balances
            if (!empty($shift['currency_balances'])) {
                foreach ($shift['currency_balances'] as $currencyCode => $balance) {
                    $currency = Currency::from($currencyCode);
                    $formatted = $this->formatCurrency($currency, $balance);
                    $message .= "💵 {$currency->value}: {$formatted}\n";
                }
            }

            $message .= "{$statusEmoji} " . $this->getStatusText($shift['status'], $lang);

            if ($shift['has_discrepancy'] && $shift['discrepancy_info']) {
                $discrepancies = $shift['discrepancy_info']['discrepancies'];
                if (!empty($discrepancies)) {
                    foreach ($discrepancies as $disc) {
                        $currency = Currency::from($disc['currency']);
                        $formatted = $this->formatCurrency($currency, $disc['discrepancy']);
                        $message .= " ({$formatted})";
                    }
                }
            }

            $message .= "\n\n";
            $count++;

            // Limit to 10 shifts per message to avoid Telegram message limit
            if ($count > 10) {
                $message .= "... " . __('telegram_pos.and_more', [], $lang) . "\n";
                break;
            }
        }

        return $message;
    }

    /**
     * Format shift detail report
     */
    public function formatShiftDetail(array $data, string $lang): string
    {
        $shift = $data['shift'];
        $statusEmoji = $this->getStatusEmoji($shift['status']);

        $message = "🔍 " . strtoupper(__('telegram_pos.shift_detail', [], $lang)) . "\n\n";

        // Basic info
        $message .= "🆔 " . __('telegram_pos.shift_id', [], $lang) . ": #{$shift['id']}\n";
        $message .= "👤 " . __('telegram_pos.cashier', [], $lang) . ": {$shift['cashier']}\n";
        $message .= "🏪 " . __('telegram_pos.drawer', [], $lang) . ": {$shift['drawer']}\n";
        $message .= "📍 " . __('telegram_pos.location', [], $lang) . ": {$shift['location']}\n";
        $message .= "🕐 " . __('telegram_pos.opened', [], $lang) . ": " . $shift['opened_at']->format('M d, H:i') . "\n";

        if ($shift['closed_at']) {
            $message .= "🕐 " . __('telegram_pos.closed', [], $lang) . ": " . $shift['closed_at']->format('M d, H:i') . "\n";
            $message .= "⏱ " . __('telegram_pos.duration', [], $lang) . ": " . $this->formatDuration($shift['duration']) . "\n";
        } else {
            $message .= "⏱ " . __('telegram_pos.duration', [], $lang) . ": " . $this->formatDuration($shift['duration']) . " (" . __('telegram_pos.ongoing', [], $lang) . ")\n";
        }

        $message .= "{$statusEmoji} " . __('telegram_pos.status', [], $lang) . ": " . $this->getStatusText($shift['status'], $lang) . "\n\n";

        // Transactions summary
        $message .= "💰 " . strtoupper(__('telegram_pos.transactions', [], $lang)) . "\n";
        $message .= "• " . __('telegram_pos.total', [], $lang) . ": " . $data['transactions']['total'] . "\n";
        $message .= "• " . __('telegram_pos.cash_in', [], $lang) . ": " . $data['transactions']['by_type']['cash_in'] . "\n";
        $message .= "• " . __('telegram_pos.cash_out', [], $lang) . ": " . $data['transactions']['by_type']['cash_out'] . "\n";
        $message .= "• " . __('telegram_pos.exchanges', [], $lang) . ": " . $data['transactions']['by_type']['exchange'] . "\n\n";

        // Currency balances
        if (!empty($data['balances'])) {
            $message .= "💵 " . strtoupper(__('telegram_pos.balances', [], $lang)) . "\n";
            foreach ($data['balances'] as $currencyCode => $balance) {
                $currency = Currency::from($currencyCode);
                $formatted = $this->formatCurrency($currency, $balance);
                $message .= "• {$currency->value}: {$formatted}\n";
            }
            $message .= "\n";
        }

        // Discrepancy info
        if ($data['discrepancy']) {
            $message .= "⚠️ " . strtoupper(__('telegram_pos.discrepancy', [], $lang)) . "\n";
            foreach ($data['discrepancy']['discrepancies'] as $disc) {
                $currency = Currency::from($disc['currency']);
                $expectedFormatted = $this->formatCurrency($currency, $disc['expected']);
                $countedFormatted = $this->formatCurrency($currency, $disc['counted']);
                $discFormatted = $this->formatCurrency($currency, $disc['discrepancy']);

                $message .= "• {$currency->value}:\n";
                $message .= "  " . __('telegram_pos.expected', [], $lang) . ": {$expectedFormatted}\n";
                $message .= "  " . __('telegram_pos.counted', [], $lang) . ": {$countedFormatted}\n";
                $message .= "  " . __('telegram_pos.discrepancy', [], $lang) . ": {$discFormatted}\n";
            }

            if ($data['discrepancy']['reason']) {
                $message .= "📝 " . __('telegram_pos.reason', [], $lang) . ": {$data['discrepancy']['reason']}\n";
            }
            $message .= "\n";
        }

        // Recent transactions
        if (!empty($data['transactions']['recent']) && count($data['transactions']['recent']) > 0) {
            $message .= "📝 " . __('telegram_pos.recent_transactions', [], $lang) . " (" . count($data['transactions']['recent']) . ")\n";
            $message .= "─────────────────\n";

            foreach ($data['transactions']['recent']->take(5) as $txn) {
                $typeEmoji = $this->getTransactionTypeEmoji($txn['type']);
                $currency = Currency::from($txn['currency']);
                $amount = $this->formatCurrency($currency, $txn['amount']);

                $message .= "{$typeEmoji} {$amount} - {$txn['occurred_at']->format('H:i')}\n";

                if ($txn['notes']) {
                    $message .= "   💭 " . mb_substr($txn['notes'], 0, 40) . "...\n";
                }
            }
        }

        return $message;
    }

    /**
     * Format transaction activity report
     */
    public function formatTransactionActivity(array $data, string $lang): string
    {
        $message = "💰 " . strtoupper(__('telegram_pos.transaction_report', [], $lang)) . "\n";
        $message .= __('telegram_pos.period', [], $lang) . ": ";
        $message .= $data['period']['start']->format('M d') . " - " . $data['period']['end']->format('M d, Y') . "\n\n";

        // Summary
        $message .= "📊 " . __('telegram_pos.summary', [], $lang) . "\n";
        $message .= "• " . __('telegram_pos.total_transactions', [], $lang) . ": " . $data['summary']['total_transactions'] . "\n";
        $message .= "• " . __('telegram_pos.cash_in', [], $lang) . ": " . $data['by_type']['cash_in'] . "\n";
        $message .= "• " . __('telegram_pos.cash_out', [], $lang) . ": " . $data['by_type']['cash_out'] . "\n";
        $message .= "• " . __('telegram_pos.exchanges', [], $lang) . ": " . $data['by_type']['exchange'] . "\n\n";

        // By currency
        if (!empty($data['by_currency'])) {
            $message .= "💵 " . __('telegram_pos.by_currency', [], $lang) . "\n";
            foreach ($data['by_currency'] as $currencyCode => $amounts) {
                $currency = Currency::from($currencyCode);
                $netFormatted = $this->formatCurrency($currency, $amounts['net']);
                $message .= "• {$currency->value}: {$netFormatted} ({$amounts['count']} " . __('telegram_pos.txns', [], $lang) . ")\n";
            }
            $message .= "\n";
        }

        // Top cashiers
        if (!empty($data['top_cashiers']) && count($data['top_cashiers']) > 0) {
            $message .= "🏆 " . __('telegram_pos.top_cashiers', [], $lang) . "\n";
            $rank = 1;
            foreach ($data['top_cashiers'] as $name => $count) {
                $message .= "{$rank}. {$name} - {$count} " . __('telegram_pos.transactions', [], $lang) . "\n";
                $rank++;
            }
        }

        return $message;
    }

    /**
     * Format multi-location summary
     */
    public function formatMultiLocationSummary(array $data, string $lang): string
    {
        $message = "🏢 " . strtoupper(__('telegram_pos.multi_location_summary', [], $lang)) . "\n";
        $message .= __('telegram_pos.date', [], $lang) . ": " . $data['date']->format('M d, Y') . "\n";
        $message .= __('telegram_pos.total_locations', [], $lang) . ": " . $data['total_locations'] . "\n\n";

        foreach ($data['locations'] as $idx => $location) {
            $num = $idx + 1;
            $message .= "{$num}. 📍 {$location['location_name']}\n";
            $message .= "   🔢 " . __('telegram_pos.shifts', [], $lang) . ": {$location['shifts']['total']} ";
            $message .= "(" . __('telegram_pos.open', [], $lang) . ": {$location['shifts']['open']}, ";
            $message .= __('telegram_pos.closed', [], $lang) . ": {$location['shifts']['closed']})\n";
            $message .= "   💰 " . __('telegram_pos.transactions', [], $lang) . ": {$location['transactions']['total']}\n";
            $message .= "   👥 " . __('telegram_pos.active', [], $lang) . ": {$location['active_cashiers']}\n\n";
        }

        return $message;
    }

    /**
     * Format currency amount
     */
    protected function formatCurrency(Currency $currency, float $amount): string
    {
        $formatted = number_format(abs($amount), 2, '.', ',');
        $sign = $amount >= 0 ? '+' : '-';

        return "{$sign}{$formatted} {$currency->value}";
    }

    /**
     * Format duration in minutes to human readable
     */
    protected function formatDuration(int $minutes): string
    {
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;

        if ($hours > 0) {
            return "{$hours}h {$mins}m";
        }

        return "{$mins}m";
    }

    /**
     * Get status emoji
     */
    protected function getStatusEmoji(ShiftStatus $status): string
    {
        return match ($status) {
            ShiftStatus::OPEN => '🟢',
            ShiftStatus::CLOSED => '✅',
            ShiftStatus::UNDER_REVIEW => '⚠️',
        };
    }

    /**
     * Get status text
     */
    protected function getStatusText(ShiftStatus $status, string $lang): string
    {
        return match ($status) {
            ShiftStatus::OPEN => __('telegram_pos.status_open', [], $lang),
            ShiftStatus::CLOSED => __('telegram_pos.status_closed', [], $lang),
            ShiftStatus::UNDER_REVIEW => __('telegram_pos.status_under_review', [], $lang),
        };
    }

    /**
     * Get transaction type emoji
     */
    protected function getTransactionTypeEmoji(TransactionType $type): string
    {
        return match ($type) {
            TransactionType::IN => '💵',
            TransactionType::OUT => '💸',
            TransactionType::IN_OUT => '🔄',
        };
    }
}
