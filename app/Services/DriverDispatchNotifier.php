<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BookingInquiry;
use Illuminate\Support\Facades\Log;

/**
 * Build a driver dispatch message from a BookingInquiry and send it via
 * tg-direct (Odil's personal Telegram session) to the assigned driver's
 * phone number. Uzbek template by default — that's the language drivers
 * actually use locally.
 *
 * Fails soft: any failure is logged and returned as a structured result;
 * the caller (Filament action) displays a danger notification but the
 * inquiry state is never touched by this class.
 */
class DriverDispatchNotifier
{
    public function __construct(
        private readonly TgDirectClient $tgDirect,
    ) {}

    /**
     * @return array{ok: bool, reason?: string, msg_id?: int}
     */
    public function dispatchDriver(BookingInquiry $inquiry): array
    {
        if (! (bool) config('services.tg_direct.enabled', true)) {
            return ['ok' => false, 'reason' => 'tg_direct_disabled'];
        }

        $driver = $inquiry->driver;

        if (! $driver) {
            return ['ok' => false, 'reason' => 'no_driver_assigned'];
        }

        $phone = (string) $driver->phone01;

        if ($phone === '') {
            return ['ok' => false, 'reason' => 'driver_missing_phone'];
        }

        $message = $this->buildMessage($inquiry);

        $result = $this->tgDirect->send($phone, $message);

        if (! ($result['ok'] ?? false)) {
            Log::warning('DriverDispatchNotifier: send failed', [
                'reference' => $inquiry->reference,
                'driver_id' => $driver->id,
                'phone'     => $phone,
                'result'    => $result,
            ]);

            return ['ok' => false, 'reason' => $result['error'] ?? 'send_failed'];
        }

        Log::info('DriverDispatchNotifier: dispatch sent', [
            'reference' => $inquiry->reference,
            'driver_id' => $driver->id,
            'msg_id'    => $result['msg_id'] ?? null,
        ]);

        return [
            'ok'     => true,
            'msg_id' => (int) ($result['msg_id'] ?? 0),
        ];
    }

    /**
     * Render the Uzbek driver dispatch template with all placeholders
     * filled. Unknown placeholders are left in place so the operator
     * sees them and can clarify before hitting send.
     */
    private function buildMessage(BookingInquiry $inquiry): string
    {
        $template = (string) config('inquiry_templates.driver_dispatch_uz', '');

        if ($template === '') {
            return 'Dispatch template missing';
        }

        $pax = $this->formatPax($inquiry->people_adults, $inquiry->people_children);

        // Uzbek-friendly date: "Aprel 13, 2026" rather than "2026-04-13"
        $travelDate = $inquiry->travel_date
            ? $this->formatUzDate($inquiry->travel_date)
            : '—';

        $pickupTime = $inquiry->pickup_time
            ? $inquiry->pickup_time
            : '—';

        $pickupPoint = $inquiry->pickup_point
            ? $inquiry->pickup_point
            : '—';

        $dropoffPoint = $inquiry->dropoff_point
            ? $inquiry->dropoff_point
            : '—';

        // "Noémie Vigne (Fransiya)" if we know the country, otherwise
        // just the name.
        $customerWithCountry = filled($inquiry->customer_country)
            ? sprintf('%s (%s)', $inquiry->customer_name, $inquiry->customer_country)
            : (string) $inquiry->customer_name;

        // operational_notes: entirely omit the placeholder when empty
        // so the signature doesn't float with a gap above it.
        $notes = filled($inquiry->operational_notes)
            ? $inquiry->operational_notes
            : '';

        $replacements = [
            '{reference}'                  => (string) $inquiry->reference,
            '{tour}'                       => (string) $inquiry->tour_name_snapshot,
            '{travel_date}'                => $travelDate,
            '{pickup_time}'                => $pickupTime,
            '{pickup_point}'               => $pickupPoint,
            '{dropoff_point}'              => $dropoffPoint,
            '{pax}'                        => $pax,
            '{customer_name}'              => (string) $inquiry->customer_name,
            '{customer_name_with_country}' => $customerWithCountry,
            '{customer_phone}'             => (string) $inquiry->customer_phone,
            '{notes}'                      => $notes,
        ];

        $rendered = str_replace(array_keys($replacements), array_values($replacements), $template);

        // Collapse triple newlines down to double when {notes} was empty,
        // so the message doesn't have a gaping hole before the signature.
        return preg_replace("/\n{3,}/", "\n\n", $rendered);
    }

    private function formatPax(int $adults, int $children): string
    {
        $parts = [];

        if ($adults > 0) {
            $parts[] = $adults . ' ' . ($adults === 1 ? 'katta yosh' : 'katta yoshli');
        }

        if ($children > 0) {
            $parts[] = $children . ' bola';
        }

        return $parts === [] ? '—' : implode(', ', $parts);
    }

    /**
     * Uzbek month names for dispatch date formatting.
     */
    private const UZ_MONTHS = [
        1 => 'Yanvar', 2 => 'Fevral', 3 => 'Mart', 4 => 'Aprel',
        5 => 'May', 6 => 'Iyun', 7 => 'Iyul', 8 => 'Avgust',
        9 => 'Sentabr', 10 => 'Oktabr', 11 => 'Noyabr', 12 => 'Dekabr',
    ];

    private function formatUzDate(\DateTimeInterface $date): string
    {
        $month = self::UZ_MONTHS[(int) $date->format('n')] ?? $date->format('M');

        return $month . ' ' . (int) $date->format('j') . ', ' . $date->format('Y');
    }
}
