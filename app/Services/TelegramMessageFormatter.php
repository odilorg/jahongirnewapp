<?php

namespace App\Services;

use App\Models\User;
use App\Models\CashierShift;

class TelegramMessageFormatter
{
    /**
     * Format welcome message
     */
    public function formatWelcome(string $userName, string $language = 'en'): string
    {
        return __('telegram_pos.welcome_back', ['name' => $userName], $language);
    }
    
    /**
     * Format authentication request
     */
    public function formatAuthRequest(string $language = 'en'): string
    {
        $welcome = __('telegram_pos.welcome', [], $language);
        $authRequired = __('telegram_pos.auth_required', [], $language);
        
        return "{$welcome}\n\n{$authRequired}";
    }
    
    /**
     * Format authentication success message
     */
    public function formatAuthSuccess(User $user, string $language = 'en'): string
    {
        return __('telegram_pos.auth_success', ['name' => $user->name], $language);
    }
    
    /**
     * Format error message
     */
    public function formatError(string $message, string $language = 'en'): string
    {
        return "❌ {$message}";
    }
    
    /**
     * Format main menu message
     */
    public function formatMainMenu(string $language = 'en'): string
    {
        return __('telegram_pos.select_action', [], $language);
    }
    
    /**
     * Format shift details
     */
    public function formatShiftDetails(CashierShift $shift, string $language = 'en'): string
    {
        $duration = $shift->opened_at->diffForHumans(null, true);
        
        $details = __('telegram_pos.shift_details', [
            'shift_id' => $shift->id,
            'location' => $shift->cashDrawer->location->name ?? 'N/A',
            'drawer' => $shift->cashDrawer->name,
            'start_time' => $shift->opened_at->format('Y-m-d H:i'),
            'duration' => $duration,
        ], $language);
        
        // Add running balances
        $balances = $shift->getAllRunningBalances();
        
        if (!empty($balances)) {
            $details .= "\n\n" . __('telegram_pos.running_balance', [], $language) . ":\n";
            
            foreach ($balances as $balance) {
                $details .= __('telegram_pos.currency_balance', [
                    'currency' => $balance['currency']->value,
                    'amount' => $balance['formatted'],
                ], $language) . "\n";
            }
        }
        
        // Add transaction count
        $transactionCount = $shift->transactions()->count();
        $details .= "\n" . __('telegram_pos.total_transactions', ['count' => $transactionCount], $language);
        
        return $details;
    }
    
    /**
     * Format transaction confirmation
     */
    public function formatTransactionConfirmation(array $data, string $language = 'en'): string
    {
        $type = $data['type'] ?? 'in';
        $amount = $data['amount'] ?? 0;
        $currency = $data['currency'] ?? 'UZS';
        $category = $data['category'] ?? null;
        $notes = $data['notes'] ?? null;
        
        $message = "📝 " . __('telegram_pos.confirm_transaction', [], $language) . "\n\n";
        $message .= "Type: " . strtoupper($type) . "\n";
        $message .= "Amount: {$amount} {$currency}\n";
        
        if ($category) {
            $message .= "Category: " . ucfirst($category) . "\n";
        }
        
        if ($notes) {
            $message .= "Notes: {$notes}\n";
        }
        
        return $message;
    }
    
    /**
     * Format shift start confirmation
     */
    public function formatShiftStarted(CashierShift $shift, string $language = 'en'): string
    {
        $message = __('telegram_pos.shift_started', [], $language) . "\n\n";
        $message .= "🆔 Shift ID: {$shift->id}\n";
        $message .= "📍 Location: " . ($shift->cashDrawer->location->name ?? 'N/A') . "\n";
        $message .= "💰 Drawer: {$shift->cashDrawer->name}\n";
        $message .= "🕐 Started: " . $shift->opened_at->format('H:i') . "\n\n";
        
        // Show beginning balances
        $beginningSaldos = $shift->beginningSaldos;
        
        if ($beginningSaldos->isNotEmpty()) {
            $message .= "💵 Beginning Balances:\n";
            foreach ($beginningSaldos as $saldo) {
                $message .= "  {$saldo->currency->value}: {$saldo->formatted_amount}\n";
            }
        }
        
        return $message;
    }
    
    /**
     * Format shift closed message
     */
    public function formatShiftClosed(CashierShift $shift, string $language = 'en'): string
    {
        $message = __('telegram_pos.shift_closed', [], $language) . "\n\n";
        $message .= "🆔 Shift ID: {$shift->id}\n";
        $message .= "🕐 Duration: " . $shift->opened_at->diffForHumans($shift->closed_at, true) . "\n\n";
        
        // Show end saldos
        if ($shift->endSaldos->isNotEmpty()) {
            $message .= "💰 Final Balances:\n";
            foreach ($shift->endSaldos as $endSaldo) {
                $expected = $endSaldo->expected_end_saldo;
                $counted = $endSaldo->counted_end_saldo;
                $discrepancy = $endSaldo->discrepancy;
                
                $message .= "  {$endSaldo->currency->value}:\n";
                $message .= "    Expected: {$expected}\n";
                $message .= "    Counted: {$counted}\n";
                
                if (abs($discrepancy) > 0.01) {
                    $message .= "    ⚠️ Discrepancy: {$discrepancy}\n";
                }
            }
        }
        
        if ($shift->status->value === 'under_review') {
            $message .= "\n" . __('telegram_pos.shift_under_review', [], $language);
        }
        
        return $message;
    }
    
    /**
     * Format help text
     */
    public function formatHelp(string $language = 'en'): string
    {
        return __('telegram_pos.help_text', [], $language);
    }
    
    /**
     * Format no open shift message
     */
    public function formatNoOpenShift(string $language = 'en'): string
    {
        return __('telegram_pos.no_open_shift', [], $language);
    }
    
    /**
     * Format session expired message
     */
    public function formatSessionExpired(string $language = 'en'): string
    {
        return __('telegram_pos.session_expired', [], $language);
    }
}

