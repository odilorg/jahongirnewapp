<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BotOperator;
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
    /** Set on every handle() call from the authenticated operator. */
    private ?BotOperator $operator = null;

    public function __construct(
        private readonly WebsiteBookingService $bookingService,
        private readonly BookingOpsService     $opsService      = new BookingOpsService(),
        private readonly BookingBrowseService  $browseService   = new BookingBrowseService(),
        private readonly DriverService         $driverService   = new DriverService(),
        private readonly GuideService          $guideService    = new GuideService(),
    ) {}

    // ── Public entry point ───────────────────────────────────────────────────

    /**
     * Handle an incoming text message or callback from the operator.
     *
     * @param  string           $chatId    Telegram chat_id (string)
     * @param  string|null      $text      Text message (null for callbacks)
     * @param  string|null      $callback  Callback data from inline button (null for text)
     * @param  BotOperator|null $operator  Authenticated operator (null = unauthenticated, deny all mutations)
     * @return array{text: string, reply_markup?: array}  Response to send back
     */
    public function handle(string $chatId, ?string $text, ?string $callback, ?BotOperator $operator = null): array
    {
        $this->operator = $operator;

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
                . "/staff      — manage drivers and guides\n"
                . "/cancel     — cancel the current flow\n\n"
                . "Tap a command to get started."
            ];
        }

        if ($text === '/staff') {
            return $this->checkPerm(BotOperator::PERM_EDIT, '🚫 Staff management requires at least operator role.') ?? $this->buildStaffMenu();
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

        // staff: — driver/guide management (read-only at PERM_EDIT; mutations guarded inside)
        if ($callback && str_starts_with($callback, 'staff:')) {
            return $this->checkPerm(BotOperator::PERM_EDIT, '🚫 Staff management requires at least operator role.') ?? $this->handleStaffCallback($session, $chatId, $callback);
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
            'edit_name_input'   => $this->handleEditNameInput($session, $chatId, $text),
            'edit_phone_input'  => $this->handleEditPhoneInput($session, $chatId, $text),
            'edit_email_input'  => $this->handleEditEmailInput($session, $chatId, $text),
            'edit_date_input'   => $this->handleEditDateInput($session, $chatId, $text),
            'edit_pax_input'    => $this->handleEditPaxInput($session, $chatId, $text),
            'edit_notes_input'  => $this->handleEditNotesInput($session, $chatId, $text),
            'add_driver'        => $this->handleAddDriverInput($session, $chatId, $text),
            'add_guide'         => $this->handleAddGuideInput($session, $chatId, $text),
            'edit_driver_field' => $this->handleEditDriverFieldInput($session, $chatId, $text),
            'edit_guide_field'  => $this->handleEditGuideFieldInput($session, $chatId, $text),
            'search_driver'     => $this->handleSearchDriverInput($session, $chatId, $text),
            'search_guide'      => $this->handleSearchGuideInput($session, $chatId, $text),
            default             => ['text' => "Use /newbooking to create a booking, /bookings to browse, /staff for staff, or /help for all commands."],
        };
    }

    // ── Auth helpers ─────────────────────────────────────────────────────────

    /**
     * Returns an actor string for audit logging.
     * Prefers telegram_user_id over chat_id so audit entries identify the person, not the chat.
     */
    private function actor(string $chatId): string
    {
        return $this->operator?->telegram_user_id ?? $chatId;
    }

    /**
     * Guard helper. Returns a denial response array if the current operator lacks $permission,
     * or null if the action is allowed.
     *
     * Usage (null-coalescing pattern):
     *   return $this->checkPerm(BotOperator::PERM_MANAGE) ?? $this->opsConfirm(...);
     */
    private function checkPerm(string $permission, string $message = '🚫 You do not have permission for this action.'): ?array
    {
        if ($this->operator === null || ! $this->operator->can($permission)) {
            return ['text' => $message];
        }

        return null;
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

        $booking = $this->activeBooking($session);

        if (! $booking && $suffix !== 'new') {
            return ['text' => "⚠️ No active booking. Use /newbooking to create one or /bookings to browse."];
        }

        return match (true) {
            $suffix === 'menu'       => $this->buildActionMenu($session, $booking),
            $suffix === 'new'        => $this->checkPerm(BotOperator::PERM_CREATE, '🚫 You cannot create bookings.') ?? $this->startNew($session),
            $suffix === 'confirm'    => $this->checkPerm(BotOperator::PERM_MANAGE, '🚫 Only managers can confirm bookings.') ?? $this->opsConfirm($session, $booking, $this->actor($chatId)),
            $suffix === 'cancel_ask' => $this->checkPerm(BotOperator::PERM_MANAGE, '🚫 Only managers can cancel bookings.') ?? $this->buildCancelConfirm($booking),
            $suffix === 'cancel_yes' => $this->checkPerm(BotOperator::PERM_MANAGE, '🚫 Only managers can cancel bookings.') ?? $this->opsCancel($session, $booking, $this->actor($chatId)),
            $suffix === 'drivers'    => $this->checkPerm(BotOperator::PERM_EDIT, '🚫 You cannot assign drivers.') ?? $this->buildDriverList($booking),
            $suffix === 'guides'     => $this->checkPerm(BotOperator::PERM_EDIT, '🚫 You cannot assign guides.') ?? $this->buildGuideList($booking),
            $suffix === 'price'      => $this->checkPerm(BotOperator::PERM_MANAGE, '🚫 Only managers can set the price.') ?? $this->promptSetPrice($session),
            $suffix === 'pickup'     => $this->checkPerm(BotOperator::PERM_EDIT, '🚫 You cannot set the pickup location.') ?? $this->promptSetPickup($session),
            $suffix === 'edit'       => $this->checkPerm(BotOperator::PERM_EDIT, '🚫 You cannot edit booking details.') ?? $this->buildEditMenu($session, $booking),
            $suffix === 'edit_name'  => $this->checkPerm(BotOperator::PERM_EDIT) ?? $this->promptEditName($session),
            $suffix === 'edit_phone' => $this->checkPerm(BotOperator::PERM_EDIT) ?? $this->promptEditPhone($session),
            $suffix === 'edit_email' => $this->checkPerm(BotOperator::PERM_EDIT) ?? $this->promptEditEmail($session),
            $suffix === 'edit_date'  => $this->checkPerm(BotOperator::PERM_EDIT) ?? $this->promptEditDate($session),
            $suffix === 'edit_pax'   => $this->checkPerm(BotOperator::PERM_EDIT) ?? $this->promptEditPax($session),
            $suffix === 'edit_notes' => $this->checkPerm(BotOperator::PERM_EDIT) ?? $this->promptEditNotes($session),
            str_starts_with($suffix, 'driver:') => $this->checkPerm(BotOperator::PERM_EDIT, '🚫 You cannot assign drivers.') ?? $this->opsAssignDriver(
                $session, $booking, $this->actor($chatId), (int) substr($suffix, 7)
            ),
            str_starts_with($suffix, 'guide:')  => $this->checkPerm(BotOperator::PERM_EDIT, '🚫 You cannot assign guides.') ?? $this->opsAssignGuide(
                $session, $booking, $this->actor($chatId), (int) substr($suffix, 6)
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

        if (! $driver->is_active) {
            return ['text' => "⚠️ {$driver->full_name} is inactive and cannot be assigned. Reactivate them first via /staff."];
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

        if (! $guide->is_active) {
            return ['text' => "⚠️ {$guide->full_name} is inactive and cannot be assigned. Reactivate them first via /staff."];
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

        // Re-check permission at input step (guards against session replay after role change)
        if ($deny = $this->checkPerm(BotOperator::PERM_MANAGE, '🚫 Only managers can set the price.')) {
            $session->setState('booking_actions');
            return $deny;
        }

        try {
            $this->opsService->setPrice($booking, $amount, $this->actor($chatId));
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
            $this->opsService->setPickupLocation($booking, $location, $this->actor($chatId));
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

        if ($deny = $this->checkPerm(BotOperator::PERM_EDIT)) {
            $session->setState('booking_actions');
            return $deny;
        }

        try {
            $this->opsService->editGuestName($booking, $firstName, $lastName, $this->actor($chatId));
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

        if ($deny = $this->checkPerm(BotOperator::PERM_EDIT)) {
            $session->setState('booking_actions');
            return $deny;
        }

        try {
            $this->opsService->editGuestPhone($booking, $phone, $this->actor($chatId));
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

        if ($deny = $this->checkPerm(BotOperator::PERM_EDIT)) {
            $session->setState('booking_actions');
            return $deny;
        }

        try {
            $this->opsService->editGuestEmail($booking, $email, $this->actor($chatId));
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

        if ($deny = $this->checkPerm(BotOperator::PERM_EDIT)) {
            $session->setState('booking_actions');
            return $deny;
        }

        try {
            $this->opsService->editDate($booking, $date->format('Y-m-d 00:00:00'), $this->actor($chatId));
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

        if ($deny = $this->checkPerm(BotOperator::PERM_EDIT)) {
            $session->setState('booking_actions');
            return $deny;
        }

        try {
            $this->opsService->editPax($booking, $n, $this->actor($chatId));
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

        if ($deny = $this->checkPerm(BotOperator::PERM_EDIT)) {
            $session->setState('booking_actions');
            return $deny;
        }

        try {
            $this->opsService->editNotes($booking, $notes, $this->actor($chatId));
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

        // ── Action buttons (filtered by role) ────────────────────────────────
        $canManage = $this->operator?->can(BotOperator::PERM_MANAGE) ?? false;
        $canEdit   = $this->operator?->can(BotOperator::PERM_EDIT)   ?? false;
        $canCreate = $this->operator?->can(BotOperator::PERM_CREATE)  ?? false;

        $buttons = [];

        if ($status !== 'cancelled') {
            $row1 = [];
            if ($status === 'pending' && $canManage) {
                $row1[] = ['text' => '✅ Confirm', 'callback_data' => 'ops:confirm'];
            }
            if ($canManage) {
                $row1[] = ['text' => '❌ Cancel booking', 'callback_data' => 'ops:cancel_ask'];
            }
            if ($row1) {
                $buttons[] = $row1;
            }

            if ($canEdit) {
                $buttons[] = [['text' => '✏️ Edit booking', 'callback_data' => 'ops:edit']];

                $buttons[] = [
                    ['text' => '🚗 Assign driver', 'callback_data' => 'ops:drivers'],
                    ['text' => '🧭 Assign guide',  'callback_data' => 'ops:guides'],
                ];
                $buttons[] = [['text' => '📍 Set pickup', 'callback_data' => 'ops:pickup']];
            }

            if ($canManage) {
                $buttons[] = [['text' => '💰 Set price', 'callback_data' => 'ops:price']];
            }
        }

        // "Back to list" if the operator came via /bookings
        $fromBrowse = $session->getData('browse_page') !== null;
        if ($fromBrowse) {
            $buttons[] = [['text' => '◀ Back to list', 'callback_data' => 'brs:back']];
        } elseif ($canCreate) {
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
        $drivers = Driver::where('is_active', true)->orderBy('first_name')->get(['id', 'first_name', 'last_name']);

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
        $guides = Guide::where('is_active', true)->orderBy('first_name')->get(['id', 'first_name', 'last_name']);

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

    protected function activeBooking(OperatorBookingSession $session): ?Booking
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

    // ── Staff management ─────────────────────────────────────────────────────
    //
    // Entry points:
    //   /staff              → buildStaffMenu()
    //   staff:drivers       → staffDriverList()
    //   staff:driver:{id}   → staffDriverDetail(id)
    //   staff:driver:add    → start add_driver state
    //   staff:driver:{id}:edit         → staffDriverEditMenu(id)
    //   staff:driver:{id}:edit:{field} → start edit_driver_field state
    //   staff:driver:{id}:toggle       → toggle is_active
    //   staff:guides / guide variants  → mirror of above
    //
    // Session states:
    //   add_driver        data: {step, collected:{}}
    //   add_guide         data: {step, collected:{}}
    //   edit_driver_field data: {driver_id, field}
    //   edit_guide_field  data: {guide_id, field}

    private function buildStaffMenu(): array
    {
        return [
            'text'         => "👥 <b>Staff Management</b>\n\nChoose a category:",
            'reply_markup' => [
                'inline_keyboard' => [
                    [['text' => '🚗 Drivers', 'callback_data' => 'staff:drivers']],
                    [['text' => '🧭 Guides',  'callback_data' => 'staff:guides']],
                ],
            ],
        ];
    }

    private function handleStaffCallback(
        OperatorBookingSession $session,
        string $chatId,
        string $callback,
    ): array {
        $suffix    = substr($callback, 6); // strip "staff:"
        $canMutate = $this->operator?->can(BotOperator::PERM_MANAGE) ?? false;

        // ── Drivers ───────────────────────────────────────────────────────────

        // Filtered/paginated list: staff:drivers[:filter[:page:N]]
        if ($suffix === 'drivers' || preg_match('/^drivers(?::(all|active|inactive))?(?::page:(\d+))?$/', $suffix, $lm)) {
            $filter = $lm[1] ?? 'all';
            $page   = isset($lm[2]) ? (int) $lm[2] : 1;
            return $this->staffDriverList($filter, $page);
        }

        if ($suffix === 'driver:search') {
            $session->update(['state' => 'search_driver', 'data' => null]);
            return ['text' => "🔍 Search drivers\n\nType a name or phone number:"];
        }

        if ($suffix === 'driver:add') {
            if (! $canMutate) {
                return ['text' => '🚫 Creating staff requires manager role.'];
            }
            $session->update(['state' => 'add_driver', 'data' => ['step' => 'first_name', 'collected' => []]]);
            return ['text' => "🚗 <b>Add Driver</b> — step 1/5\n\nEnter first name:"];
        }

        if (preg_match('/^driver:(\d+)$/', $suffix, $m)) {
            return $this->staffDriverDetail((int) $m[1]);
        }

        if (preg_match('/^driver:(\d+):toggle$/', $suffix, $m)) {
            if (! $canMutate) {
                return ['text' => '🚫 Activating/deactivating staff requires manager role.'];
            }
            return $this->staffToggleDriver((int) $m[1], $this->actor($chatId));
        }

        if (preg_match('/^driver:(\d+):edit$/', $suffix, $m)) {
            if (! $canMutate) {
                return ['text' => '🚫 Editing staff requires manager role.'];
            }
            return $this->staffDriverEditMenu((int) $m[1]);
        }

        if (preg_match('/^driver:(\d+):edit:(.+)$/', $suffix, $m)) {
            if (! $canMutate) {
                return ['text' => '🚫 Editing staff requires manager role.'];
            }
            $driverId = (int) $m[1];
            $field    = $m[2];
            $session->update(['state' => 'edit_driver_field', 'data' => ['driver_id' => $driverId, 'field' => $field]]);
            return ['text' => $this->editDriverFieldPrompt($field)];
        }

        // ── Guides ────────────────────────────────────────────────────────────

        if ($suffix === 'guides' || preg_match('/^guides(?::(all|active|inactive))?(?::page:(\d+))?$/', $suffix, $lm)) {
            $filter = $lm[1] ?? 'all';
            $page   = isset($lm[2]) ? (int) $lm[2] : 1;
            return $this->staffGuideList($filter, $page);
        }

        if ($suffix === 'guide:search') {
            $session->update(['state' => 'search_guide', 'data' => null]);
            return ['text' => "🔍 Search guides\n\nType a name or phone number:"];
        }

        if ($suffix === 'guide:add') {
            if (! $canMutate) {
                return ['text' => '🚫 Creating staff requires manager role.'];
            }
            $session->update(['state' => 'add_guide', 'data' => ['step' => 'first_name', 'collected' => []]]);
            return ['text' => "🧭 <b>Add Guide</b> — step 1/5\n\nEnter first name:"];
        }

        if (preg_match('/^guide:(\d+)$/', $suffix, $m)) {
            return $this->staffGuideDetail((int) $m[1]);
        }

        if (preg_match('/^guide:(\d+):toggle$/', $suffix, $m)) {
            if (! $canMutate) {
                return ['text' => '🚫 Activating/deactivating staff requires manager role.'];
            }
            return $this->staffToggleGuide((int) $m[1], $this->actor($chatId));
        }

        if (preg_match('/^guide:(\d+):edit$/', $suffix, $m)) {
            if (! $canMutate) {
                return ['text' => '🚫 Editing staff requires manager role.'];
            }
            return $this->staffGuideEditMenu((int) $m[1]);
        }

        if (preg_match('/^guide:(\d+):edit:(.+)$/', $suffix, $m)) {
            if (! $canMutate) {
                return ['text' => '🚫 Editing staff requires manager role.'];
            }
            $guideId = (int) $m[1];
            $field   = $m[2];
            $session->update(['state' => 'edit_guide_field', 'data' => ['guide_id' => $guideId, 'field' => $field]]);
            return ['text' => $this->editGuideFieldPrompt($field)];
        }

        return $this->buildStaffMenu();
    }

    // ── Driver list / detail / toggle / edit ─────────────────────────────────

    private function staffDriverList(string $filter = 'all', int $page = 1): array
    {
        $perPage   = 15;
        $canMutate = $this->operator?->can(BotOperator::PERM_MANAGE) ?? false;

        $query = Driver::orderBy('first_name');
        if ($filter === 'active') {
            $query->where('is_active', true);
        } elseif ($filter === 'inactive') {
            $query->where('is_active', false);
        }
        $all      = $query->get(['id', 'first_name', 'last_name', 'is_active']);
        $total    = $all->count();
        $pages    = (int) ceil($total / $perPage) ?: 1;
        $page     = max(1, min($page, $pages));
        $drivers  = $all->forPage($page, $perPage);

        $filterRow = [
            ['text' => ($filter === 'all'      ? '● All'      : '○ All'),      'callback_data' => 'staff:drivers:all'],
            ['text' => ($filter === 'active'   ? '● Active'   : '○ Active'),   'callback_data' => 'staff:drivers:active'],
            ['text' => ($filter === 'inactive' ? '● Inactive' : '○ Inactive'), 'callback_data' => 'staff:drivers:inactive'],
        ];

        if ($drivers->isEmpty()) {
            $buttons = [$filterRow];
            $buttons[] = [['text' => '🔍 Search', 'callback_data' => 'staff:driver:search']];
            if ($canMutate) {
                $buttons[] = [['text' => '➕ Add driver', 'callback_data' => 'staff:driver:add']];
            }
            $buttons[] = [['text' => '◀ Back', 'callback_data' => 'staff:menu']];
            return [
                'text'         => "🚗 <b>Drivers</b>\n\nNo drivers found.",
                'reply_markup' => ['inline_keyboard' => $buttons],
            ];
        }

        $buttons   = [$filterRow];
        $buttons[] = [['text' => '🔍 Search', 'callback_data' => 'staff:driver:search']];

        foreach ($drivers as $d) {
            $buttons[] = [[
                'text'          => ($d->is_active ? '✅' : '🔴') . ' ' . $d->full_name,
                'callback_data' => "staff:driver:{$d->id}",
            ]];
        }

        // Pagination row
        if ($pages > 1) {
            $pagRow = [];
            if ($page > 1) {
                $pagRow[] = ['text' => '◀ Prev', 'callback_data' => "staff:drivers:{$filter}:page:" . ($page - 1)];
            }
            $pagRow[] = ['text' => "{$page}/{$pages}", 'callback_data' => "staff:drivers:{$filter}:page:{$page}"];
            if ($page < $pages) {
                $pagRow[] = ['text' => 'Next ▶', 'callback_data' => "staff:drivers:{$filter}:page:" . ($page + 1)];
            }
            $buttons[] = $pagRow;
        }

        if ($canMutate) {
            $buttons[] = [['text' => '➕ Add driver', 'callback_data' => 'staff:driver:add']];
        }
        $buttons[] = [['text' => '◀ Back', 'callback_data' => 'staff:menu']];

        $filterLabel = $filter !== 'all' ? " · " . ucfirst($filter) . " only" : '';

        return [
            'text'         => "🚗 <b>Drivers</b> ({$total} total{$filterLabel})\n\n✅ active · 🔴 inactive",
            'reply_markup' => ['inline_keyboard' => $buttons],
        ];
    }

    private function staffDriverDetail(int $driverId): array
    {
        $driver = Driver::find($driverId);

        if (! $driver) {
            return ['text' => "⚠️ Driver not found."];
        }

        $status    = $driver->is_active ? '✅ Active' : '🔴 Inactive';
        $toggleLbl = $driver->is_active ? '🔴 Deactivate' : '✅ Activate';
        $langs     = $driver->phone02 ? "\n📱 Phone 2: {$driver->phone02}" : '';

        $text = "🚗 <b>{$driver->full_name}</b>\n\n"
            . "📱 Phone: {$driver->phone01}{$langs}\n"
            . "📧 Email: {$driver->email}\n"
            . "⛽ Fuel:  {$driver->fuel_type}\n"
            . "🏙 City:  " . ($driver->address_city ?? '—') . "\n"
            . "Status: {$status}";

        $keyboard = [];
        if ($this->operator?->can(BotOperator::PERM_MANAGE)) {
            $keyboard[] = [['text' => '✏️ Edit',  'callback_data' => "staff:driver:{$driverId}:edit"]];
            $keyboard[] = [['text' => $toggleLbl,  'callback_data' => "staff:driver:{$driverId}:toggle"]];
        }
        $keyboard[] = [['text' => '◀ Back', 'callback_data' => 'staff:drivers']];

        return [
            'text'         => $text,
            'reply_markup' => ['inline_keyboard' => $keyboard],
        ];
    }

    private function staffToggleDriver(int $driverId, string $actor): array
    {
        $driver = Driver::find($driverId);

        if (! $driver) {
            return ['text' => "⚠️ Driver not found."];
        }

        $this->driverService->setActive($driver, ! $driver->is_active, $actor);
        $driver->refresh();

        return $this->staffDriverDetail($driverId);
    }

    private function staffDriverEditMenu(int $driverId): array
    {
        $driver = Driver::find($driverId);

        if (! $driver) {
            return ['text' => "⚠️ Driver not found."];
        }

        return [
            'text'         => "✏️ <b>Edit {$driver->full_name}</b>\n\nWhich field?",
            'reply_markup' => [
                'inline_keyboard' => [
                    [['text' => 'First name',  'callback_data' => "staff:driver:{$driverId}:edit:first_name"]],
                    [['text' => 'Last name',   'callback_data' => "staff:driver:{$driverId}:edit:last_name"]],
                    [['text' => 'Phone 1',     'callback_data' => "staff:driver:{$driverId}:edit:phone01"]],
                    [['text' => 'Phone 2',     'callback_data' => "staff:driver:{$driverId}:edit:phone02"]],
                    [['text' => 'Email',       'callback_data' => "staff:driver:{$driverId}:edit:email"]],
                    [['text' => 'Fuel type',   'callback_data' => "staff:driver:{$driverId}:edit:fuel_type"]],
                    [['text' => 'City',        'callback_data' => "staff:driver:{$driverId}:edit:address_city"]],
                    [['text' => '◀ Back',      'callback_data' => "staff:driver:{$driverId}"]],
                ],
            ],
        ];
    }

    private function editDriverFieldPrompt(string $field): string
    {
        return match ($field) {
            'first_name'    => "Enter new first name:",
            'last_name'     => "Enter new last name:",
            'phone01'       => "Enter new primary phone:",
            'phone02'       => "Enter new secondary phone (or send - to clear):",
            'email'         => "Enter new email:",
            'fuel_type'     => "Enter fuel type (e.g. Petrol, Diesel, Gas, Electric):",
            'address_city'  => "Enter city (or send - to clear):",
            default         => "Enter new value for {$field}:",
        };
    }

    private function handleEditDriverFieldInput(
        OperatorBookingSession $session,
        string $chatId,
        ?string $text,
    ): array {
        $driverId = (int) ($session->getData('driver_id') ?? 0);
        $field    = (string) ($session->getData('field') ?? '');

        if (! $driverId || ! $field) {
            $session->reset();
            return ['text' => "⚠️ Session lost. Use /staff to start again."];
        }

        $driver = Driver::find($driverId);

        if (! $driver) {
            $session->reset();
            return ['text' => "⚠️ Driver not found."];
        }

        $value = trim($text ?? '');

        if ($value === '') {
            return ['text' => $this->editDriverFieldPrompt($field) . "\n\n(Send /cancel to abort.)"];
        }

        // "-" clears nullable fields
        if ($value === '-' && in_array($field, ['phone02', 'address_city'])) {
            $value = null;
        }

        try {
            $this->driverService->update($driver, [$field => $value], $this->actor($chatId));
        } catch (\RuntimeException $e) {
            return ['text' => "⚠️ " . $e->getMessage() . "\n\nSend a different value or /cancel to abort."];
        }
        $driver->refresh();

        $session->update(['state' => 'idle', 'data' => null]);

        return $this->staffDriverDetail($driverId);
    }

    // ── Guide list / detail / toggle / edit ──────────────────────────────────

    private function staffGuideList(string $filter = 'all', int $page = 1): array
    {
        $perPage   = 15;
        $canMutate = $this->operator?->can(BotOperator::PERM_MANAGE) ?? false;

        $query = Guide::orderBy('first_name');
        if ($filter === 'active') {
            $query->where('is_active', true);
        } elseif ($filter === 'inactive') {
            $query->where('is_active', false);
        }
        $all    = $query->get(['id', 'first_name', 'last_name', 'is_active']);
        $total  = $all->count();
        $pages  = (int) ceil($total / $perPage) ?: 1;
        $page   = max(1, min($page, $pages));
        $guides = $all->forPage($page, $perPage);

        $filterRow = [
            ['text' => ($filter === 'all'      ? '● All'      : '○ All'),      'callback_data' => 'staff:guides:all'],
            ['text' => ($filter === 'active'   ? '● Active'   : '○ Active'),   'callback_data' => 'staff:guides:active'],
            ['text' => ($filter === 'inactive' ? '● Inactive' : '○ Inactive'), 'callback_data' => 'staff:guides:inactive'],
        ];

        if ($guides->isEmpty()) {
            $buttons = [$filterRow];
            $buttons[] = [['text' => '🔍 Search', 'callback_data' => 'staff:guide:search']];
            if ($canMutate) {
                $buttons[] = [['text' => '➕ Add guide', 'callback_data' => 'staff:guide:add']];
            }
            $buttons[] = [['text' => '◀ Back', 'callback_data' => 'staff:menu']];
            return [
                'text'         => "🧭 <b>Guides</b>\n\nNo guides found.",
                'reply_markup' => ['inline_keyboard' => $buttons],
            ];
        }

        $buttons   = [$filterRow];
        $buttons[] = [['text' => '🔍 Search', 'callback_data' => 'staff:guide:search']];

        foreach ($guides as $g) {
            $buttons[] = [[
                'text'          => ($g->is_active ? '✅' : '🔴') . ' ' . $g->full_name,
                'callback_data' => "staff:guide:{$g->id}",
            ]];
        }

        if ($pages > 1) {
            $pagRow = [];
            if ($page > 1) {
                $pagRow[] = ['text' => '◀ Prev', 'callback_data' => "staff:guides:{$filter}:page:" . ($page - 1)];
            }
            $pagRow[] = ['text' => "{$page}/{$pages}", 'callback_data' => "staff:guides:{$filter}:page:{$page}"];
            if ($page < $pages) {
                $pagRow[] = ['text' => 'Next ▶', 'callback_data' => "staff:guides:{$filter}:page:" . ($page + 1)];
            }
            $buttons[] = $pagRow;
        }

        if ($canMutate) {
            $buttons[] = [['text' => '➕ Add guide', 'callback_data' => 'staff:guide:add']];
        }
        $buttons[] = [['text' => '◀ Back', 'callback_data' => 'staff:menu']];

        $filterLabel = $filter !== 'all' ? " · " . ucfirst($filter) . " only" : '';

        return [
            'text'         => "🧭 <b>Guides</b> ({$total} total{$filterLabel})\n\n✅ active · 🔴 inactive",
            'reply_markup' => ['inline_keyboard' => $buttons],
        ];
    }

    private function staffGuideDetail(int $guideId): array
    {
        $guide = Guide::find($guideId);

        if (! $guide) {
            return ['text' => "⚠️ Guide not found."];
        }

        $status    = $guide->is_active ? '✅ Active' : '🔴 Inactive';
        $toggleLbl = $guide->is_active ? '🔴 Deactivate' : '✅ Activate';
        $langs     = $guide->lang_spoken ? implode(', ', $guide->lang_spoken) : '—';
        $phone2    = $guide->phone02 ? "\n📱 Phone 2: {$guide->phone02}" : '';

        $text = "🧭 <b>{$guide->full_name}</b>\n\n"
            . "📱 Phone: {$guide->phone01}{$phone2}\n"
            . "📧 Email: {$guide->email}\n"
            . "🗣 Languages: {$langs}\n"
            . "Status: {$status}";

        $keyboard = [];
        if ($this->operator?->can(BotOperator::PERM_MANAGE)) {
            $keyboard[] = [['text' => '✏️ Edit',  'callback_data' => "staff:guide:{$guideId}:edit"]];
            $keyboard[] = [['text' => $toggleLbl,  'callback_data' => "staff:guide:{$guideId}:toggle"]];
        }
        $keyboard[] = [['text' => '◀ Back', 'callback_data' => 'staff:guides']];

        return [
            'text'         => $text,
            'reply_markup' => ['inline_keyboard' => $keyboard],
        ];
    }

    private function staffToggleGuide(int $guideId, string $actor): array
    {
        $guide = Guide::find($guideId);

        if (! $guide) {
            return ['text' => "⚠️ Guide not found."];
        }

        $this->guideService->setActive($guide, ! $guide->is_active, $actor);
        $guide->refresh();

        return $this->staffGuideDetail($guideId);
    }

    private function staffGuideEditMenu(int $guideId): array
    {
        $guide = Guide::find($guideId);

        if (! $guide) {
            return ['text' => "⚠️ Guide not found."];
        }

        return [
            'text'         => "✏️ <b>Edit {$guide->full_name}</b>\n\nWhich field?",
            'reply_markup' => [
                'inline_keyboard' => [
                    [['text' => 'First name', 'callback_data' => "staff:guide:{$guideId}:edit:first_name"]],
                    [['text' => 'Last name',  'callback_data' => "staff:guide:{$guideId}:edit:last_name"]],
                    [['text' => 'Phone 1',    'callback_data' => "staff:guide:{$guideId}:edit:phone01"]],
                    [['text' => 'Phone 2',    'callback_data' => "staff:guide:{$guideId}:edit:phone02"]],
                    [['text' => 'Email',      'callback_data' => "staff:guide:{$guideId}:edit:email"]],
                    [['text' => 'Languages',  'callback_data' => "staff:guide:{$guideId}:edit:lang_spoken"]],
                    [['text' => '◀ Back',     'callback_data' => "staff:guide:{$guideId}"]],
                ],
            ],
        ];
    }

    private function editGuideFieldPrompt(string $field): string
    {
        return match ($field) {
            'first_name'  => "Enter new first name:",
            'last_name'   => "Enter new last name:",
            'phone01'     => "Enter new primary phone:",
            'phone02'     => "Enter new secondary phone (or send - to clear):",
            'email'       => "Enter new email:",
            'lang_spoken' => "Enter languages spoken, comma-separated (e.g. EN, RU, UZ):",
            default       => "Enter new value for {$field}:",
        };
    }

    private function handleEditGuideFieldInput(
        OperatorBookingSession $session,
        string $chatId,
        ?string $text,
    ): array {
        $guideId = (int) ($session->getData('guide_id') ?? 0);
        $field   = (string) ($session->getData('field') ?? '');

        if (! $guideId || ! $field) {
            $session->reset();
            return ['text' => "⚠️ Session lost. Use /staff to start again."];
        }

        $guide = Guide::find($guideId);

        if (! $guide) {
            $session->reset();
            return ['text' => "⚠️ Guide not found."];
        }

        $value = trim($text ?? '');

        if ($value === '') {
            return ['text' => $this->editGuideFieldPrompt($field) . "\n\n(Send /cancel to abort.)"];
        }

        if ($value === '-' && $field === 'phone02') {
            $value = null;
        }

        try {
            $this->guideService->update($guide, [$field => $value], $this->actor($chatId));
        } catch (\RuntimeException $e) {
            return ['text' => "⚠️ " . $e->getMessage() . "\n\nSend a different value or /cancel to abort."];
        }
        $guide->refresh();

        $session->update(['state' => 'idle', 'data' => null]);

        return $this->staffGuideDetail($guideId);
    }

    // ── Search handlers ───────────────────────────────────────────────────────

    private function handleSearchDriverInput(
        OperatorBookingSession $session,
        string $chatId,
        ?string $text,
    ): array {
        $query = trim($text ?? '');

        if ($query === '') {
            return ['text' => "🔍 Type a name or phone number to search drivers:"];
        }

        $drivers = $this->driverService->search($query);

        $session->update(['state' => 'idle', 'data' => null]);

        if ($drivers->isEmpty()) {
            return [
                'text'         => "🔍 No drivers found for &ldquo;<b>{$query}</b>&rdquo;.",
                'reply_markup' => ['inline_keyboard' => [
                    [['text' => '◀ Back to list', 'callback_data' => 'staff:drivers']],
                ]],
            ];
        }

        $buttons = $drivers->map(fn ($d) => [[
            'text'          => ($d->is_active ? '✅' : '🔴') . ' ' . $d->full_name,
            'callback_data' => "staff:driver:{$d->id}",
        ]])->all();
        $buttons[] = [['text' => '◀ Back to list', 'callback_data' => 'staff:drivers']];

        return [
            'text'         => "🔍 <b>Driver search: &ldquo;{$query}&rdquo;</b>\n\n{$drivers->count()} result(s)",
            'reply_markup' => ['inline_keyboard' => $buttons],
        ];
    }

    private function handleSearchGuideInput(
        OperatorBookingSession $session,
        string $chatId,
        ?string $text,
    ): array {
        $query = trim($text ?? '');

        if ($query === '') {
            return ['text' => "🔍 Type a name or phone number to search guides:"];
        }

        $guides = $this->guideService->search($query);

        $session->update(['state' => 'idle', 'data' => null]);

        if ($guides->isEmpty()) {
            return [
                'text'         => "🔍 No guides found for &ldquo;<b>{$query}</b>&rdquo;.",
                'reply_markup' => ['inline_keyboard' => [
                    [['text' => '◀ Back to list', 'callback_data' => 'staff:guides']],
                ]],
            ];
        }

        $buttons = $guides->map(fn ($g) => [[
            'text'          => ($g->is_active ? '✅' : '🔴') . ' ' . $g->full_name,
            'callback_data' => "staff:guide:{$g->id}",
        ]])->all();
        $buttons[] = [['text' => '◀ Back to list', 'callback_data' => 'staff:guides']];

        return [
            'text'         => "🔍 <b>Guide search: &ldquo;{$query}&rdquo;</b>\n\n{$guides->count()} result(s)",
            'reply_markup' => ['inline_keyboard' => $buttons],
        ];
    }

    // ── Add driver multi-step flow ────────────────────────────────────────────

    /** Driver add steps: first_name → last_name → phone01 → email → fuel_type */
    private function handleAddDriverInput(
        OperatorBookingSession $session,
        string $chatId,
        ?string $text,
    ): array {
        $step      = (string) ($session->getData('step') ?? 'first_name');
        $collected = (array)  ($session->getData('collected') ?? []);
        $value     = trim($text ?? '');

        if ($value === '') {
            return ['text' => $this->addDriverStepPrompt($step)];
        }

        // Validate minimum
        if ($step === 'phone01' && strlen($value) < 7) {
            return ['text' => "⚠️ Phone looks too short. Try again:"];
        }
        if ($step === 'email' && ! str_contains($value, '@')) {
            return ['text' => "⚠️ That doesn't look like a valid email. Try again:"];
        }

        $collected[$step] = $value;

        $nextStep = match ($step) {
            'first_name' => 'last_name',
            'last_name'  => 'phone01',
            'phone01'    => 'email',
            'email'      => 'fuel_type',
            'fuel_type'  => 'done',
            default      => 'done',
        };

        if ($nextStep === 'done') {
            // Create driver
            try {
                $driver = $this->driverService->create($collected, $this->actor($chatId));
            } catch (\Throwable $e) {
                Log::error('OperatorBookingFlow: add driver failed', ['error' => $e->getMessage()]);
                $session->update(['state' => 'idle', 'data' => null]);
                return ['text' => "❌ Failed to create driver: {$e->getMessage()}"];
            }

            $session->update(['state' => 'idle', 'data' => null]);
            return $this->staffDriverDetail($driver->id);
        }

        $stepNum  = array_search($nextStep, ['first_name', 'last_name', 'phone01', 'email', 'fuel_type']) + 1;
        $session->update(['state' => 'add_driver', 'data' => ['step' => $nextStep, 'collected' => $collected]]);

        return ['text' => "🚗 <b>Add Driver</b> — step {$stepNum}/5\n\n" . $this->addDriverStepPrompt($nextStep)];
    }

    private function addDriverStepPrompt(string $step): string
    {
        return match ($step) {
            'first_name' => "Enter first name:",
            'last_name'  => "Enter last name:",
            'phone01'    => "Enter primary phone (e.g. +998901234567):",
            'email'      => "Enter email:",
            'fuel_type'  => "Enter fuel type (Petrol / Diesel / Gas / Electric):",
            default      => "Enter value:",
        };
    }

    // ── Add guide multi-step flow ─────────────────────────────────────────────

    /** Guide add steps: first_name → last_name → phone01 → email → lang_spoken */
    private function handleAddGuideInput(
        OperatorBookingSession $session,
        string $chatId,
        ?string $text,
    ): array {
        $step      = (string) ($session->getData('step') ?? 'first_name');
        $collected = (array)  ($session->getData('collected') ?? []);
        $value     = trim($text ?? '');

        if ($value === '') {
            return ['text' => $this->addGuideStepPrompt($step)];
        }

        if ($step === 'phone01' && strlen($value) < 7) {
            return ['text' => "⚠️ Phone looks too short. Try again:"];
        }
        if ($step === 'email' && ! str_contains($value, '@')) {
            return ['text' => "⚠️ That doesn't look like a valid email. Try again:"];
        }

        $collected[$step] = $value;

        $nextStep = match ($step) {
            'first_name'  => 'last_name',
            'last_name'   => 'phone01',
            'phone01'     => 'email',
            'email'       => 'lang_spoken',
            'lang_spoken' => 'done',
            default       => 'done',
        };

        if ($nextStep === 'done') {
            try {
                $guide = $this->guideService->create($collected, $this->actor($chatId));
            } catch (\Throwable $e) {
                Log::error('OperatorBookingFlow: add guide failed', ['error' => $e->getMessage()]);
                $session->update(['state' => 'idle', 'data' => null]);
                return ['text' => "❌ Failed to create guide: {$e->getMessage()}"];
            }

            $session->update(['state' => 'idle', 'data' => null]);
            return $this->staffGuideDetail($guide->id);
        }

        $stepNum = array_search($nextStep, ['first_name', 'last_name', 'phone01', 'email', 'lang_spoken']) + 1;
        $session->update(['state' => 'add_guide', 'data' => ['step' => $nextStep, 'collected' => $collected]]);

        return ['text' => "🧭 <b>Add Guide</b> — step {$stepNum}/5\n\n" . $this->addGuideStepPrompt($nextStep)];
    }

    private function addGuideStepPrompt(string $step): string
    {
        return match ($step) {
            'first_name'  => "Enter first name:",
            'last_name'   => "Enter last name:",
            'phone01'     => "Enter primary phone (e.g. +998901234567):",
            'email'       => "Enter email:",
            'lang_spoken' => "Enter languages spoken, comma-separated (e.g. EN, RU, UZ):",
            default       => "Enter value:",
        };
    }
}
