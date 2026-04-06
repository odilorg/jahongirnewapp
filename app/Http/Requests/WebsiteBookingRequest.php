<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates and normalises the JSON payload POSTed by mailer-tours.php.
 *
 * Field mapping from mailer-tours.php:
 *   first_name        → name
 *   email             → email
 *   phone             → phone
 *   hotel_to_pickup   → hotel
 *   departure_date    → date  (YYYY-MM-DD from <input type="date">)
 *   number_adults     → adults
 *   number_children   → children
 *   tour_name         → tour
 *   tour_code         → tour_code  (optional)
 */
class WebsiteBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization handled by VerifyWebsiteApiKey middleware
        return true;
    }

    public function rules(): array
    {
        return [
            'tour'      => ['required', 'string', 'max:255'],
            'name'      => ['required', 'string', 'max:255'],
            'email'     => ['required', 'email:rfc,dns', 'max:255'],
            'phone'     => ['required', 'string', 'max:50'],
            'hotel'     => ['nullable', 'string', 'max:255'],
            'date'      => ['required', 'date', 'after:today'],
            'adults'    => ['required', 'integer', 'min:1', 'max:50'],
            'children'  => ['required', 'integer', 'min:0', 'max:50'],
            'tour_code' => ['nullable', 'string', 'max:50'],
            'source'    => ['sometimes', 'string', 'in:website'],
        ];
    }

    public function messages(): array
    {
        return [
            'date.after' => 'Departure date must be in the future.',
        ];
    }

    /**
     * Return the payload as a clean, typed array ready for the service layer.
     * Strips the raw request from the service so it never needs to touch $request.
     */
    public function toBookingData(): array
    {
        return [
            'tour'      => $this->string('tour')->trim()->toString(),
            'name'      => $this->string('name')->trim()->toString(),
            'email'     => mb_strtolower($this->string('email')->trim()->toString()),
            'phone'     => $this->string('phone')->trim()->toString(),
            'hotel'     => $this->filled('hotel') ? $this->string('hotel')->trim()->toString() : null,
            'date'      => $this->string('date')->trim()->toString(),
            'adults'    => $this->integer('adults'),
            'children'  => $this->integer('children'),
            'tour_code' => $this->filled('tour_code') ? $this->string('tour_code')->trim()->toString() : null,
        ];
    }
}
