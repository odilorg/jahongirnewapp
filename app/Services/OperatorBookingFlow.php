<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Booking;
use App\Models\Driver;
use App\Models\Guide;
use App\Models\OperatorBookingSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handles the step-by-step Telegram flow for manual tour booking entry and
 * post-creation operational actions (confirm, cancel, assign driver/guide,
 * set price, set pickup location).
 *
 * ── Creation state machine ────────────────────────────────────────────────────
 *   idle → select_tour → enter_date → enter_adults → enter_children
 *        → enter_name  → enter_email → enter_phone → enter_hotel → confirm
 *        → booking_actions  (stays here until /newbooking or ops:new)
 *
 * ── Post-creation states ──────────────────────────────────────────────────────
 *   booking_actions  → action menu (hub state)
 *   set_price_input  → waiting for price text
 *   set_pickup_input → waiting for pickup text
 *   cancel_confirm   → "are you sure?" before cancellation
 *
 * ── ops: callbacks (state-independent) ───────────────────────────────────────
 *   ops:confirm, ops:cancel_ask, ops:cancel_yes, ops:drivers, ops:driver:{id},
 *   ops:guides, ops:guide:{id}, ops:price, ops:pickup, ops:menu, ops:new
 */
class OperatorBookingFlow
{
    public function __construct(
        private readonly WebsiteBookingService $bookingService,
        private readonly BookingOpsService     $opsService = new BookingOpsService(),
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

        // Global cancel at any step (returns to idle, clears active booking too)
        if ($text === '/cancel' || $callback === 'cancel') {
            $session->reset();
            return ['text' => "❌ Booking cancelled."];
        }

        // Command to start a new booking (clears any active booking context)
        if ($text === '/newbooking') {
            $session->reset();
            return $this->stepSelectTour($session);
        }

        // ops: callbacks are state-independent — handled before the state match.
        // This lets the action buttons work regardless of which step the session
        // is currently on (e.g. if the operator taps an old message).
        if ($callback && str_starts_with($callback, 'ops:')) {
            return $this->handleOpsCallback($session, $chatId, $callback);
        }

        return match ($session->state) {
            'select_tour'     => $this->handleTourSelection($session, $callback),
            'enter_date'      => $this->handleDate($session, $text),
            'enter_adults'    => $this->handleAdults($session, $text),
            'enter_children'  => $this->handleChildren($session, $text),
            'enter_name'      => $this->handleName($session, $text),
            'enter_email'     => $this->handleEmail($session, $text),
            'enter_phone'     => $this->handlePhone($session, $text),
            'enter_hotel'     => $this->handleHotel($session, $text, $callback),
            'confirm'         => $this->handleConfirm($session, $callback),
            'booking_actions' => $this->buildActionMenu($session),
            'set_price_input' => $this->handleSetPriceInput($session, $chatId, $text),
            'set_pickup_input'=> $this->handleSetPickupInput($session, $chatId, $text),
            'cancel_confirm'  => $this->buildActionMenu($session), // stale text → re-show menu
            default           => ['text' => "Use /newbooking to start a booking."],
        };
    }

    // ── Creation steps ────────────────────────────────────────────────────────

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

        // Store booking_id and switch to the action menu — don't reset to idle.
        $session->setData('active_booking_id', $booking->id);
        $session->setState('booking_actions');

        $label = $created ? '✅ Booking created' : '♻️ Booking already exists';

        $intro = "{$label}: <b>{$booking->booking_number}</b>\n\n";

        return $this->buildActionMenu($session, $booking, $intro);
    }

    // ── Post-creation ops callback router ─────────────────────────────────────

    /**
     * Route all ops: prefixed callbacks.
     *
     * Called before the state machine match, so action buttons work from
     * any state (old messages, stale keyboards, etc.).
     */
    private function handleOpsCallback(
        OperatorBookingSession $session,
        string $chatId,
        string $callback,
    ): array {
        $suffix = substr($callback, 4); // strip "ops:"

        // Fetch the active booking for this session.
        $bookingId = $session->getData('active_booking_id');
        $booking   = $bookingId ? Booking::find($bookingId) : null;

        if (! $booking && $suffix !== 'new') {
            return ['text' => "⚠️ No active booking. Use /newbooking to start one."];
        }

        return match (true) {
            // ── Navigation ────────────────────────────────────────────────
            $suffix === 'menu'       => $this->buildActionMenu($session, $booking),
            $suffix === 'new'        => $this->startNew($session),

            // ── Confirm booking ───────────────────────────────────────────
            $suffix === 'confirm'    => $this->opsConfirm($session, $booking, $chatId),

            // ── Cancel booking ────────────────────────────────────────────
            $suffix === 'cancel_ask' => $this->buildCancelConfirm($booking),
            $suffix === 'cancel_yes' => $this->opsCancel($session, $booking, $chatId),

            // ── Driver/guide lists ────────────────────────────────────────
            $suffix === 'drivers'    => $this->buildDriverList($booking),
            $suffix === 'guides'     => $this->buildGuideList($booking),

            // ── Assign driver ─────────────────────────────────────────────
            str_starts_with($suffix, 'driver:') => $this->opsAssignDriver(
                $session, $booking, $chatId, (int) substr($suffix, 7)
            ),

            // ── Assign guide ──────────────────────────────────────────────
            str_starts_with($suffix, 'guide:') => $this->opsAssignGuide(
                $session, $booking, $chatId, (int) substr($suffix, 6)
            ),

            // ── Price / pickup text-input entry ───────────────────────────
            $suffix === 'price'      => $this->promptSetPrice($session),
            $suffix === 'pickup'     => $this->promptSetPickup($session),

            default => $this->buildActionMenu($session, $booking),
        };
    }

    // ── Ops actions ───────────────────────────────────────────────────────────

    private function opsConfirm(
        OperatorBookingSession $session,
        Booking $booking,
        string $actor,
    ): array {
        try {
            $this->opsService->confirm($booking, $actor);
            $booking->refresh();
        } catch (\RuntimeException $e) {
            return ['text' => "⚠️ {$e->getMessage()}"];
        }

        return $this->buildActionMenu($session, $booking, "✅ Booking confirmed.\n\n");
    }

    private function opsCancel(
        OperatorBookingSession $session,
        Booking $booking,
        string $actor,
    ): array {
        try {
            $this->opsService->cancel($booking, $actor);
            $booking->refresh();
        } catch (\RuntimeException $e) {
            return ['text' => "⚠️ {$e->getMessage()}"];
        }

        return $this->buildActionMenu($session, $booking, "❌ Booking cancelled.\n\n");
    }

    private function opsAssignDriver(
        OperatorBookingSession $session,
        Booking $booking,
        string $actor,
        int $driverId,
    ): array {
        $driver = Driver::find($driverId);

        if (! $driver) {
            return ['text' => "⚠️ Driver not found."];
        }

        try {
            $this->opsService->assignDriver($booking, $driverId, $actor);
            $booking->refresh();
        } catch (\RuntimeException $e) {
            return ['text' => "⚠️ {$e->getMessage()}"];
        }

        return $this->buildActionMenu($session, $booking, "🚗 Driver assigned: <b>{$driver->full_name}</b>\n\n");
    }

    private function opsAssignGuide(
        OperatorBookingSession $session,
        Booking $booking,
        string $actor,
        int $guideId,
    ): array {
        $guide = Guide::find($guideId);

        if (! $guide) {
            return ['text' => "⚠️ Guide not found."];
        }

        try {
            $this->opsService->assignGuide($booking, $guideId, $actor);
            $booking->refresh();
        } catch (\RuntimeException $e) {
            return ['text' => "⚠️ {$e->getMessage()}"];
        }

        return $this->buildActionMenu($session, $booking, "🧭 Guide assigned: <b>{$guide->full_name}</b>\n\n");
    }

    private function handleSetPriceInput(
        OperatorBookingSession $session,
        string $chatId,
        ?string $text,
    ): array {
        $bookingId = $session->getData('active_booking_id');
        $booking   = $bookingId ? Booking::find($bookingId) : null;

        if (! $booking) {
            $session->reset();
            return ['text' => "⚠️ Session lost. Use /newbooking."];
        }

        $amount = filter_var(trim($text ?? ''), FILTER_VALIDATE_FLOAT);

        if ($amount === false || $amount < 0) {
            return ['text' => "⚠️ Please enter a valid price (e.g. 120 or 120.50):"];
        }

        try {
            $this->opsService->setPrice($booking, $amount, $chatId);
            $booking->refresh();
        } catch (\RuntimeException $e) {
            $session->setState('booking_actions');
            return ['text' => "⚠️ {$e->getMessage()}"];
        }

        $session->setState('booking_actions');

        return $this->buildActionMenu($session, $booking, "💰 Price set: <b>\${$amount}</b>\n\n");
    }

    private function handleSetPickupInput(
        OperatorBookingSession $session,
        string $chatId,
        ?string $text,
    ): array {
        $bookingId = $session->getData('active_booking_id');
        $booking   = $bookingId ? Booking::find($bookingId) : null;

        if (! $booking) {
            $session->reset();
            return ['text' => "⚠️ Session lost. Use /newbooking."];
        }

        $location = trim($text ?? '');

        if (mb_strlen($location) < 3) {
            return ['text' => "⚠️ Location too short. Please enter the pickup location:"];
        }

        try {
            $this->opsService->setPickupLocation($booking, $location, $chatId);
            $booking->refresh();
        } catch (\RuntimeException $e) {
            $session->setState('booking_actions');
            return ['text' => "⚠️ {$e->getMessage()}"];
        }

        $session->setState('booking_actions');

        return $this->buildActionMenu($session, $booking, "📍 Pickup set: <b>{$location}</b>\n\n");
    }

    // ── Prompt helpers ────────────────────────────────────────────────────────

    private function promptSetPrice(OperatorBookingSession $session): array
    {
        $session->setState('set_price_input');

        return [
            'text'         => "💰 Enter the booking price in USD (e.g. 120 or 120.50):",
            'reply_markup' => [
                'inline_keyboard' => [
                    [['text' => '◀ Back', 'callback_data' => 'ops:menu']],
                ],
            ],
        ];
    }

    private function promptSetPickup(OperatorBookingSession $session): array
    {
        $session->setState('set_pickup_input');

        return [
            'text'         => "📍 Enter the pickup location:",
            'reply_markup' => [
                'inline_keyboard' => [
                    [['text' => '◀ Back', 'callback_data' => 'ops:menu']],
                ],
            ],
        ];
    }

    private function startNew(OperatorBookingSession $session): array
    {
        $session->reset();
        return $this->stepSelectTour($session);
    }

    // ── Keyboard builders ─────────────────────────────────────────────────────

    /**
     * Build the action menu for the current active booking.
     *
     * @param  string|null $intro  Optional intro line prepended to the booking summary.
     */
    private function buildActionMenu(
        OperatorBookingSession $session,
        ?Booking $booking = null,
        ?string $intro = null,
    ): array {
        if ($booking === null) {
            $bookingId = $session->getData('active_booking_id');
            $booking   = $bookingId ? Booking::find($bookingId) : null;
        }

        if (! $booking) {
            $session->reset();
            return ['text' => "⚠️ Booking not found. Use /newbooking to start again."];
        }

        $status  = $booking->booking_status;
        $driver  = $booking->driver ? $booking->driver->full_name : '—';
        $guide   = $booking->guide  ? $booking->guide->full_name  : '—';
        $price   = $booking->amount  ? "\${$booking->amount}"      : '—';
        $pickup  = $booking->pickup_location ?: '—';

        $info = ($intro ?? '')
            . "📋 <b>{$booking->booking_number}</b>  |  Status: <b>{$status}</b>\n"
            . "🚗 Driver: {$driver}\n"
            . "🧭 Guide: {$guide}\n"
            . "💰 Price: {$price}\n"
            . "📍 Pickup: {$pickup}";

        $buttons = [];

        if ($status !== 'cancelled') {
            $row1 = [];
            if ($status === 'pending') {
                $row1[] = ['text' => '✅ Confirm', 'callback_data' => 'ops:confirm'];
            }
            $row1[] = ['text' => '❌ Cancel booking', 'callback_data' => 'ops:cancel_ask'];
            $buttons[] = $row1;

            $buttons[] = [
                ['text' => '🚗 Assign driver', 'callback_data' => 'ops:drivers'],
                ['text' => '🧭 Assign guide',  'callback_data' => 'ops:guides'],
            ];
            $buttons[] = [
                ['text' => '💰 Set price',  'callback_data' => 'ops:price'],
                ['text' => '📍 Set pickup', 'callback_data' => 'ops:pickup'],
            ];
        }

        $buttons[] = [['text' => '🔄 New booking', 'callback_data' => 'ops:new']];

        return [
            'text'         => $info,
            'reply_markup' => ['inline_keyboard' => $buttons],
        ];
    }

    private function buildCancelConfirm(Booking $booking): array
    {
        return [
            'text'         => "⚠️ Cancel <b>{$booking->booking_number}</b>?\n\nThis will delete all scheduled notifications.",
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        ['text' => '✅ Yes, cancel it', 'callback_data' => 'ops:cancel_yes'],
                        ['text' => '◀ Back',            'callback_data' => 'ops:menu'],
                    ],
                ],
            ],
        ];
    }

    private function buildDriverList(Booking $booking): array
    {
        $drivers = Driver::orderBy('first_name')->get(['id', 'first_name', 'last_name']);

        if ($drivers->isEmpty()) {
            return ['text' => "⚠️ No drivers found in the system."];
        }

        $buttons = $drivers->map(fn ($d) => [
            // callback_data max 64 bytes — "ops:driver:999" = 14 chars, safe
            ['text' => $d->full_name, 'callback_data' => "ops:driver:{$d->id}"],
        ])->all();

        $buttons[] = [['text' => '◀ Back', 'callback_data' => 'ops:menu']];

        $current = $booking->driver ? " (current: {$booking->driver->full_name})" : '';

        return [
            'text'         => "🚗 Select a driver{$current}:",
            'reply_markup' => ['inline_keyboard' => $buttons],
        ];
    }

    private function buildGuideList(Booking $booking): array
    {
        $guides = Guide::orderBy('first_name')->get(['id', 'first_name', 'last_name']);

        if ($guides->isEmpty()) {
            return ['text' => "⚠️ No guides found in the system."];
        }

        $buttons = $guides->map(fn ($g) => [
            ['text' => $g->full_name, 'callback_data' => "ops:guide:{$g->id}"],
        ])->all();

        $buttons[] = [['text' => '◀ Back', 'callback_data' => 'ops:menu']];

        $current = $booking->guide ? " (current: {$booking->guide->full_name})" : '';

        return [
            'text'         => "🧭 Select a guide{$current}:",
            'reply_markup' => ['inline_keyboard' => $buttons],
        ];
    }

    // ── Confirm prompt (creation step) ────────────────────────────────────────

    private function buildConfirmPrompt(OperatorBookingSession $session): array
    {
        $d = $session->data ?? [];

        $hotel = $d['hotel'] ?? '<i>not provided</i>';
        $pax   = ($d['adults'] ?? 0) . ' adults' . ($d['children'] ? ', ' . $d['children'] . ' children' : '');
        $date  = Carbon::parse($d['date'])->format('d M Y');

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

    // ── Session factory ───────────────────────────────────────────────────────

    protected function getOrCreateSession(string $chatId): OperatorBookingSession
    {
        return OperatorBookingSession::firstOrCreate(
            ['chat_id' => $chatId],
            ['state' => 'idle'],
        );
    }
}
