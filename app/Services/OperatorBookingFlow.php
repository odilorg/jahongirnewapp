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
 * post-creation / browse-based operational actions.
 *
 * ── Creation flow ─────────────────────────────────────────────────────────────
 *   /newbooking
 *   idle → select_tour → enter_date → enter_adults → enter_children
 *        → enter_name  → enter_email → enter_phone → enter_hotel → confirm
 *        → booking_actions   (stays alive for immediate ops)
 *
 * ── Browse flow ───────────────────────────────────────────────────────────────
 *   /bookings → browse_list (paginated)
 *   tap row   → booking_actions (same action menu as Phase 1)
 *   ◀ Back    → browse_list (restores last page)
 *
 * ── Post-creation / browse states ────────────────────────────────────────────
 *   booking_actions   → action menu hub (shared by creation and browse)
 *   set_price_input   → waiting for price text
 *   set_pickup_input  → waiting for pickup location text
 *
 * ── Edit flow ─────────────────────────────────────────────────────────────────
 *   ops:edit          → edit field-picker menu (state stays booking_actions)
 *   ops:edit_name     → edit_name_input
 *   ops:edit_phone    → edit_phone_input
 *   ops:edit_email    → edit_email_input
 *   ops:edit_date     → edit_date_input
 *   ops:edit_pax      → edit_pax_input
 *   ops:edit_notes    → edit_notes_input
 *
 * ── Callback prefixes ─────────────────────────────────────────────────────────
 *   ops: → booking mutation actions (state-independent)
 *   brs: → browse navigation (state-independent)
 */
class OperatorBookingFlow
{
    public function __construct(
        private readonly WebsiteBookingService $bookingService,
        private readonly BookingOpsService     $opsService    = new BookingOpsService(),
        private readonly BookingBrowseService  $browseService = new BookingBrowseService(),
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

        // Global cancel at any step — resets everything including browse context
        if ($text === '/cancel' || $callback === 'cancel') {
            $session->reset();
            return ['text' => "❌ Booking cancelled."];
        }

        // ── Commands ─────────────────────────────────────────────────────────

        if ($text === '/start' || $text === '/help') {
            return ['text' =>
                "👋 <b>Jahongir Ops Bot</b>\n\n"
                . "Available commands:\n"
                . "/newbooking — create a new manual booking\n"
                . "/bookings   — browse and manage upcoming bookings\n"
                . "/cancel     — cancel the current flow\n\n"
                . "Tap a command to get started."
            ];
        }

        if ($text === '/newbooking') {
            // Clear browse context so "back" button is not shown on post-create menu
            $session->update(['data' => null, 'state' => 'idle']);
            return $this->stepSelectTour($session);
        }

        if ($text === '/bookings') {
            return $this->handleBrowseCommand($session);
        }

        // ── State-independent callback prefixes ───────────────────────────────

        // ops: — booking mutation actions
        if ($callback && str_starts_with($callback, 'ops:')) {
            return $this->handleOpsCallback($session, $chatId, $callback);
        }

        // brs: — browse navigation
        if ($callback && str_starts_with($callback, 'brs:')) {
            return $this->handleBrsCallback($session, $chatId, $callback);
        }

        // ── State machine ─────────────────────────────────────────────────────

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
            'booking_actions'  => $this->buildActionMenu($session),
            'browse_list'      => $this->buildBookingList($session),
            'set_price_input'  => $this->handleSetPriceInput($session, $chatId, $text),
            'set_pickup_input' => $this->handleSetPickupInput($session, $chatId, $text),
            'edit_name_input'  => $this->handleEditNameInput($session, $chatId, $text),
            'edit_phone_input' => $this->handleEditPhoneInput($session, $chatId, $text),
            'edit_email_input' => $this->handleEditEmailInput($session, $chatId, $text),
            'edit_date_input'  => $this->handleEditDateInput($session, $chatId, $text),
            'edit_pax_input'   => $this->handleEditPaxInput($session, $chatId, $text),
            'edit_notes_input' => $this->handleEditNotesInput($session, $chatId, $text),
            default            => ['text' => "Use /newbooking to create a booking, /bookings to browse, or /help for all commands."],
        };
    }

    // ── Browse: /bookings command ─────────────────────────────────────────────

    private function handleBrowseCommand(OperatorBookingSession $session): array
    {
        // Preserve show_cancelled preference across sessions; reset page to 1
        $showCancelled = (bool) ($session->getData('browse_show_cancelled') ?? false);
        $session->update([
            'state' => 'browse_list',
            'data'  => ['browse_page' => 1, 'browse_show_cancelled' => $showCancelled],
        ]);

        return $this->buildBookingList($session);
    }

    // ── Browse: brs: callback router ─────────────────────────────────────────

    private function handleBrsCallback(
        OperatorBookingSession $session,
        string $chatId,
        string $callback,
    ): array {
        $suffix = substr($callback, 4); // strip "brs:"

        return match (true) {
            $suffix === 'back'               => $this->browseBack($session),
            $suffix === 'rf'                 => $this->buildBookingList($session),
            $suffix === 'tog'                => $this->toggleCancelled($session),
            str_starts_with($suffix, 'pg:') => $this->goToPage($session, (int) substr($suffix, 3)),
            str_starts_with($suffix, 'op:') => $this->openBooking($session, (int) substr($suffix, 3)),
            default                          => $this->buildBookingList($session),
        };
    }

    private function browseBack(OperatorBookingSession $session): array
    {
        // Clear active booking but keep browse context
        $session->update([
            'state' => 'browse_list',
            'data'  => [
                'browse_page'           => $session->getData('browse_page', 1),
                'browse_show_cancelled' => $session->getData('browse_show_cancelled', false),
            ],
        ]);

        return $this->buildBookingList($session);
    }

    private function goToPage(OperatorBookingSession $session, int $page): array
    {
        $session->setData('browse_page', $page);
        return $this->buildBookingList($session);
    }

    private function toggleCancelled(OperatorBookingSession $session): array
    {
        $current = (bool) ($session->getData('browse_show_cancelled') ?? false);
        $session->setData('browse_show_cancelled', ! $current);
        $session->setData('browse_page', 1);
        return $this->buildBookingList($session);
    }

    private function openBooking(OperatorBookingSession $session, int $bookingId): array
    {
        $booking = $this->browseService->findWithRelations($bookingId);

        if (! $booking) {
            return ['text' => "⚠️ Booking not found — it may have been deleted.\n\nTap 🔄 to refresh the list.", 'reply_markup' => [
                'inline_keyboard' => [[['text' => '◀ Back to list', 'callback_data' => 'brs:back']]],
            ]];
        }

        // Set as the active booking — same mechanism used after /newbooking
        $session->setData('active_booking_id', $booking->id);
        $session->setState('booking_actions');

        return $this->buildActionMenu($session, $booking);
    }

    // ── Browse: list renderer ─────────────────────────────────────────────────

    private function buildBookingList(OperatorBookingSession $session): array
    {
        $page          = (int) ($session->getData('browse_page') ?? 1);
        $showCancelled = (bool) ($session->getData('browse_show_cancelled') ?? false);

        $result = $this->browseService->paginate($page, $showCancelled);

        $session->setState('browse_list');

        if ($result['total'] === 0) {
            $noResultMsg = $showCancelled
                ? "📋 No bookings in the next 30 days."
                : "📋 No upcoming pending/confirmed bookings in the next 30 days.";

            return [
                'text'         => $noResultMsg,
                'reply_markup' => [
                    'inline_keyboard' => [
                        [
                            ['text' => '🔄 Refresh',            'callback_data' => 'brs:rf'],
                            $this->cancelledToggleButton($showCancelled),
                        ],
                    ],
                ],
            ];
        }

        // Update page in case it was clamped inside paginate()
        if ($result['page'] !== $page) {
            $session->setData('browse_page', $result['page']);
        }

        // One button per booking row
        $buttons = array_map(fn ($item) => [
            ['text' => $item['label'], 'callback_data' => "brs:op:{$item['id']}"],
        ], $result['items']);

        // Pagination row (only show buttons that are meaningful)
        $navRow = [];
        if ($result['page'] > 1) {
            $navRow[] = ['text' => '◀ Prev', 'callback_data' => 'brs:pg:' . ($result['page'] - 1)];
        }
        if ($result['page'] < $result['pages']) {
            $navRow[] = ['text' => 'Next ▶', 'callback_data' => 'brs:pg:' . ($result['page'] + 1)];
        }
        if (! empty($navRow)) {
            $buttons[] = $navRow;
        }

        // Controls row
        $buttons[] = [
            ['text' => '🔄 Refresh', 'callback_data' => 'brs:rf'],
            $this->cancelledToggleButton($showCancelled),
        ];

        $toggleLabel = $showCancelled ? ' (incl. cancelled)' : '';
        $header      = "📋 <b>Upcoming bookings{$toggleLabel}</b>"
            . "  —  Page {$result['page']}/{$result['pages']}"
            . " ({$result['total']} total)\n\n"
            . "Tap a booking to manage:";

        return [
            'text'         => $header,
            'reply_markup' => ['inline_keyboard' => $buttons],
        ];
    }

    private function cancelledToggleButton(bool $showCancelled): array
    {
        return $showCancelled
            ? ['text' => '✅ Incl. cancelled', 'callback_data' => 'brs:tog']
            : ['text' => '➕ Show cancelled',  'callback_data' => 'brs:tog'];
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

        // Store booking_id and switch to the action menu; no browse context here.
        $session->update(['state' => 'booking_actions', 'data' => ['active_booking_id' => $booking->id]]);

        $label = $created ? '✅ Booking created' : '♻️ Booking already exists';

        return $this->buildActionMenu($session, $booking, "{$label}: <b>{$booking->booking_number}</b>\n\n");
    }

    // ── Post-creation / browse: ops: callback router ──────────────────────────

    private function handleOpsCallback(
        OperatorBookingSession $session,
        string $chatId,
        string $callback,
    ): array {
        $suffix = substr($callback, 4); // strip "ops:"

        $bookingId = $session->getData('active_booking_id');
        $booking   = $bookingId ? Booking::find($bookingId) : null;

        if (! $booking && $suffix !== 'new') {
            return ['text' => "⚠️ No active booking. Use /newbooking to create one or /bookings to browse."];
        }

        return match (true) {
            $suffix === 'menu'        => $this->buildActionMenu($session, $booking),
            $suffix === 'new'         => $this->startNew($session),
            $suffix === 'confirm'     => $this->opsConfirm($session, $booking, $chatId),
            $suffix === 'cancel_ask'  => $this->buildCancelConfirm($booking),
            $suffix === 'cancel_yes'  => $this->opsCancel($session, $booking, $chatId),
            $suffix === 'drivers'     => $this->buildDriverList($booking),
            $suffix === 'guides'      => $this->buildGuideList($booking),
            $suffix === 'price'       => $this->promptSetPrice($session),
            $suffix === 'pickup'      => $this->promptSetPickup($session),
            $suffix === 'edit'        => $this->buildEditMenu($session, $booking),
            $suffix === 'edit_name'   => $this->promptEditName($session),
            $suffix === 'edit_phone'  => $this->promptEditPhone($session),
            $suffix === 'edit_email'  => $this->promptEditEmail($session),
            $suffix === 'edit_date'   => $this->promptEditDate($session),
            $suffix === 'edit_pax'    => $this->promptEditPax($session),
            $suffix === 'edit_notes'  => $this->promptEditNotes($session),
            str_starts_with($suffix, 'driver:') => $this->opsAssignDriver(
                $session, $booking, $chatId, (int) substr($suffix, 7)
            ),
            str_starts_with($suffix, 'guide:')  => $this->opsAssignGuide(
                $session, $booking, $chatId, (int) substr($suffix, 6)
            ),
            default => $this->buildActionMenu($session, $booking),
        };
    }

    // ── Ops actions ───────────────────────────────────────────────────────────

    private function opsConfirm(OperatorBookingSession $session, Booking $booking, string $actor): array
    {
        try {
            $this->opsService->confirm($booking, $actor);
            $booking->refresh();
        } catch (\RuntimeException $e) {
            return ['text' => "⚠️ {$e->getMessage()}"];
        }

        return $this->buildActionMenu($session, $booking, "✅ Booking confirmed.\n\n");
    }

    private function opsCancel(OperatorBookingSession $session, Booking $booking, string $actor): array
    {
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
        $booking = $this->activeBooking($session);

        if (! $booking) {
            $session->reset();
            return ['text' => "⚠️ Session lost. Use /newbooking or /bookings."];
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
        $booking = $this->activeBooking($session);

        if (! $booking) {
            $session->reset();
            return ['text' => "⚠️ Session lost. Use /newbooking or /bookings."];
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
                'inline_keyboard' => [[['text' => '◀ Back', 'callback_data' => 'ops:menu']]],
            ],
        ];
    }

    private function promptSetPickup(OperatorBookingSession $session): array
    {
        $session->setState('set_pickup_input');

        return [
            'text'         => "📍 Enter the pickup location:",
            'reply_markup' => [
                'inline_keyboard' => [[['text' => '◀ Back', 'callback_data' => 'ops:menu']]],
            ],
        ];
    }

    // ── Edit: field-picker menu ───────────────────────────────────────────────

    /**
     * Show a field-picker so the operator can choose which booking detail to edit.
     * State stays at booking_actions — Back returns to the action menu via ops:menu.
     */
    private function buildEditMenu(OperatorBookingSession $session, Booking $booking): array
    {
        $guest = $booking->guest;
        $name  = $guest?->full_name  ?? '—';
        $phone = $guest?->phone      ?? '—';
        $email = $guest?->email      ?? '—';
        $date  = $booking->booking_start_date_time
            ? Carbon::parse($booking->booking_start_date_time)->format('d M Y')
            : '—';
        $pax   = $guest?->number_of_people ?? '—';
        $notes = $booking->special_requests ?: '—';

        $info = "✏️ <b>Edit {$booking->booking_number}</b>\n\n"
            . "👤 {$name}\n"
            . "📱 {$phone}  |  📧 {$email}\n"
            . "📅 {$date}  |  👥 {$pax} pax\n"
            . "📝 {$notes}\n\n"
            . "Tap a field to edit:";

        return [
            'text'         => $info,
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        ['text' => '✍️ Name',  'callback_data' => 'ops:edit_name'],
                        ['text' => '📱 Phone', 'callback_data' => 'ops:edit_phone'],
                        ['text' => '📧 Email', 'callback_data' => 'ops:edit_email'],
                    ],
                    [
                        ['text' => '📅 Date',  'callback_data' => 'ops:edit_date'],
                        ['text' => '👥 Pax',   'callback_data' => 'ops:edit_pax'],
                        ['text' => '📝 Notes', 'callback_data' => 'ops:edit_notes'],
                    ],
                    [['text' => '◀ Back', 'callback_data' => 'ops:menu']],
                ],
            ],
        ];
    }

    // ── Edit: prompt helpers ──────────────────────────────────────────────────

    private function promptEditName(OperatorBookingSession $session): array
    {
        $session->setState('edit_name_input');

        return [
            'text'         => "✍️ Enter the new guest full name:",
            'reply_markup' => [
                'inline_keyboard' => [[['text' => '◀ Back', 'callback_data' => 'ops:edit']]],
            ],
        ];
    }

    private function promptEditPhone(OperatorBookingSession $session): array
    {
        $session->setState('edit_phone_input');

        return [
            'text'         => "📱 Enter the new phone number (with country code):",
            'reply_markup' => [
                'inline_keyboard' => [[['text' => '◀ Back', 'callback_data' => 'ops:edit']]],
            ],
        ];
    }

    private function promptEditEmail(OperatorBookingSession $session): array
    {
        $session->setState('edit_email_input');

        return [
            'text'         => "📧 Enter the new email address:",
            'reply_markup' => [
                'inline_keyboard' => [[['text' => '◀ Back', 'callback_data' => 'ops:edit']]],
            ],
        ];
    }

    private function promptEditDate(OperatorBookingSession $session): array
    {
        $session->setState('edit_date_input');

        return [
            'text'         => "📅 Enter the new departure date (YYYY-MM-DD):\n\n"
                . "⚠️ Changing the date will reschedule all staff notifications.",
            'reply_markup' => [
                'inline_keyboard' => [[['text' => '◀ Back', 'callback_data' => 'ops:edit']]],
            ],
        ];
    }

    private function promptEditPax(OperatorBookingSession $session): array
    {
        $session->setState('edit_pax_input');

        return [
            'text'         => "👥 Enter the new total guest count (1–50):",
            'reply_markup' => [
                'inline_keyboard' => [[['text' => '◀ Back', 'callback_data' => 'ops:edit']]],
            ],
        ];
    }

    private function promptEditNotes(OperatorBookingSession $session): array
    {
        $session->setState('edit_notes_input');

        return [
            'text'         => "📝 Enter the new special requests / notes\n(or send a dash — to clear):",
            'reply_markup' => [
                'inline_keyboard' => [[['text' => '◀ Back', 'callback_data' => 'ops:edit']]],
            ],
        ];
    }

    // ── Edit: input handlers ──────────────────────────────────────────────────

    private function handleEditNameInput(
        OperatorBookingSession $session,
        string $chatId,
        ?string $text,
    ): array {
        $booking = $this->activeBooking($session);
        if (! $booking) {
            $session->reset();
            return ['text' => "⚠️ Session lost. Use /newbooking or /bookings."];
        }

        $name = trim($text ?? '');
        if (mb_strlen($name) < 2) {
            return ['text' => "⚠️ Name too short. Enter the guest's full name:"];
        }

        $parts     = explode(' ', $name, 2);
        $firstName = $parts[0];
        $lastName  = $parts[1] ?? '';

        try {
            $this->opsService->editGuestName($booking, $firstName, $lastName, $chatId);
        } catch (\RuntimeException $e) {
            $session->setState('booking_actions');
            return ['text' => "⚠️ {$e->getMessage()}"];
        }

        $session->setState('booking_actions');
        $booking = $this->activeBooking($session); // fresh — relations re-loaded on next access

        return $this->buildEditMenu($session, $booking);
    }

    private function handleEditPhoneInput(
        OperatorBookingSession $session,
        string $chatId,
        ?string $text,
    ): array {
        $booking = $this->activeBooking($session);
        if (! $booking) {
            $session->reset();
            return ['text' => "⚠️ Session lost. Use /newbooking or /bookings."];
        }

        $phone = trim($text ?? '');
        if (mb_strlen($phone) < 7) {
            return ['text' => "⚠️ Phone number too short. Include the country code:"];
        }

        try {
            $this->opsService->editGuestPhone($booking, $phone, $chatId);
        } catch (\RuntimeException $e) {
            $session->setState('booking_actions');
            return ['text' => "⚠️ {$e->getMessage()}"];
        }

        $session->setState('booking_actions');
        $booking = $this->activeBooking($session);

        return $this->buildEditMenu($session, $booking);
    }

    private function handleEditEmailInput(
        OperatorBookingSession $session,
        string $chatId,
        ?string $text,
    ): array {
        $booking = $this->activeBooking($session);
        if (! $booking) {
            $session->reset();
            return ['text' => "⚠️ Session lost. Use /newbooking or /bookings."];
        }

        $email = mb_strtolower(trim($text ?? ''));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['text' => "⚠️ That doesn't look like a valid email. Try again:"];
        }

        try {
            $this->opsService->editGuestEmail($booking, $email, $chatId);
        } catch (\RuntimeException $e) {
            $session->setState('booking_actions');
            return ['text' => "⚠️ {$e->getMessage()}"];
        }

        $session->setState('booking_actions');
        $booking = $this->activeBooking($session);

        return $this->buildEditMenu($session, $booking);
    }

    private function handleEditDateInput(
        OperatorBookingSession $session,
        string $chatId,
        ?string $text,
    ): array {
        $booking = $this->activeBooking($session);
        if (! $booking) {
            $session->reset();
            return ['text' => "⚠️ Session lost. Use /newbooking or /bookings."];
        }

        $raw = trim($text ?? '');

        try {
            $date = Carbon::createFromFormat('Y-m-d', $raw);

            if ($date->isPast() && ! $date->isToday()) {
                return ['text' => "⚠️ Date must be today or in the future (YYYY-MM-DD):"];
            }
        } catch (\Exception) {
            return ['text' => "⚠️ Invalid format. Enter as YYYY-MM-DD (e.g. 2026-06-15):"];
        }

        try {
            $this->opsService->editDate($booking, $date->format('Y-m-d 00:00:00'), $chatId);
            $booking->refresh();
        } catch (\RuntimeException $e) {
            $session->setState('booking_actions');
            return ['text' => "⚠️ {$e->getMessage()}"];
        }

        $session->setState('booking_actions');
        $booking = $this->activeBooking($session);

        return $this->buildEditMenu($session, $booking);
    }

    private function handleEditPaxInput(
        OperatorBookingSession $session,
        string $chatId,
        ?string $text,
    ): array {
        $booking = $this->activeBooking($session);
        if (! $booking) {
            $session->reset();
            return ['text' => "⚠️ Session lost. Use /newbooking or /bookings."];
        }

        $n = filter_var(trim($text ?? ''), FILTER_VALIDATE_INT);

        if ($n === false || $n < 1 || $n > 50) {
            return ['text' => "⚠️ Please enter a number between 1 and 50:"];
        }

        try {
            $this->opsService->editPax($booking, $n, $chatId);
        } catch (\RuntimeException $e) {
            $session->setState('booking_actions');
            return ['text' => "⚠️ {$e->getMessage()}"];
        }

        $session->setState('booking_actions');
        $booking = $this->activeBooking($session);

        return $this->buildEditMenu($session, $booking);
    }

    private function handleEditNotesInput(
        OperatorBookingSession $session,
        string $chatId,
        ?string $text,
    ): array {
        $booking = $this->activeBooking($session);
        if (! $booking) {
            $session->reset();
            return ['text' => "⚠️ Session lost. Use /newbooking or /bookings."];
        }

        $notes = trim($text ?? '');
        // A single dash clears the notes field
        $notes = ($notes === '-') ? '' : $notes;

        try {
            $this->opsService->editNotes($booking, $notes, $chatId);
        } catch (\RuntimeException $e) {
            $session->setState('booking_actions');
            return ['text' => "⚠️ {$e->getMessage()}"];
        }

        $session->setState('booking_actions');
        $booking = $this->activeBooking($session);

        return $this->buildEditMenu($session, $booking);
    }

    private function startNew(OperatorBookingSession $session): array
    {
        $session->reset();
        return $this->stepSelectTour($session);
    }

    // ── Action menu (shared by Phase 1 and Phase 2) ───────────────────────────

    /**
     * Render the booking detail + action buttons.
     *
     * Shows full booking details (tour, guest, date, driver, guide, price, pickup).
     * Adds "◀ Back to list" button when the session came from /bookings.
     *
     * @param  string|null $intro  Optional prefix line (e.g. "✅ Booking confirmed.\n\n")
     */
    private function buildActionMenu(
        OperatorBookingSession $session,
        ?Booking $booking = null,
        ?string  $intro   = null,
    ): array {
        if ($booking === null) {
            $booking = $this->activeBooking($session);
        }

        if (! $booking) {
            $session->reset();
            return ['text' => "⚠️ Booking not found. Use /newbooking or /bookings."];
        }

        $status = $booking->booking_status;

        // ── Booking detail block ──────────────────────────────────────────────
        $tourTitle = $booking->tour?->title ?? '—';
        $date      = $booking->booking_start_date_time
            ? Carbon::parse($booking->booking_start_date_time)->format('d M Y')
            : '—';
        $pax       = $booking->guest?->number_of_people
            ? "{$booking->guest->number_of_people} guests"
            : '—';
        $guestName = $booking->guest?->full_name ?? '—';
        $guestPhone= $booking->guest?->phone ?? '—';
        $driver    = $booking->driver?->full_name ?? '—';
        $guide     = $booking->guide?->full_name  ?? '—';
        $price     = $booking->amount     ? "\${$booking->amount}"      : '—';
        $pickup    = $booking->pickup_location ?: '—';

        $detail = ($intro ?? '')
            . "📋 <b>{$booking->booking_number}</b>  |  Status: <b>{$status}</b>\n"
            . "🗺 {$tourTitle}\n"
            . "📅 {$date}  |  👥 {$pax}\n"
            . "👤 {$guestName}  |  📱 {$guestPhone}\n"
            . "🚗 Driver: {$driver}  |  🧭 Guide: {$guide}\n"
            . "💰 {$price}  |  📍 {$pickup}";

        // ── Action buttons ────────────────────────────────────────────────────
        $buttons = [];

        if ($status !== 'cancelled') {
            $row1 = [];
            if ($status === 'pending') {
                $row1[] = ['text' => '✅ Confirm', 'callback_data' => 'ops:confirm'];
            }
            $row1[] = ['text' => '❌ Cancel booking', 'callback_data' => 'ops:cancel_ask'];
            $buttons[] = $row1;

            $buttons[] = [['text' => '✏️ Edit booking', 'callback_data' => 'ops:edit']];

            $buttons[] = [
                ['text' => '🚗 Assign driver', 'callback_data' => 'ops:drivers'],
                ['text' => '🧭 Assign guide',  'callback_data' => 'ops:guides'],
            ];
            $buttons[] = [
                ['text' => '💰 Set price',  'callback_data' => 'ops:price'],
                ['text' => '📍 Set pickup', 'callback_data' => 'ops:pickup'],
            ];
        }

        // "Back to list" if the operator came via /bookings
        $fromBrowse = $session->getData('browse_page') !== null;
        if ($fromBrowse) {
            $buttons[] = [['text' => '◀ Back to list', 'callback_data' => 'brs:back']];
        } else {
            $buttons[] = [['text' => '🔄 New booking', 'callback_data' => 'ops:new']];
        }

        return [
            'text'         => $detail,
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

    // ── Creation confirm prompt ───────────────────────────────────────────────

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

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function activeBooking(OperatorBookingSession $session): ?Booking
    {
        $id = $session->getData('active_booking_id');
        return $id ? Booking::find($id) : null;
    }

    protected function getOrCreateSession(string $chatId): OperatorBookingSession
    {
        return OperatorBookingSession::firstOrCreate(
            ['chat_id' => $chatId],
            ['state' => 'idle'],
        );
    }
}
