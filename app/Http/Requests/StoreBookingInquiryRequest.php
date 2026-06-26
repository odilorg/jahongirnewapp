<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\BookingInquiry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for POST /api/v1/inquiries
 *
 * Spam mitigation:
 *  - `hp_company` is a honeypot field: CSS-hidden in the form, so a real
 *    browser user will never fill it. Any non-empty value is treated as spam
 *    and silently stored with status=spam (never surfaced to the operator
 *    workflow, but retained so we can measure spam volume).
 *  - Throttle middleware on the route provides per-IP rate limiting.
 *  - Existing reCAPTCHA on mailer-tours.php runs before we are called.
 */
class StoreBookingInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Honeypot — must be empty or missing. If populated, the
            // controller will mark the row as spam rather than rejecting,
            // so bots get a 201 and stop retrying.
            'hp_company' => ['nullable', 'string', 'max:255'],

            'tour_slug'          => ['nullable', 'string', 'max:191'],
            'tour_name_snapshot' => ['required', 'string', 'max:255'],
            'page_url'           => ['nullable', 'url', 'max:500'],
            // Operator-editable pickup_point can arrive prefilled from the
            // public form's hotel_to_pickup field so it goes straight into
            // the operational record (not buried in the message blob).
            'pickup_point'       => ['nullable', 'string', 'max:255'],

            'customer_name'     => ['required', 'string', 'min:2', 'max:191'],
            'customer_email'    => ['required', 'email', 'max:191'],
            'customer_phone'    => ['required', 'string', 'min:5', 'max:64'],
            'customer_country'  => ['nullable', 'string', 'max:100'],
            'preferred_contact' => ['nullable', Rule::in(['email', 'phone', 'whatsapp', 'telegram'])],

            'people_adults'   => ['required', 'integer', 'min:1', 'max:50'],
            'people_children' => ['nullable', 'integer', 'min:0', 'max:50'],

            // Deliberately accept past dates: an inquiry for "last weekend" is
            // still a valid lead an operator should see. We'd rather capture
            // a quirky date and let the operator clarify than reject the row.
            // Accepts ISO datetime ("2026-06-02T09:00") — toInquiryData()
            // extracts the time component into pickup_time when present.
            'travel_date'    => ['nullable', 'date'],
            'pickup_time'    => ['nullable', 'date_format:H:i'],
            'flexible_dates' => ['nullable', 'boolean'],

            'message' => ['nullable', 'string', 'max:5000'],

            // Source override — default to 'website' if not provided.
            'source' => ['nullable', 'string', Rule::in(BookingInquiry::SOURCES)],

            // Optional yurt-hub routing fields from jahongir-travel.uz.
            // Intentionally NOT enum-restricted: an unknown direction or an
            // unrecognised booking_type must NOT 422 the public form
            // (fail-soft). EnrichWebsiteInquiryFromTourSlugAction resolves
            // direction against the catalog, and toInquiryData() ignores a
            // booking_type outside TOUR_TYPES. dropoff_hotel is accepted for
            // forward-compatibility but has no column yet, so it is not
            // persisted (the website does not send it today).
            'direction'     => ['nullable', 'string', 'max:64'],
            'booking_type'  => ['nullable', 'string', 'max:16'],
            'dropoff_hotel' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** Did the submitter trip the honeypot? */
    public function isLikelySpam(): bool
    {
        return filled($this->input('hp_company'));
    }

    /**
     * The website-requested route code (yurt hub pages), or null. Resolved
     * against the catalog by EnrichWebsiteInquiryFromTourSlugAction — never
     * enum-validated here, so an unknown route is fail-soft, not a 422.
     */
    public function requestedDirectionCode(): ?string
    {
        $code = trim((string) ($this->validated()['direction'] ?? ''));

        return $code !== '' ? $code : null;
    }

    /**
     * booking_type → tour_type, but only when it is a recognised type.
     * An unrecognised value yields null so creation never breaks and the
     * enrichment Action applies the catalog default instead.
     */
    private function resolveTourType(): ?string
    {
        $type = trim((string) ($this->validated()['booking_type'] ?? ''));

        return in_array($type, BookingInquiry::TOUR_TYPES, true) ? $type : null;
    }

    /**
     * Map validated input to a row-ready payload.
     *
     * Caller is responsible for setting `reference`, `ip_address`, `user_agent`,
     * `submitted_at`, and `status` (we set status here only for the happy path
     * so the controller can override it to 'spam' if needed).
     */
    public function toInquiryData(): array
    {
        $v = $this->validated();

        // The website form sends `travel_date` as ISO datetime (e.g.
        // "2026-06-02T09:00"). The DB column is `date`, which silently
        // strips the time. Extract pickup_time from the input ONLY when
        // a real time component was present — never default to 00:00:00,
        // which would mislead operators about the actual pickup time.
        [$travelDate, $pickupTime] = $this->splitTravelDateTime(
            $v['travel_date'] ?? null,
            $v['pickup_time'] ?? null,
        );

        return [
            'source'             => $v['source'] ?? 'website',
            'tour_slug'          => $v['tour_slug'] ?? null,
            'tour_name_snapshot' => $v['tour_name_snapshot'],
            'page_url'           => $v['page_url'] ?? null,
            'pickup_point'       => $v['pickup_point'] ?? null,

            'customer_name'     => trim($v['customer_name']),
            'customer_email'    => mb_strtolower(trim($v['customer_email'])),
            'customer_phone'    => trim($v['customer_phone']),
            'customer_country'  => $v['customer_country'] ?? null,
            'preferred_contact' => $v['preferred_contact'] ?? null,

            'people_adults'   => (int) $v['people_adults'],
            'people_children' => (int) ($v['people_children'] ?? 0),

            'travel_date'    => $travelDate,
            'pickup_time'    => $pickupTime,
            'flexible_dates' => (bool) ($v['flexible_dates'] ?? false),

            'message' => $v['message'] ?? null,

            // booking_type → tour_type (direct column). Only a value within
            // TOUR_TYPES is applied; anything else stays null so the
            // enrichment Action falls back to the catalog default.
            'tour_type' => $this->resolveTourType(),

            'status' => BookingInquiry::STATUS_NEW,
        ];
    }

    /**
     * Split a possibly-ISO travel_date input into a date-only string and
     * a separate pickup_time. Explicit pickup_time wins over the embedded
     * time component. Returns [date|null, time|null].
     */
    private function splitTravelDateTime(?string $rawDate, ?string $explicitTime): array
    {
        if ($rawDate === null) {
            return [null, $explicitTime];
        }

        // Detect a real time component in the raw input. Carbon::parse always
        // attaches 00:00:00 to a date-only string, so we cannot rely on the
        // parsed object alone to know if the user supplied a time.
        $hasTimeInInput = (bool) preg_match('/[T ]\d{1,2}:\d{2}/', $rawDate);

        try {
            $parsed = \Carbon\Carbon::parse($rawDate);
        } catch (\Throwable $e) {
            // Validation already enforced 'date' rule, so this should not
            // happen. Pass through raw to avoid swallowing a parse failure.
            return [$rawDate, $explicitTime];
        }

        $dateOnly  = $parsed->format('Y-m-d');
        $extracted = $hasTimeInInput ? $parsed->format('H:i:s') : null;

        // Explicit pickup_time on the request body wins. Otherwise, fall
        // back to the time embedded in travel_date (if any).
        return [$dateOnly, $explicitTime !== null ? $explicitTime.':00' : $extracted];
    }
}
