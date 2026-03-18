<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessBeds24WebhookJob;
use App\Jobs\SendTelegramNotificationJob;
use App\Models\Beds24Booking;
use App\Models\Beds24BookingChange;
use App\Models\Beds24WebhookEvent;
use App\Models\CashTransaction;
use App\Models\IncomingWebhook;
use App\Models\TelegramPosSession;
use App\Enums\TransactionType;
use App\Enums\TransactionCategory;
use App\Models\CashierShift;
use App\Services\OwnerAlertService;
use App\Services\Beds24BookingService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class Beds24WebhookController extends Controller
{
    public function __construct(
        protected OwnerAlertService $alertService,
        protected Beds24BookingService $beds24Service
    )
    {
    }

    /**
     * Handle incoming Beds24 webhook.
     *
     * Beds24 sends a POST with booking data whenever a booking is
     * created, modified, or cancelled. We process it, persist the data,
     * detect changes and alert the owner via Telegram.
     *
     * The response MUST be 200 quickly — heavy work is done synchronously
     * but wrapped in try/catch so we never return 5xx to Beds24.
     */
    public function handle(Request $request): Response
    {
        $raw = $request->all();

        Log::info('Beds24 Webhook received', ['payload_keys' => array_keys($raw)]);

        // Idempotency: hash the payload to prevent duplicate processing
        $eventHash = hash('sha256', json_encode($raw));

        // Check if already processed
        $existing = Beds24WebhookEvent::where('event_hash', $eventHash)->first();
        if ($existing && $existing->status === 'processed') {
            Log::info('Beds24 Webhook: Duplicate event skipped', ['event_id' => $existing->id]);
            return response('OK', 200);
        }

        // Extract booking ID for indexing
        $bookingId = (string) ($raw['bookId'] ?? $raw['bookid'] ?? $raw['id'] ?? '');

        // --- Durable inbox: record raw payload before any processing ---
        // This ensures we never lose a webhook even if the job fails or the
        // worker is down. The incoming_webhook_id is passed to the job so it
        // can update the record's status throughout its lifecycle.
        $incomingWebhook = IncomingWebhook::create([
            'source'   => 'beds24',
            'event_id' => $bookingId ?: $eventHash,
            'payload'  => $raw,
            'status'   => IncomingWebhook::STATUS_PENDING,
        ]);

        // Store webhook event (never lose data)
        $event = Beds24WebhookEvent::updateOrCreate(
            ['event_hash' => $eventHash],
            [
                'booking_id' => $bookingId ?: null,
                'payload'    => $raw,
                'status'     => 'pending',
            ]
        );

        // Dispatch async processing, passing the durable inbox record ID
        ProcessBeds24WebhookJob::dispatch($event->id, $incomingWebhook->id);

        return response('OK', 200);
    }

    /**
     * Process a stored webhook payload. Called by ProcessBeds24WebhookJob.
     */
    public function processWebhookPayload(array $raw): void
    {
        Log::info('Beds24 Webhook processing', ['payload' => $raw]);

        $data = $this->parsePayload($raw);

        if (!$data) {
            Log::warning('Beds24 Webhook: Could not parse payload', ['raw' => $raw]);
            return;
        }

        $bookingId = (string) ($data['booking_id'] ?? '');
        if (!$bookingId) {
            Log::warning('Beds24 Webhook: No booking ID in payload');
            return;
        }

        $existing = Beds24Booking::where('beds24_booking_id', $bookingId)->first();

        // If invoice_balance was not in webhook payload, calculate from invoiceItems
        if (($data['invoice_balance'] ?? 0) == -999) {
            $calculated = $this->calculateBalanceFromItems($raw);
            $data['invoice_balance'] = $calculated ?? (float) ($data['total_amount'] ?? 0);
            Log::info('Beds24 Webhook: Calculated balance from invoiceItems', [
                'booking_id' => $bookingId,
                'calculated_balance' => $data['invoice_balance'],
            ]);
        }

        if ($existing) {
            $this->handleUpdate($existing, $data, $raw);
        } else {
            $this->handleNew($bookingId, $data, $raw);
        }
    }

    // -------------------------------------------------------------------------
    // New booking
    // -------------------------------------------------------------------------

    private function handleNew(string $bookingId, array $data, array $raw): void
    {
        $booking = Beds24Booking::create([
            'beds24_booking_id' => $bookingId,
            'property_id'       => $data['property_id'] ?? '',
            'room_id'           => $data['room_id'] ?? null,
            'room_name'         => $data['room_name'] ?? null,
            'guest_name'        => $this->buildGuestName($data),
            'guest_email'       => $data['guest_email'] ?? null,
            'guest_phone'       => $data['guest_phone'] ?? null,
            'channel'           => $data['channel'] ?? null,
            'arrival_date'      => $data['arrival_date'] ?? null,
            'departure_date'    => $data['departure_date'] ?? null,
            'num_adults'        => (int) ($data['num_adults'] ?? 1),
            'num_children'      => (int) ($data['num_children'] ?? 0),
            'total_amount'      => (float) ($data['total_amount'] ?? 0),
            'currency'          => $data['currency'] ?? 'USD',
            'payment_status'    => $this->derivePaymentStatus($data),
            'booking_status'    => $this->deriveBookingStatus($data),
            'original_status'   => $data['status'] ?? null,
            'invoice_balance'   => (float) ($data['invoice_balance'] ?? 0),
            'beds24_raw_data'   => $raw,
        ]);

        $change = Beds24BookingChange::create([
            'beds24_booking_id' => $bookingId,
            'change_type'       => 'created',
            'old_data'          => null,
            'new_data'          => $data,
            'detected_at'       => now(),
        ]);

        Log::info('Beds24 Webhook: New booking saved', ['booking_id' => $bookingId]);

        // Fetch guest name from API if webhook didn't include it
        $this->enrichGuestInfo($booking);

        // Detect if this is a genuinely NEW booking or a first-time-seen old booking.
        // Beds24 sends webhooks for old bookings when they're modified (payment added,
        // cancelled, etc.) but our system never saw the original creation webhook.
        // If bookingTime is older than 1 hour, treat as "sync" — don't send "new booking" alert.
        $bookingTime = $raw['booking']['bookingTime'] ?? null;
        $isGenuinelyNew = true;
        if ($bookingTime) {
            try {
                $createdAt = Carbon::parse($bookingTime);
                $isGenuinelyNew = $createdAt->diffInMinutes(now()) < 60;
            } catch (\Throwable $e) {
                // If we can't parse, assume it's new
            }
        }

        if (!$isGenuinelyNew) {
            Log::info('Beds24 Webhook: Old booking first seen (sync), skipping new booking alert', [
                'booking_id' => $bookingId,
                'booking_time' => $bookingTime,
            ]);

            // Still process payment if it's paid
            $balance = (float) ($data["invoice_balance"] ?? 0);
            $total   = (float) ($data["total_amount"] ?? 0);
            if ($total > 0 && $balance <= 0) {
                $paymentLines = $this->extractNewPaymentLines($booking->beds24_booking_id, $raw);
                if (!empty($paymentLines)) {
                    foreach ($paymentLines as $line) {
                        $method = $line['status'] ?? '';
                        $desc = $line['description'] ?? '';
                        $amount = (float) ($line['amount'] ?? 0);
                        $this->createPaymentTransaction($booking, $amount, $desc . ($method ? " ({$method})" : ''), $method, $desc, $line['_ref'] ?? null);
                    }
                    $this->alertService->alertPaymentWithDetails($booking, $change, $paymentLines, $total, $balance);
                } else {
                    $paymentAmount = $total - $balance;
                    $this->alertService->alertPaymentReceived($booking, $change, $paymentAmount, $total, $balance);
                }
            }
            // If cancelled old booking — no alert needed (it's historical)
            return;
        }

        if ($booking->isCancelled()) {
            $this->alertService->alertCancellation($booking, $change);
            return;
        }

        // Check if booking arrives already paid (e.g. prepaid via OTA)
        $balance = (float) ($data["invoice_balance"] ?? 0);
        $total   = (float) ($data["total_amount"] ?? 0);
        $isPrePaid = ($total > 0 && $balance <= 0);

        if ($isPrePaid) {
            // Send ONE combined message (new booking + payment) instead of two separate alerts
            $paymentLines = $this->extractNewPaymentLines($booking->beds24_booking_id, $raw);
            if (!empty($paymentLines)) {
                foreach ($paymentLines as $line) {
                    $method = $line['status'] ?? '';
                    $desc = $line['description'] ?? '';
                    $amount = (float) ($line['amount'] ?? 0);
                    $this->createPaymentTransaction($booking, $amount, $desc . ($method ? " ({$method})" : ''), $method, $desc, $line['_ref'] ?? null);
                }
            }
            $this->alertService->alertNewBookingWithPayment($booking, $change, $paymentLines);
        } else {
            // Not yet paid — just send new booking alert
            $this->alertService->alertNewBooking($booking, $change);
        }
    }

    // -------------------------------------------------------------------------
    // Updated booking
    // -------------------------------------------------------------------------

    private function handleUpdate(Beds24Booking $booking, array $data, array $raw): void
    {
        $oldData = $booking->toArray();
        $newStatus = $this->deriveBookingStatus($data);
        $newAmount = (float) ($data['total_amount'] ?? 0);

        // Detect new charge items (e.g. taxi, minibar, extra services)
        $newCharges = $this->detectNewCharges($booking, $raw);

        // Detect checkout (CHECKOUT info code added)
        $isNewCheckout = $this->detectCheckout($booking, $raw);

        $changedFields = [];

        // Detect significant field changes
        $watchedFields = [
            'arrival_date'   => ['label' => 'Дата заезда',  'new' => $data['arrival_date'] ?? null],
            'departure_date' => ['label' => 'Дата выезда',  'new' => $data['departure_date'] ?? null],
            'room_name'      => ['label' => 'Комната',      'new' => $data['room_name'] ?? null],
            'num_adults'     => ['label' => 'Взрослых',     'new' => $data['num_adults'] ?? null],
            'num_children'   => ['label' => 'Детей',        'new' => $data['num_children'] ?? null],
        ];

        foreach ($watchedFields as $field => $info) {
            $oldVal = (string) ($booking->$field ?? '');
            // Normalise dates for comparison
            if (in_array($field, ['arrival_date', 'departure_date']) && $booking->$field) {
                $oldVal = $booking->$field->toDateString();
            }
            $newVal = (string) ($info['new'] ?? '');
            if ($oldVal !== $newVal && $newVal !== '') {
                $changedFields[$info['label']] = ['old' => $oldVal, 'new' => $newVal];
            }
        }

        // Detect amount change
        $amountReduced = ($newAmount > 0 && $newAmount < (float) $booking->total_amount);
        $amountChanged = ($newAmount > 0 && abs($newAmount - (float) $booking->total_amount) > 0.01);

        // Update the booking record
        $updatePayload = [
            'room_id'         => $data['room_id'] ?? $booking->room_id,
            'room_name'       => $data['room_name'] ?? $booking->room_name,
            'guest_name'      => $this->buildGuestName($data) ?: $booking->guest_name,
            'guest_email'     => $data['guest_email'] ?? $booking->guest_email,
            'guest_phone'     => $data['guest_phone'] ?? $booking->guest_phone,
            'channel'         => $data['channel'] ?? $booking->channel,
            'arrival_date'    => $data['arrival_date'] ?? $booking->arrival_date,
            'departure_date'  => $data['departure_date'] ?? $booking->departure_date,
            'num_adults'      => (int) ($data['num_adults'] ?? $booking->num_adults),
            'num_children'    => (int) ($data['num_children'] ?? $booking->num_children),
            'booking_status'  => $newStatus,
            'invoice_balance' => (float) ($data['invoice_balance'] ?? $booking->invoice_balance),
            'beds24_raw_data' => $raw,
        ];

        if ($amountChanged) {
            $updatePayload['total_amount'] = $newAmount;
        }
        
        // Always update payment_status when balance changes
        $newPaymentStatus = $this->derivePaymentStatus($data);
        if ($newPaymentStatus !== ($booking->payment_status ?? '')) {
            $updatePayload['payment_status'] = $newPaymentStatus;
        }

        // Set cancelled_at timestamp when booking goes to cancelled
        if ($newStatus === 'cancelled' && !$booking->isCancelled()) {
            $updatePayload['cancelled_at'] = now();
        }

        $booking->update($updatePayload);
        $booking->refresh();

        // Determine the primary change type for this update
        $changeType = $this->deriveChangeType($booking, $oldData, $newStatus, $amountChanged, $changedFields, $newCharges, $isNewCheckout);

        if ($changeType === null) {
            // Nothing meaningful changed — still log, but don't alert
            Log::info('Beds24 Webhook: No meaningful change detected', ['booking_id' => $booking->beds24_booking_id]);
            return;
        }

        $change = Beds24BookingChange::create([
            'beds24_booking_id' => $booking->beds24_booking_id,
            'change_type'       => $changeType,
            'old_data'          => $oldData,
            'new_data'          => $data,
            'detected_at'       => now(),
        ]);

        // Fetch guest name from API if still empty
        $this->enrichGuestInfo($booking);

        Log::info('Beds24 Webhook: Booking updated', [
            'booking_id'  => $booking->beds24_booking_id,
            'change_type' => $changeType,
        ]);

        // Send appropriate alert
        $this->dispatchAlert($booking, $change, $changeType, $oldData, $newAmount, $changedFields, $raw, $newCharges);
    }

    // -------------------------------------------------------------------------
    // Alert dispatching
    // -------------------------------------------------------------------------

    private function dispatchAlert(
        Beds24Booking $booking,
        Beds24BookingChange $change,
        string $changeType,
        array $oldData,
        float $newAmount,
        array $changedFields,
        array $raw = [],
        array $newCharges = []
    ): void {
        $today = now('Asia/Tashkent')->toDateString();

        switch ($changeType) {
            case 'cancelled':
                // Check if cancellation is after check-in
                $arrivalDate = $booking->arrival_date ? $booking->arrival_date->toDateString() : null;
                if ($arrivalDate && $arrivalDate <= $today) {
                    $this->alertService->alertCancelledAfterCheckin($booking, $change);
                } else {
                    $this->alertService->alertCancellation($booking, $change);
                }
                break;

            case 'amount_changed':
                $oldAmount = (float) ($oldData['total_amount'] ?? 0);
                $this->alertService->alertAmountReduced($booking, $change, $oldAmount, $newAmount);
                break;

            case 'modified':
                if (!empty($changedFields)) {
                    $this->alertService->alertModification($booking, $change, $changedFields);
                }
                break;

            case 'payment_updated':
                $this->handlePaymentSync($booking, $change, $oldData, $raw);
                break;

            case 'charge_added':
                $this->alertService->alertNewCharge($booking, $change, $newCharges, $raw);
                break;

            case 'checked_out':
                $this->notifyCleanersCheckout($booking);
                break;
        }
    }


    // -------------------------------------------------------------------------
    // Payment auto-sync — Beds24 payment → CashTransaction + Owner alert
    // -------------------------------------------------------------------------

    private function handlePaymentSync(Beds24Booking $booking, Beds24BookingChange $change, array $oldData, array $raw = []): void
    {
        $oldBalance = (float) ($oldData['invoice_balance'] ?? 0);
        $newBalance = (float) ($booking->invoice_balance ?? 0);

        // Only create transaction if balance decreased (payment received)
        if ($newBalance >= $oldBalance) {
            Log::info('Beds24 Payment: No balance decrease detected', [
                'booking_id' => $booking->beds24_booking_id,
                'old_balance' => $oldBalance,
                'new_balance' => $newBalance,
            ]);
            return;
        }

        // Fetch payment line details from Beds24 API
        $paymentLines = $this->extractNewPaymentLines($booking->beds24_booking_id, $raw);

        if (!empty($paymentLines)) {
            // Create a CashTransaction for each new payment line
            foreach ($paymentLines as $line) {
                $method = $line['status'] ?? '';
                $desc = $line['description'] ?? '';
                $amount = (float) ($line['amount'] ?? 0);
                $note = $desc . ($method ? " ({$method})" : '');
                $this->createPaymentTransaction($booking, $amount, $note, $method, $desc, $line['_ref'] ?? null);
            }
            $this->alertService->alertPaymentWithDetails($booking, $change, $paymentLines, $oldBalance, $newBalance);
        } else {
            // Fallback: no line details available
            $paymentAmount = $oldBalance - $newBalance;
            $this->createPaymentTransaction($booking, $paymentAmount, "Balance: {$oldBalance} -> {$newBalance}");
            $this->alertService->alertPaymentReceived($booking, $change, $paymentAmount, $oldBalance, $newBalance);
        }
    }

    /**
     * Extract new payment lines from webhook payload (no API call needed)
     */
    private function extractNewPaymentLines(string $bookingId, array $raw): array
    {
        // Beds24 V2 webhooks include invoiceItems in the payload
        $items = $raw['booking']['invoiceItems'] ?? $raw['invoiceItems'] ?? [];

        if (empty($items)) {
            Log::info('Beds24 Payment: No invoiceItems in webhook payload', ['booking_id' => $bookingId]);
            return [];
        }

        // Filter only payment lines (type=payment)
        $payments = array_values(array_filter($items, fn($i) => ($i['type'] ?? '') === 'payment'));

        // Get IDs of already-recorded payments to avoid duplicates
        $existingRefs = CashTransaction::where('beds24_booking_id', $bookingId)
            ->whereNotNull('reference')
            ->pluck('reference')
            ->toArray();

        // Return only new payment lines
        $newPayments = [];
        foreach ($payments as $p) {
            $ref = "Beds24 #{$bookingId} item#{$p['id']}";
            if (!in_array($ref, $existingRefs)) {
                $p['_ref'] = $ref;
                $newPayments[] = $p;
            }
        }

        Log::info('Beds24 Payment: Extracted payment lines from webhook', [
            'booking_id' => $bookingId,
            'total_items' => count($items),
            'payment_items' => count($payments),
            'new_items' => count($newPayments),
        ]);

        return $newPayments;
    }


    /**
     * Create a CashTransaction from a Beds24 payment
     */
    private function createPaymentTransaction(Beds24Booking $booking, float $amount, string $notes, string $method = '', string $description = '', ?string $reference = null): void
    {
        try {
            // Only create CashTransaction for cash payments (naqd)
            // Card, transfer, OTA payments don't hit the physical cash register
            $cashMethods = ['naqd', 'cash', 'наличные'];
            if (!in_array(mb_strtolower(trim($method)), $cashMethods)) {
                Log::info('Beds24 Payment: Skipped non-cash payment (no CashTransaction)', [
                    'booking_id' => $booking->beds24_booking_id,
                    'amount' => $amount,
                    'method' => $method,
                ]);
                return;
            }

            $ref = $reference;
            // Deduplication check
            if ($ref) {
                // Use reference-based dedup (most reliable)
                if (CashTransaction::where('reference', $ref)->exists()) {
                    Log::info('Beds24 Payment: Duplicate transaction skipped (by ref)', [
                        'booking_id' => $booking->beds24_booking_id,
                        'reference' => $ref,
                    ]);
                    return;
                }
            } elseif ($booking->beds24_booking_id) {
                // Fallback dedup by booking + notes + amount
                if (CashTransaction::where('beds24_booking_id', $booking->beds24_booking_id)
                    ->where('notes', $notes)
                    ->where('amount', $amount)
                    ->exists()) {
                    Log::info('Beds24 Payment: Duplicate transaction skipped (by content)', [
                        'booking_id' => $booking->beds24_booking_id,
                        'amount' => $amount,
                    ]);
                    return;
                }
            }

            // Link to active cashier shift (latest open shift)
            $activeShift = CashierShift::where('status', 'open')
                ->latest('opened_at')
                ->first();

            CashTransaction::create([
                'cashier_shift_id' => $activeShift?->id,
                'type' => TransactionType::IN,
                'amount' => $amount,
                'currency' => 'USD',
                'category' => TransactionCategory::SALE,
                'notes' => $notes,
                'beds24_booking_id' => $booking->beds24_booking_id,
                'payment_method' => $method,
                'guest_name' => $booking->guest_name ?? '',
                'room_number' => $booking->room_name ?? '',
                'reference' => $ref,
                'occurred_at' => now(),
            ]);

            Log::info('Beds24 Payment: CashTransaction created', [
                'booking_id' => $booking->beds24_booking_id,
                'amount' => $amount,
                'method' => $method,
            ]);
        } catch (\Throwable $e) {
            Log::error('Beds24 Payment: Failed to create CashTransaction', [
                'booking_id' => $booking->beds24_booking_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Checkout detection & cleaner notification
    // -------------------------------------------------------------------------

    /**
     * Detect if CHECKOUT info code was just added (not present in old raw data).
     */
    private function detectCheckout(Beds24Booking $booking, array $raw): bool
    {
        $newInfoItems = $raw['infoItems'] ?? [];
        $hasCheckout = false;
        foreach ($newInfoItems as $item) {
            if (strtoupper($item['code'] ?? '') === 'CHECKOUT') {
                $hasCheckout = true;
                break;
            }
        }

        if (!$hasCheckout) {
            return false;
        }

        // Check if old raw data already had CHECKOUT
        $oldRaw = $booking->beds24_raw_data ?? [];
        $oldInfoItems = $oldRaw['infoItems'] ?? [];
        foreach ($oldInfoItems as $item) {
            if (strtoupper($item['code'] ?? '') === 'CHECKOUT') {
                return false; // Already had checkout — not new
            }
        }

        Log::info('Beds24 Webhook: Checkout detected', [
            'booking_id' => $booking->beds24_booking_id,
            'room_name'  => $booking->room_name,
            'guest_name' => $booking->guest_name,
        ]);

        return true;
    }

    /**
     * Send TG notification to all authenticated housekeeping bot users about checkout.
     */
    private function notifyCleanersCheckout(Beds24Booking $booking): void
    {
        $botToken = config('services.housekeeping_bot.token', '');
        if (!$botToken) {
            Log::warning('Beds24 Checkout: No housekeeping bot token configured');
            return;
        }

        // Resolve room number: try room_name, then look up unit name from Beds24
        $roomName = $booking->room_name;
        if (empty($roomName)) {
            try {
                $propertyId = (int) $booking->property_id;
                $rooms = $this->beds24Service->getRoomStatuses($propertyId);
                $raw = $booking->beds24_raw_data ?? [];
                $roomId = (int) ($raw['booking']['roomId'] ?? 0);
                $unitId = (int) ($raw['booking']['unitId'] ?? 0);
                foreach ($rooms as $room) {
                    if ($room['room_type_id'] === $roomId && $room['unit_id'] === $unitId) {
                        $roomName = $room['room_number'];
                        break;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Beds24 Checkout: Failed to resolve room name', ['error' => $e->getMessage()]);
            }
        }
        $roomName = $roomName ?: '?';

        $guestName = $booking->guest_name ?? '';
        $propertyName = $booking->getPropertyName();

        $text = "🚪 <b>Checkout!</b>\n\n"
            . "📍 <b>{$roomName}</b>-xona bo'shadi\n"
            . ($guestName ? "👤 {$guestName}\n" : '')
            . "🏨 {$propertyName}\n\n"
            . "🧹 Tozalashni boshlash mumkin!";

        // Send to all authenticated housekeeping bot sessions (queued)
        // Exclude kitchen bot sessions (negative chat_id) and other non-HK states
        $sessions = TelegramPosSession::whereNotNull('user_id')
            ->where('chat_id', '>', 0)
            ->where('state', 'LIKE', 'hk_%')
            ->get();

        foreach ($sessions as $session) {
            SendTelegramNotificationJob::dispatch('housekeeping', 'sendMessage', [
                'chat_id'    => $session->chat_id,
                'text'       => $text,
                'parse_mode' => 'HTML',
            ]);
        }

        // Also send to management group
        $mgmtGroupId = (int) config('services.housekeeping_bot.mgmt_group_id', 0);
        if ($mgmtGroupId) {
            SendTelegramNotificationJob::dispatch('housekeeping', 'sendMessage', [
                'chat_id'    => $mgmtGroupId,
                'text'       => $text,
                'parse_mode' => 'HTML',
            ]);
        }

        Log::info('Beds24 Checkout: Cleaner notifications queued', [
            'booking_id' => $booking->beds24_booking_id,
            'room_name'  => $roomName,
            'queued_for' => $sessions->count(),
            'mgmt_group' => (bool) $mgmtGroupId,
        ]);
    }

    // -------------------------------------------------------------------------
    // Payload parsing — handles Beds24 V1 and V2 formats
    // -------------------------------------------------------------------------

    /**
     * Normalise the incoming Beds24 webhook payload into a consistent array.
     *
     * Beds24 V2 JSON format (used by modern webhooks):
     *   { "bookId": 123, "propId": 41097, ... }
     *
     * Beds24 V1 flat format (legacy / custom headers):
     *   { "bookid": "123", "propid": "41097", ... }
     */
    private function parsePayload(array $raw): ?array
    {
        // V2 JSON — top-level keys are camelCase
        if (isset($raw['bookId']) || isset($raw['propId']) || isset($raw['propertyId'])) {
            return $this->parseV2($raw);
        }

        // V1 flat format — keys are lowercase
        if (isset($raw['bookid']) || isset($raw['propid'])) {
            return $this->parseV1($raw);
        }

        // Sometimes wrapped in a 'booking' key
        if (isset($raw['booking']) && is_array($raw['booking'])) {
            return $this->parsePayload($raw['booking']);
        }

        // Last resort — try to detect any booking ID key
        foreach (['id', 'booking_id', 'bookingId', 'bookId', 'bookid'] as $key) {
            if (!empty($raw[$key])) {
                // Generic extraction
                return [
                    'booking_id'   => (string) $raw[$key],
                    'property_id'  => (string) ($raw['propertyId'] ?? $raw['propId'] ?? $raw['propid'] ?? $raw['property_id'] ?? ''),
                    'status'       => $raw['status'] ?? 'confirmed',
                    'total_amount' => $raw['totalAmount'] ?? $raw['total'] ?? $raw['price'] ?? 0,
                    'currency'     => $raw['currency'] ?? 'USD',
                ];
            }
        }

        return null;
    }

    private function parseV2(array $raw): array
    {
        $guestFirst = $raw['guestFirstName'] ?? $raw['firstName'] ?? '';
        $guestLast  = $raw['guestLastName']  ?? $raw['lastName']  ?? '';
        $fullName   = $raw['guestFullName'] ?? $raw['fullname'] ?? null;
        if ($fullName && empty($guestFirst) && empty($guestLast)) {
            $parts = explode(' ', $fullName, 2);
            $guestFirst = $parts[0] ?? '';
            $guestLast  = $parts[1] ?? '';
        }

        return [
            'booking_id'     => (string) ($raw['bookId'] ?? $raw['id'] ?? ''),
            'property_id'    => (string) ($raw['propId'] ?? $raw['propertyId'] ?? ''),
            'room_id'        => (string) ($raw['roomId'] ?? ''),
            'room_name'      => $raw['roomName'] ?? $raw['unitName'] ?? $raw['room'] ?? null,
            'first_name'     => $guestFirst,
            'last_name'      => $guestLast,
            'guest_email'    => $raw['email'] ?? $raw['guestEmail'] ?? null,
            'guest_phone'    => $raw['mobile'] ?? $raw['phone'] ?? $raw['guestPhone'] ?? null,
            'channel'        => $raw['referer'] ?? $raw['channel'] ?? $raw['source'] ?? null,
            'arrival_date'   => $this->parseDate($raw['arrival'] ?? $raw['checkIn'] ?? null),
            'departure_date' => $this->parseDate($raw['departure'] ?? $raw['checkOut'] ?? null),
            'num_adults'     => (int) ($raw['numAdult'] ?? $raw['adults'] ?? 1),
            'num_children'   => (int) ($raw['numChild'] ?? $raw['children'] ?? 0),
            'total_amount'   => (float) ($raw['price'] ?? $raw['totalAmount'] ?? $raw['invoiceTotal'] ?? 0),
            'currency'       => $raw['currency'] ?? 'USD',
            'status'         => $raw['status'] ?? 'confirmed',
            'invoice_balance'=> (float) ($raw['invoiceBalance'] ?? $raw['balance'] ?? -999),
        ];
    }

    private function parseV1(array $raw): array
    {
        $guestFirst = $raw['firstname'] ?? $raw['guestfirstname'] ?? '';
        $guestLast  = $raw['lastname']  ?? $raw['guestlastname']  ?? '';

        return [
            'booking_id'     => (string) ($raw['bookid'] ?? ''),
            'property_id'    => (string) ($raw['propid'] ?? $raw['propertyid'] ?? ''),
            'room_id'        => (string) ($raw['roomid'] ?? ''),
            'room_name'      => $raw['roomname'] ?? $raw['room'] ?? null,
            'first_name'     => $guestFirst,
            'last_name'      => $guestLast,
            'guest_email'    => $raw['email'] ?? null,
            'guest_phone'    => $raw['mobile'] ?? $raw['phone'] ?? null,
            'channel'        => $raw['referer'] ?? $raw['channel'] ?? null,
            'arrival_date'   => $this->parseDate($raw['arrival'] ?? $raw['checkin'] ?? null),
            'departure_date' => $this->parseDate($raw['departure'] ?? $raw['checkout'] ?? null),
            'num_adults'     => (int) ($raw['numadult'] ?? $raw['adults'] ?? 1),
            'num_children'   => (int) ($raw['numchild'] ?? $raw['children'] ?? 0),
            'total_amount'   => (float) ($raw['price'] ?? $raw['total'] ?? 0),
            'currency'       => $raw['currency'] ?? 'USD',
            'status'         => $raw['status'] ?? 'confirmed',
            'invoice_balance'=> (float) ($raw['balance'] ?? $raw['invoicebalance'] ?? 0),
        ];
    }

    // -------------------------------------------------------------------------
    // Derivation helpers
    // -------------------------------------------------------------------------

    /**
     * If guest name is empty after parsing webhook, fetch from Beds24 API.
     */
    private function enrichGuestInfo(\App\Models\Beds24Booking $booking): void
    {
        if (!empty($booking->guest_name)) {
            return;
        }

        try {
            $info = $this->beds24Service->fetchGuestInfo($booking->beds24_booking_id);

            $updates = [];
            if (!empty($info['guest_name'])) {
                $updates['guest_name'] = $info['guest_name'];
            }
            if (!empty($info['guest_email']) && empty($booking->guest_email)) {
                $updates['guest_email'] = $info['guest_email'];
            }
            if (!empty($info['guest_phone']) && empty($booking->guest_phone)) {
                $updates['guest_phone'] = $info['guest_phone'];
            }

            if (!empty($updates)) {
                $booking->update($updates);
                $booking->refresh();
                Log::info('Beds24 Webhook: Enriched guest info from API', [
                    'booking_id' => $booking->beds24_booking_id,
                    'guest_name' => $booking->guest_name,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Beds24 Webhook: Failed to enrich guest info', [
                'booking_id' => $booking->beds24_booking_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildGuestName(array $data): string
    {
        $first = trim($data['first_name'] ?? '');
        $last  = trim($data['last_name']  ?? '');

        if ($first && $last) {
            return "{$first} {$last}";
        }

        return $first ?: $last ?: '';
    }

    private function deriveBookingStatus(array $data): string
    {
        $raw = strtolower(trim($data['status'] ?? 'confirmed'));

        return match (true) {
            str_contains($raw, 'cancel')   => 'cancelled',
            str_contains($raw, 'no_show'),
            str_contains($raw, 'noshow')   => 'no_show',
            str_contains($raw, 'await'),
            str_contains($raw, 'request')  => 'awaiting_payment',
            default                        => 'confirmed',
        };
    }

    private function derivePaymentStatus(array $data): string
    {
        $balance = $data['invoice_balance'] ?? null;
        $total   = (float) ($data['total_amount'] ?? 0);

        // If balance not provided in payload, default to pending
        if ($balance === null) {
            return 'pending';
        }

        $balance = (float) $balance;

        if ($balance <= 0 && $total > 0) {
            return 'paid';
        }

        if ($total > 0 && $balance < $total) {
            return 'partial';
        }

        return 'pending';
    }

    private function deriveChangeType(
        Beds24Booking $booking,
        array $oldData,
        string $newStatus,
        bool $amountChanged,
        array $changedFields,
        array $newCharges = [],
        bool $isNewCheckout = false
    ): ?string {
        $oldStatus = $oldData['booking_status'] ?? 'confirmed';

        // Checkout takes highest priority — cleaners need to know immediately
        if ($isNewCheckout) {
            return 'checked_out';
        }

        // Cancellation takes priority
        if ($newStatus === 'cancelled' && $oldStatus !== 'cancelled') {
            return 'cancelled';
        }

        // Amount reduction (suspicious)
        $newAmount = (float) ($booking->total_amount ?? 0);
        $oldAmount = (float) ($oldData['total_amount'] ?? 0);
        if ($amountChanged && $newAmount < $oldAmount && $oldAmount > 0) {
            return 'amount_changed';
        }

        // General modification
        if (!empty($changedFields) || $amountChanged) {
            return 'modified';
        }

        // Payment status changed (not alarming, just track)
        if (($oldData['payment_status'] ?? '') !== $booking->payment_status) {
            return 'payment_updated';
        }

        // New charge items added (e.g. taxi, minibar, extra services)
        if (!empty($newCharges)) {
            return 'charge_added';
        }

        return null; // Nothing meaningful
    }

    /**
     * Detect new charge items by comparing current webhook invoiceItems
     * with the previously stored raw data.
     */
    private function detectNewCharges(Beds24Booking $booking, array $raw): array
    {
        $newItems = $raw['invoiceItems'] ?? $raw['booking']['invoiceItems'] ?? [];
        if (empty($newItems)) {
            return [];
        }

        // Get old invoice item IDs from stored raw data
        $oldRaw = $booking->beds24_raw_data ?? [];
        $oldItems = $oldRaw['invoiceItems'] ?? $oldRaw['booking']['invoiceItems'] ?? [];
        $oldIds = array_map(fn($i) => $i['id'] ?? null, $oldItems);
        $oldIds = array_filter($oldIds);

        // Find new charge items (type=charge) that weren't in previous webhook
        $newCharges = [];
        foreach ($newItems as $item) {
            $type = $item['type'] ?? '';
            $id = $item['id'] ?? null;
            if ($type === 'charge' && $id && !in_array($id, $oldIds)) {
                $newCharges[] = $item;
            }
        }

        if (!empty($newCharges)) {
            Log::info('Beds24 Webhook: New charges detected', [
                'booking_id' => $booking->beds24_booking_id,
                'new_charges' => count($newCharges),
                'items' => array_map(fn($c) => [
                    'description' => $c['description'] ?? '?',
                    'amount' => $c['lineTotal'] ?? 0,
                ], $newCharges),
            ]);
        }

        return $newCharges;
    }

    /**
     * Calculate invoice balance from invoiceItems when invoiceBalance is not provided.
     * Balance = sum of charges - sum of payments
     */
    private function calculateBalanceFromItems(array $raw): ?float
    {
        $items = $raw['invoiceItems'] ?? $raw['booking']['invoiceItems'] ?? [];
        if (empty($items)) {
            return null;
        }

        // Beds24 invoiceItems: charges have positive lineTotal, payments have NEGATIVE lineTotal
        // So balance = sum of all lineTotals (positive = unpaid, 0 = fully paid)
        $balance = 0;
        foreach ($items as $item) {
            $balance += (float) ($item['lineTotal'] ?? 0);
        }

        return max(0, $balance); // balance can't be negative
    }

    private function parseDate(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
