<?php

namespace App\Actions\BookingBot;

use App\Models\BookingInquiry;
use App\Services\OctoPaymentService;
use App\Services\TelegramBotService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handle the /pay command in the hotel booking bot.
 *
 * Intercepts before the LLM parser so "pay Name Amount" is never
 * mis-parsed as a create_booking intent.
 *
 * Usage:  /pay <guest name> <amount> [<tour label>]
 * Examples:
 *   /pay Delphine Ettori 153
 *   /pay John Smith 250 Samarkand City Tour
 */
class HandlePayCommandAction
{
    public function __construct(
        private OctoPaymentService $octo,
    ) {}

    public function execute(int $chatId, string $text, TelegramBotService $telegram): void
    {
        $body = trim(preg_replace('/^\/pay\s+/i', '', $text));

        if (!preg_match('/^(.+?)\s+\$?(\d+(?:\.\d{1,2})?)\s*(.*)$/s', $body, $m)) {
            $telegram->sendMessage($chatId,
                "❌ Couldn't parse that.\n\n"
                . "Usage:\n"
                . "/pay <name> <amount>\n"
                . "/pay <name> <amount> <tour>\n\n"
                . "Examples:\n"
                . "/pay Delphine Ettori 153\n"
                . "/pay John Smith 250 Samarkand City Tour"
            );
            return;
        }

        $name   = trim($m[1]);
        $amount = (float) $m[2];
        $tour   = trim($m[3]) ?: null;

        if (strlen($name) < 2) {
            $telegram->sendMessage($chatId, "❌ Name too short (minimum 2 characters).");
            return;
        }
        if ($amount < 1 || $amount > 50000) {
            $telegram->sendMessage($chatId, "❌ Amount must be between \$1 and \$50,000.");
            return;
        }

        try {
            $inquiry = null;
            $result  = null;

            DB::transaction(function () use ($name, $amount, $tour, &$inquiry, &$result) {
                $inquiry = BookingInquiry::create([
                    'reference'          => BookingInquiry::generateReference(),
                    'source'             => BookingInquiry::SOURCE_MANUAL,
                    'tour_name_snapshot' => $tour ?? 'Manual Payment',
                    'customer_name'      => $name,
                    'customer_email'     => 'manual@jahongir-travel.uz',
                    'customer_phone'     => '+00000000000',
                    'people_adults'      => 1,
                    'price_quoted'       => $amount,
                    'status'             => BookingInquiry::STATUS_AWAITING_PAYMENT,
                    'submitted_at'       => now(),
                    'ip_address'         => '0.0.0.0',
                ]);

                $result = $this->octo->createPaymentLinkForInquiry($inquiry, $amount);
            });

            $uzsFormatted  = number_format($result['uzs_amount']);
            $amountDisplay = '$' . number_format($amount, 0);
            $tourLine      = $tour ? "\n📦 {$tour}" : '';

            $telegram->sendMessage($chatId,
                "💳 *Payment link ready*\n\n"
                . "👤 {$name}\n"
                . "💵 {$amountDisplay} → {$uzsFormatted} UZS"
                . $tourLine . "\n\n"
                . "🔗 {$result['url']}\n\n"
                . "📋 `{$inquiry->reference}`",
                ['parse_mode' => 'Markdown']
            );

        } catch (\Throwable $e) {
            Log::error('HandlePayCommandAction: Octo link generation failed', [
                'name'   => $name,
                'amount' => $amount,
                'error'  => $e->getMessage(),
            ]);
            $telegram->sendMessage($chatId,
                "❌ Failed to generate payment link.\n\nOcto error — try again or check the Filament panel."
            );
        }
    }
}
