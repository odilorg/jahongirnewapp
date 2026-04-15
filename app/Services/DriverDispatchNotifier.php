<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BookingInquiry;
use App\Models\InquiryStay;
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
        return $this->dispatchSupplier($inquiry, 'driver');
    }

    /**
     * @return array{ok: bool, reason?: string, msg_id?: int}
     */
    public function dispatchGuide(BookingInquiry $inquiry): array
    {
        return $this->dispatchSupplier($inquiry, 'guide');
    }

    /**
     * Dispatch to whichever suppliers are assigned. Reports per-recipient
     * results so the operator's UI can show partial successes ("driver
     * sent, guide failed") rather than a single all-or-nothing flag.
     *
     * Each accommodation stay produces its own per-stay message because
     * stays are independent legs of the tour with their own dates and
     * meal plans.
     *
     * @return array{
     *   driver: ?array{ok: bool, reason?: string, msg_id?: int},
     *   guide:  ?array{ok: bool, reason?: string, msg_id?: int},
     *   stays:  array<int, array{stay_id: int, accommodation: string, ok: bool, reason?: string, msg_id?: int}>,
     * }
     */
    public function dispatchAssigned(BookingInquiry $inquiry): array
    {
        $inquiry->loadMissing(['driver', 'guide', 'stays.accommodation']);

        $stayResults = [];
        foreach ($inquiry->stays as $stay) {
            if (! $stay->accommodation_id) {
                continue;
            }
            $r = $this->dispatchStay($inquiry, $stay);
            $stayResults[] = array_merge(
                ['stay_id' => $stay->id, 'accommodation' => $stay->accommodation?->name ?? '?'],
                $r,
            );
        }

        return [
            'driver' => $inquiry->driver_id ? $this->dispatchDriver($inquiry) : null,
            'guide'  => $inquiry->guide_id  ? $this->dispatchGuide($inquiry)  : null,
            'stays'  => $stayResults,
        ];
    }

    /**
     * @return array{ok: bool, reason?: string, msg_id?: int}
     */
    public function dispatchStay(BookingInquiry $inquiry, InquiryStay $stay): array
    {
        if (! (bool) config('services.tg_direct.enabled', true)) {
            return ['ok' => false, 'reason' => 'tg_direct_disabled'];
        }

        $accommodation = $stay->accommodation;

        if (! $accommodation) {
            return ['ok' => false, 'reason' => 'no_accommodation'];
        }

        $phone = (string) $accommodation->phone_primary;

        if ($phone === '') {
            return ['ok' => false, 'reason' => 'accommodation_missing_phone'];
        }

        $message = $this->buildStayMessage($inquiry, $stay);
        $result  = $this->tgDirect->send($phone, $message);

        if (! ($result['ok'] ?? false)) {
            Log::warning('DriverDispatchNotifier: stay dispatch failed', [
                'reference'        => $inquiry->reference,
                'stay_id'          => $stay->id,
                'accommodation_id' => $accommodation->id,
                'phone'            => $phone,
                'result'           => $result,
            ]);

            return ['ok' => false, 'reason' => $result['error'] ?? 'send_failed'];
        }

        Log::info('DriverDispatchNotifier: stay dispatch sent', [
            'reference'        => $inquiry->reference,
            'stay_id'          => $stay->id,
            'accommodation_id' => $accommodation->id,
            'msg_id'           => $result['msg_id'] ?? null,
        ]);

        return ['ok' => true, 'msg_id' => (int) ($result['msg_id'] ?? 0)];
    }

    /**
     * Single-supplier dispatch worker. Identical Uzbek brief is sent to
     * whoever is named — driver and guide use the same template because
     * they need the same operational context.
     */
    private function dispatchSupplier(BookingInquiry $inquiry, string $role): array
    {
        if (! (bool) config('services.tg_direct.enabled', true)) {
            return ['ok' => false, 'reason' => 'tg_direct_disabled'];
        }

        $supplier = $role === 'driver' ? $inquiry->driver : $inquiry->guide;

        if (! $supplier) {
            return ['ok' => false, 'reason' => "no_{$role}_assigned"];
        }

        $phone = (string) $supplier->phone01;

        if ($phone === '') {
            return ['ok' => false, 'reason' => "{$role}_missing_phone"];
        }

        $message = $this->buildMessage($inquiry);
        $result  = $this->tgDirect->send($phone, $message);

        if (! ($result['ok'] ?? false)) {
            Log::warning('DriverDispatchNotifier: send failed', [
                'reference'   => $inquiry->reference,
                'role'        => $role,
                'supplier_id' => $supplier->id,
                'phone'       => $phone,
                'result'      => $result,
            ]);

            return ['ok' => false, 'reason' => $result['error'] ?? 'send_failed'];
        }

        Log::info('DriverDispatchNotifier: dispatch sent', [
            'reference'   => $inquiry->reference,
            'role'        => $role,
            'supplier_id' => $supplier->id,
            'msg_id'      => $result['msg_id'] ?? null,
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

    /**
     * Render the Russian accommodation dispatch template for one stay.
     * Uses stay-specific date/nights/guest_count, falls back to inquiry
     * pax when guest_count is empty.
     */
    private function buildStayMessage(BookingInquiry $inquiry, InquiryStay $stay): string
    {
        $template = (string) config('inquiry_templates.accommodation_dispatch_ru', '');

        if ($template === '') {
            return 'Accommodation template missing';
        }

        $accommodation = $stay->accommodation;

        $stayDate = $stay->stay_date
            ? $this->formatRuDate($stay->stay_date)
            : '—';

        $nights = (int) ($stay->nights ?? 1);

        $guestCount = $stay->guest_count
            ?: ($inquiry->people_adults + (int) $inquiry->people_children);

        $customerWithCountry = filled($inquiry->customer_country)
            ? sprintf('%s (%s)', $inquiry->customer_name, $inquiry->customer_country)
            : (string) $inquiry->customer_name;

        $mealPlan = filled($stay->meal_plan) ? $stay->meal_plan : '—';
        $notes    = filled($stay->notes)     ? $stay->notes     : '—';

        $replacements = [
            '{accommodation}'              => $accommodation?->full_name ?? '—',
            '{customer_name_with_country}' => $customerWithCountry,
            '{customer_name}'              => (string) $inquiry->customer_name,
            '{customer_phone}'             => (string) $inquiry->customer_phone,
            '{stay_date}'                  => $stayDate,
            '{nights}'                     => (string) $nights,
            '{guest_count}'                => (string) $guestCount,
            '{meal_plan}'                  => $mealPlan,
            '{notes}'                      => $notes,
            '{reference}'                  => (string) $inquiry->reference,
        ];

        $rendered = str_replace(array_keys($replacements), array_values($replacements), $template);

        return preg_replace("/\n{3,}/", "\n\n", $rendered);
    }

    /**
     * Russian month names for accommodation dispatch.
     */
    private const RU_MONTHS = [
        1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля',
        5 => 'мая', 6 => 'июня', 7 => 'июля', 8 => 'августа',
        9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря',
    ];

    private function formatRuDate(\DateTimeInterface $date): string
    {
        $month = self::RU_MONTHS[(int) $date->format('n')] ?? $date->format('M');

        return (int) $date->format('j') . ' ' . $month . ' ' . $date->format('Y');
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
