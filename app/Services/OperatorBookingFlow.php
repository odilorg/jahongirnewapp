<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OperatorBookingSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handles the step-by-step Telegram flow for manual tour booking entry.
 *
 * Called from TelegramDriverGuideSignUpController for messages/callbacks that
 * originate from the owner chat ID.
 *
 * The flow uses a database-backed state machine (OperatorBookingSession).
 * Each call to handle() advances the session one step forward, or returns
 * an error prompt so the operator can retry the current step.
 *
 * Entry point:   /newbooking command  → starts the flow
 * Exit points:   ✅ confirm           → creates booking, resets session
 *                ❌ cancel            → resets session at any step
 *                30-min idle timeout  → session auto-abandoned
 *
 * ── State machine ───────────────────────────────────────────────────────────
 *   idle → select_tour → enter_date → enter_adults → enter_children
 *        → enter_name  → enter_email → enter_phone → enter_hotel → confirm
 *        → idle (on confirm or cancel)
 */
class OperatorBookingFlow
{
    public function __construct(
        private readonly WebsiteBookingService $bookingService,
    ) {}

    // ── Public entry point ───────────────────────────────────────────────────

    /**
     * Handle an incoming text message or callback from the operator.
     *
     * @param  string      $chatId   Telegram chat_id (string)
     * @param  string|null $text     Text message (null for callbacks)
     * @param  string|null $callback Callback data from inline button (null for text)
     * @return array{text: string, reply_markup?: array}  Response to send back
     */
    public function handle(string $chatId, ?string $text, ?string $callback): array
    {
        $session = $this->getOrCreateSession($chatId);

        // Expire idle sessions gracefully
        if ($session->isExpired() && $session->state !== 'idle') {
            $session->reset();
            return ['text' => "⏰ Session expired. Use /newbooking to start again."];
        }

        // Global cancel at any step
        if ($text === '/cancel' || $callback === 'cancel') {
            $session->reset();
            return ['text' => "❌ Booking cancelled."];
        }

        // Command to start a new booking
        if ($text === '/newbooking') {
            $session->reset();
            return $this->stepSelectTour($session);
        }

        return match ($session->state) {
            'select_tour'    => $this->handleTourSelection($session, $callback),
            'enter_date'     => $this->handleDate($session, $text),
            'enter_adults'   => $this->handleAdults($session, $text),
            'enter_children' => $this->handleChildren($session, $text),
            'enter_name'     => $this->handleName($session, $text),
            'enter_email'    => $this->handleEmail($session, $text),
            'enter_phone'    => $this->handlePhone($session, $text),
            'enter_hotel'    => $this->handleHotel($session, $text, $callback),
            'confirm'        => $this->handleConfirm($session, $callback),
            default          => ['text' => "Use /newbooking to start a booking, or /cancel to reset."],
        };
    }

    // ── Steps ────────────────────────────────────────────────────────────────

    private function stepSelectTour(OperatorBookingSession $session): array
    {
        $session->setState('select_tour');

        $tours = DB::table('tours')->select('id', 'title')->orderBy('id')->get();

        $buttons = $tours->map(fn ($t) => [
            ['text' => $t->title, 'callback_data' => "tour:{$t->id}:{$t->title}"],
        ])->all();

        $buttons[] = [['text' => '❌ Cancel', 'callback_data' => 'cancel']];

        return [
            'text'         => "📋 <b>New manual booking</b>\n\nSelect the tour:",
            'reply_markup' => ['inline_keyboard' => $buttons],
        ];
    }

    private function handleTourSelection(OperatorBookingSession $session, ?string $callback): array
    {
        if (! $callback || ! str_starts_with($callback, 'tour:')) {
            return array_merge(
                ['text' => "Please tap a tour button above."],
                $this->stepSelectTour($session),
            );
        }

        // callback_data = "tour:{id}:{title}"
        [, $tourId, $tourName] = explode(':', $callback, 3);

        $session->setData('tour_id', (int) $tourId);
        $session->setData('tour_name', $tourName);
        $session->setState('enter_date');

        return ['text' => "✅ Tour: <b>{$tourName}</b>\n\n📅 Enter the departure date (YYYY-MM-DD):"];
    }

    private function handleDate(OperatorBookingSession $session, ?string $text): array
    {
        $text = trim($text ?? '');

        try {
            $date = Carbon::createFromFormat('Y-m-d', $text);

            if ($date->isPast() && ! $date->isToday()) {
                return ['text' => "⚠️ Date must be today or in the future. Try again (YYYY-MM-DD):"];
            }
        } catch (\Exception) {
            return ['text' => "⚠️ Invalid date format. Please enter as YYYY-MM-DD (e.g. 2026-05-20):"];
        }

        $session->setData('date', $date->format('Y-m-d'));
        $session->setState('enter_adults');

        return ['text' => "✅ Date: <b>{$date->format('d M Y')}</b>\n\n👥 Number of adults (min 1):"];
    }

    private function handleAdults(OperatorBookingSession $session, ?string $text): array
    {
        $n = filter_var(trim($text ?? ''), FILTER_VALIDATE_INT);

        if ($n === false || $n < 1 || $n > 50) {
            return ['text' => "⚠️ Please enter a valid number of adults (1–50):"];
        }

        $session->setData('adults', $n);
        $session->setState('enter_children');

        return ['text' => "✅ Adults: <b>{$n}</b>\n\n👶 Number of children (0 if none):"];
    }

    private function handleChildren(OperatorBookingSession $session, ?string $text): array
    {
        $n = filter_var(trim($text ?? ''), FILTER_VALIDATE_INT);

        if ($n === false || $n < 0 || $n > 50) {
            return ['text' => "⚠️ Please enter a valid number of children (0–50):"];
        }

        $session->setData('children', $n);
        $session->setState('enter_name');

        return ['text' => "✅ Children: <b>{$n}</b>\n\n👤 Guest full name:"];
    }

    private function handleName(OperatorBookingSession $session, ?string $text): array
    {
        $name = trim($text ?? '');

        if (mb_strlen($name) < 2) {
            return ['text' => "⚠️ Name seems too short. Please enter the guest's full name:"];
        }

        $session->setData('guest_name', $name);
        $session->setState('enter_email');

        return ['text' => "✅ Name: <b>{$name}</b>\n\n📧 Guest email address:"];
    }

    private function handleEmail(OperatorBookingSession $session, ?string $text): array
    {
        $email = mb_strtolower(trim($text ?? ''));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['text' => "⚠️ That doesn't look like a valid email. Please try again:"];
        }

        $session->setData('guest_email', $email);
        $session->setState('enter_phone');

        return ['text' => "✅ Email: <b>{$email}</b>\n\n📱 Guest phone number (with country code):"];
    }

    private function handlePhone(OperatorBookingSession $session, ?string $text): array
    {
        $phone = trim($text ?? '');

        if (mb_strlen($phone) < 7) {
            return ['text' => "⚠️ Phone number seems too short. Please include country code:"];
        }

        $session->setData('guest_phone', $phone);
        $session->setState('enter_hotel');

        return [
            'text'         => "✅ Phone: <b>{$phone}</b>\n\n🏨 Hotel / pickup location (or tap Skip):",
            'reply_markup' => [
                'inline_keyboard' => [
                    [['text' => '⏭ Skip (no hotel yet)', 'callback_data' => 'hotel:skip']],
                    [['text' => '❌ Cancel', 'callback_data' => 'cancel']],
                ],
            ],
        ];
    }

    private function handleHotel(OperatorBookingSession $session, ?string $text, ?string $callback): array
    {
        if ($callback === 'hotel:skip') {
            $session->setData('hotel', null);
        } else {
            $hotel = trim($text ?? '');
            if (mb_strlen($hotel) < 2) {
                return [
                    'text'         => "⚠️ Hotel name too short, or tap Skip:",
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [['text' => '⏭ Skip (no hotel yet)', 'callback_data' => 'hotel:skip']],
                        ],
                    ],
                ];
            }
            $session->setData('hotel', $hotel);
        }

        $session->setState('confirm');

        return $this->buildConfirmPrompt($session);
    }

    private function handleConfirm(OperatorBookingSession $session, ?string $callback): array
    {
        if ($callback !== 'confirm:yes') {
            $session->reset();
            return ['text' => "❌ Booking cancelled."];
        }

        try {
            $data = $session->toBookingData();
            ['booking' => $booking, 'created' => $created] = $this->bookingService->createFromWebsite($data);
        } catch (\Throwable $e) {
            Log::error('OperatorBookingFlow: booking creation failed', [
                'chat_id' => $session->chat_id,
                'error'   => $e->getMessage(),
                'data'    => $session->data,
            ]);

            $session->reset();

            return ['text' => "❌ Failed to create booking: {$e->getMessage()}\n\nCheck admin email for the submission details."];
        }

        $session->reset();

        $label = $created ? '✅ Booking created' : '♻️ Booking already exists';

        return [
            'text' => "{$label}\n\n<b>{$booking->booking_number}</b>\nStatus: pending — confirm price and set driver/guide in the admin panel.",
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function buildConfirmPrompt(OperatorBookingSession $session): array
    {
        $d = $session->data ?? [];

        $hotel    = $d['hotel'] ?? '<i>not provided</i>';
        $pax      = ($d['adults'] ?? 0) . ' adults' . ($d['children'] ? ', ' . $d['children'] . ' children' : '');
        $date     = Carbon::parse($d['date'])->format('d M Y');

        $summary = "📋 <b>Booking summary</b>\n\n"
            . "🗺 Tour:    <b>{$d['tour_name']}</b>\n"
            . "📅 Date:    <b>{$date}</b>\n"
            . "👥 Guests:  <b>{$pax}</b>\n"
            . "👤 Name:    <b>{$d['guest_name']}</b>\n"
            . "📧 Email:   <b>{$d['guest_email']}</b>\n"
            . "📱 Phone:   <b>{$d['guest_phone']}</b>\n"
            . "🏨 Hotel:   <b>{$hotel}</b>\n\n"
            . "Confirm?";

        return [
            'text'         => $summary,
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        ['text' => '✅ Create booking', 'callback_data' => 'confirm:yes'],
                        ['text' => '❌ Cancel',         'callback_data' => 'cancel'],
                    ],
                ],
            ],
        ];
    }

    protected function getOrCreateSession(string $chatId): OperatorBookingSession
    {
        return OperatorBookingSession::firstOrCreate(
            ['chat_id' => $chatId],
            ['state' => 'idle'],
        );
    }
}
