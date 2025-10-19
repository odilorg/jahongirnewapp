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

    /**
     * Format Financial Range Summary Report
     */
    public function formatFinancialRangeSummary(array $data, string $lang): string
    {
        $message = "📊 <b>FINANCIAL SUMMARY</b>\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

        // Period info
        $message .= "📅 Period: {$data['period']['start']->format('M d')} - {$data['period']['end']->format('M d, Y')}\n";
        $message .= "📍 Location: " . ($data['location'] ?? 'All') . "\n\n";

        // Revenue section
        $message .= "💰 <b>REVENUE</b>\n";
        $message .= "   Total: " . number_format($data['summary']['revenue'], 0) . " UZS\n";
        if (isset($data['comparison']['revenue_change_pct']) && $data['comparison']['revenue_change_pct'] != 0) {
            $arrow = $data['comparison']['revenue_change_pct'] > 0 ? '↗️' : '↘️';
            $sign = $data['comparison']['revenue_change_pct'] > 0 ? '+' : '';
            $message .= "   Change: {$arrow} {$sign}" . number_format($data['comparison']['revenue_change_pct'], 1) . "%\n";
        }
        $message .= "\n";

        // Expenses section
        $message .= "💸 <b>EXPENSES</b>\n";
        $message .= "   Total: " . number_format($data['summary']['expenses'], 0) . " UZS\n";
        if (isset($data['comparison']['expenses_change_pct']) && $data['comparison']['expenses_change_pct'] != 0) {
            $arrow = $data['comparison']['expenses_change_pct'] > 0 ? '↗️' : '↘️';
            $sign = $data['comparison']['expenses_change_pct'] > 0 ? '+' : '';
            $message .= "   Change: {$arrow} {$sign}" . number_format($data['comparison']['expenses_change_pct'], 1) . "%\n";
        }
        $message .= "\n";

        // Net cash flow
        $netFlow = $data['summary']['net_cash_flow'];
        $netIcon = $netFlow >= 0 ? '✅' : '⚠️';
        $message .= "{$netIcon} <b>NET CASH FLOW</b>\n";
        $message .= "   " . number_format($netFlow, 0) . " UZS\n\n";

        // By currency
        if (!empty($data['by_currency'])) {
            $message .= "💵 <b>BY CURRENCY</b>\n";
            foreach ($data['by_currency'] as $currencyCode => $amounts) {
                $revenue = number_format($amounts['revenue'], 0);
                $message .= "   {$currencyCode}: {$revenue}\n";
            }
            $message .= "\n";
        }

        // Transactions
        $message .= "🔢 <b>TRANSACTIONS</b>\n";
        $message .= "   Total: {$data['summary']['total_transactions']}\n";
        $message .= "   Cash In: {$data['summary']['cash_in_count']}\n";
        $message .= "   Cash Out: {$data['summary']['cash_out_count']}\n";
        $message .= "   Exchanges: {$data['summary']['exchange_count']}\n\n";

        // Daily average
        if (isset($data['daily_average'])) {
            $message .= "📈 <b>DAILY AVERAGE</b>\n";
            $message .= "   Revenue: " . number_format($data['daily_average']['revenue'], 0) . " UZS\n";
            $message .= "   Transactions: {$data['daily_average']['transactions']}\n";
        }

        return $message;
    }

    /**
     * Format Discrepancy/Variance Report
     */
    public function formatDiscrepancyReport(array $data, string $lang): string
    {
        $message = "⚠️ <b>DISCREPANCY REPORT</b>\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

        // Period info
        $message .= "📅 Period: {$data['period']['start']->format('M d')} - {$data['period']['end']->format('M d, Y')}\n";
        $message .= "📍 Location: " . ($data['location'] ?? 'All') . "\n\n";

        // Summary
        $message .= "📊 <b>SUMMARY</b>\n";
        $message .= "   Total Shifts: {$data['summary']['total_shifts']}\n";
        $message .= "   With Discrepancies: {$data['summary']['shifts_with_discrepancies']}\n";

        $accuracyIcon = $data['summary']['accuracy_rate'] >= 95 ? '✅' : ($data['summary']['accuracy_rate'] >= 90 ? '⚠️' : '❌');
        $message .= "   {$accuracyIcon} Accuracy Rate: " . number_format($data['summary']['accuracy_rate'], 1) . "%\n\n";

        // Total discrepancy amount
        $totalDisc = $data['summary']['total_discrepancy_amount'];
        $discIcon = abs($totalDisc) > 1000000 ? '❌' : (abs($totalDisc) > 100000 ? '⚠️' : '✅');
        $message .= "{$discIcon} <b>TOTAL DISCREPANCY</b>\n";
        $message .= "   " . number_format($totalDisc, 0) . " UZS\n\n";

        // By cashier
        if (!empty($data['by_cashier'])) {
            $message .= "👥 <b>BY CASHIER</b>\n";
            $count = 1;
            foreach ($data['by_cashier'] as $cashierName => $stats) {
                if ($count > 5) {
                    $message .= "   ... and " . (count($data['by_cashier']) - 5) . " more\n";
                    break;
                }

                $accuracyIcon = $stats['accuracy_rate'] >= 95 ? '✅' : ($stats['accuracy_rate'] >= 90 ? '⚠️' : '❌');
                $message .= "   {$accuracyIcon} {$cashierName}\n";
                $message .= "      Accuracy: " . number_format($stats['accuracy_rate'], 1) . "%\n";
                $message .= "      Discrepancies: {$stats['discrepancy_count']} shifts\n";

                $count++;
            }
            $message .= "\n";
        }

        // Top 5 largest discrepancies
        if (!empty($data['largest_discrepancies'])) {
            $message .= "🔝 <b>LARGEST DISCREPANCIES</b>\n";
            foreach (array_slice($data['largest_discrepancies'], 0, 5) as $disc) {
                $amount = number_format(abs($disc['amount']), 0);
                $message .= "   • Shift #{$disc['shift_id']} ({$disc['cashier']})\n";
                $message .= "     {$amount} {$disc['currency']}\n";
            }
        }

        return $message;
    }

    /**
     * Format Executive Dashboard
     */
    public function formatExecutiveDashboard(array $data, string $lang): string
    {
        $message = "📊 <b>EXECUTIVE DASHBOARD</b>\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

        // Period info
        $message .= "📅 Period: {$data['period']}\n";
        $message .= "🕐 Generated: " . now()->format('M d, Y H:i') . "\n\n";

        // Financial KPIs
        $message .= "💰 <b>FINANCIAL</b>\n";
        $message .= "   Revenue: " . number_format($data['financial']['revenue'], 0) . " UZS\n";

        if (isset($data['financial']['revenue_change_pct'])) {
            $change = $data['financial']['revenue_change_pct'];
            $arrow = $change > 0 ? '↗️' : ($change < 0 ? '↘️' : '➡️');
            $sign = $change > 0 ? '+' : '';
            $message .= "   Change: {$arrow} {$sign}" . number_format($change, 1) . "%\n";
        }

        $message .= "   Transactions: {$data['financial']['total_transactions']}\n";

        if (isset($data['financial']['txn_change_pct'])) {
            $change = $data['financial']['txn_change_pct'];
            $arrow = $change > 0 ? '↗️' : ($change < 0 ? '↘️' : '➡️');
            $sign = $change > 0 ? '+' : '';
            $message .= "   Change: {$arrow} {$sign}" . number_format($change, 1) . "%\n";
        }
        $message .= "\n";

        // Operations
        $message .= "⚙️ <b>OPERATIONS</b>\n";
        $message .= "   Total Shifts: {$data['operations']['total_shifts']}\n";
        $message .= "   Active Now: {$data['operations']['active_shifts']}\n";
        $message .= "   Avg Duration: " . $this->formatDuration($data['operations']['avg_shift_duration']) . "\n";
        $message .= "   Avg Transactions/Shift: " . number_format($data['operations']['avg_transactions_per_shift'], 1) . "\n\n";

        // Quality metrics
        $qualityScore = $data['quality']['quality_score'];
        $qualityIcon = $qualityScore >= 95 ? '✅' : ($qualityScore >= 90 ? '⚠️' : '❌');
        $message .= "{$qualityIcon} <b>QUALITY</b>\n";
        $message .= "   Score: " . number_format($qualityScore, 1) . "/100\n";
        $message .= "   Accuracy: " . number_format($data['quality']['accuracy_rate'], 1) . "%\n";
        $message .= "   Discrepancies: {$data['quality']['discrepancy_count']}\n\n";

        // Top performers
        if (!empty($data['top_performers'])) {
            $message .= "🏆 <b>TOP PERFORMERS</b>\n";
            $rank = 1;
            foreach (array_slice($data['top_performers'], 0, 5) as $performer) {
                $revenue = number_format($performer['revenue'], 0);
                $message .= "   {$rank}. {$performer['name']}\n";
                $message .= "      {$revenue} UZS • {$performer['transaction_count']} txns\n";
                $rank++;
            }
            $message .= "\n";
        }

        // Alerts
        if (!empty($data['alerts'])) {
            $message .= "🚨 <b>ALERTS</b>\n";
            foreach (array_slice($data['alerts'], 0, 5) as $alert) {
                $message .= "   • {$alert['message']}\n";
            }
        }

        return $message;
    }

    /**
     * Format Currency Exchange Report
     */
    public function formatCurrencyExchangeReport(array $data, string $lang): string
    {
        $message = "💱 <b>CURRENCY EXCHANGE REPORT</b>\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

        // Period info
        $message .= "📅 Period: {$data['period']['start']->format('M d')} - {$data['period']['end']->format('M d, Y')}\n";
        $message .= "📍 Location: " . ($data['location'] ?? 'All') . "\n\n";

        // Summary
        $message .= "📊 <b>SUMMARY</b>\n";
        $message .= "   Total Exchanges: {$data['summary']['total_exchanges']}\n";
        $message .= "   Total Value: " . number_format($data['summary']['total_value_uzs'], 0) . " UZS\n";
        $message .= "   Avg Amount: " . number_format($data['summary']['avg_exchange_amount'], 0) . " UZS\n\n";

        // By currency pair
        if (!empty($data['by_currency'])) {
            $message .= "💵 <b>BY CURRENCY</b>\n";
            foreach ($data['by_currency'] as $currencyCode => $stats) {
                $message .= "   <b>{$currencyCode}</b>\n";
                $message .= "      Count: {$stats['count']}\n";
                $message .= "      Volume: " . number_format($stats['total_amount'], 0) . "\n";
                $message .= "      Avg: " . number_format($stats['avg_amount'], 0) . "\n";
            }
            $message .= "\n";
        }

        // Hourly pattern
        if (!empty($data['hourly_pattern'])) {
            $message .= "🕐 <b>PEAK HOURS</b>\n";
            $topHours = array_slice($data['hourly_pattern'], 0, 3, true);
            foreach ($topHours as $hour => $count) {
                $hourFormatted = str_pad($hour, 2, '0', STR_PAD_LEFT) . ":00";
                $message .= "   {$hourFormatted} - {$count} exchanges\n";
            }
            $message .= "\n";
        }

        // Largest exchanges
        if (!empty($data['largest_exchanges'])) {
            $message .= "🔝 <b>LARGEST EXCHANGES</b>\n";
            foreach (array_slice($data['largest_exchanges'], 0, 5) as $exchange) {
                $amount = number_format($exchange['amount'], 0);
                $time = $exchange['occurred_at']->format('M d, H:i');
                $message .= "   • {$amount} {$exchange['currency']}\n";
                $message .= "     {$time} - {$exchange['cashier']}\n";
            }
        }

        return $message;
    }
}
