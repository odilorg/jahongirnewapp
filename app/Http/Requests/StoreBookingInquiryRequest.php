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

            'customer_name'     => ['required', 'string', 'min:2', 'max:191'],
            'customer_email'    => ['required', 'email', 'max:191'],
            'customer_phone'    => ['required', 'string', 'min:5', 'max:64'],
            'preferred_contact' => ['nullable', Rule::in(['email', 'phone', 'whatsapp', 'telegram'])],

            'people_adults'   => ['required', 'integer', 'min:1', 'max:50'],
            'people_children' => ['nullable', 'integer', 'min:0', 'max:50'],

            'travel_date'    => ['nullable', 'date', 'after_or_equal:today'],
            'flexible_dates' => ['nullable', 'boolean'],

            'message' => ['nullable', 'string', 'max:5000'],

            // Source override — default to 'website' if not provided.
            'source' => ['nullable', 'string', Rule::in(['website', 'telegram', 'manual', 'gyg'])],
        ];
    }

    /** Did the submitter trip the honeypot? */
    public function isLikelySpam(): bool
    {
        return filled($this->input('hp_company'));
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

        return [
            'source'             => $v['source'] ?? 'website',
            'tour_slug'          => $v['tour_slug'] ?? null,
            'tour_name_snapshot' => $v['tour_name_snapshot'],
            'page_url'           => $v['page_url'] ?? null,

            'customer_name'     => trim($v['customer_name']),
            'customer_email'    => mb_strtolower(trim($v['customer_email'])),
            'customer_phone'    => trim($v['customer_phone']),
            'preferred_contact' => $v['preferred_contact'] ?? null,

            'people_adults'   => (int) $v['people_adults'],
            'people_children' => (int) ($v['people_children'] ?? 0),

            'travel_date'    => $v['travel_date'] ?? null,
            'flexible_dates' => (bool) ($v['flexible_dates'] ?? false),

            'message' => $v['message'] ?? null,

            'status' => BookingInquiry::STATUS_NEW,
        ];
    }
}
