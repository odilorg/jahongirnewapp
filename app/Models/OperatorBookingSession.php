<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tracks operator-initiated manual tour bookings via Telegram.
 *
 * One row per chat_id. The JSON `data` column accumulates booking fields
 * step-by-step; on completion it is passed directly to
 * WebsiteBookingService::createFromWebsite() and the session is reset.
 *
 * States:
 *   idle           → no booking in progress
 *   select_tour    → waiting for tour inline-button tap
 *   enter_date     → waiting for date string (YYYY-MM-DD)
 *   enter_adults   → waiting for adult count
 *   enter_children → waiting for children count
 *   enter_name     → waiting for guest full name
 *   enter_email    → waiting for guest email
 *   enter_phone    → waiting for guest phone
 *   enter_hotel    → waiting for hotel name (or "skip")
 *   confirm        → summary shown, waiting for ✅ or ❌
 */
class OperatorBookingSession extends Model
{
    protected $fillable = ['chat_id', 'state', 'data'];

    protected $casts = ['data' => 'array'];

    // ── Expiry ───────────────────────────────────────────────────────────────

    /** Operator sessions time out after 30 minutes of inactivity. */
    public function isExpired(int $timeoutMinutes = 30): bool
    {
        return $this->updated_at && $this->updated_at->addMinutes($timeoutMinutes)->isPast();
    }

    // ── State helpers ────────────────────────────────────────────────────────

    public function setState(string $state): void
    {
        $this->update(['state' => $state]);
    }

    public function reset(): void
    {
        $this->update(['state' => 'idle', 'data' => null]);
    }

    // ── Data helpers ─────────────────────────────────────────────────────────

    public function getData(string $key, mixed $default = null): mixed
    {
        return data_get($this->data ?? [], $key, $default);
    }

    public function setData(string $key, mixed $value): void
    {
        $current = $this->data ?? [];
        data_set($current, $key, $value);
        $this->update(['data' => $current]);
    }

    /** Return the accumulated data as the typed array WebsiteBookingService expects. */
    public function toBookingData(): array
    {
        $data = $this->data ?? [];

        return [
            'tour'      => $data['tour_name'],
            'name'      => $data['guest_name'],
            'email'     => $data['guest_email'],
            'phone'     => $data['guest_phone'],
            'hotel'     => $data['hotel'] ?: null,
            'date'      => $data['date'],
            'adults'    => (int) ($data['adults'] ?? 1),
            'children'  => (int) ($data['children'] ?? 0),
            'tour_code' => null,
        ];
    }
}
