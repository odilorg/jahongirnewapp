<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Temporary room cleaning priority for a single day.
 *
 * One active priority per room per day (unique constraint).
 * Absence of a row = normal priority. No "normal" rows stored.
 * Expires at midnight Tashkent time of the priority_date.
 */
class RoomPriority extends Model
{
    private const HOTEL_TZ = 'Asia/Tashkent';

    protected $table = 'room_priorities';

    protected $fillable = [
        'room_number',
        'priority',
        'reason',
        'set_by',
        'priority_date',
        'expires_at',
    ];

    protected $casts = [
        'room_number' => 'integer',
        'priority_date' => 'date',
        'expires_at' => 'datetime',
    ];

    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }

    // ── Scopes ───────────────────────────────────────────

    /**
     * Active priorities for today (Tashkent timezone).
     */
    public function scopeActiveForToday($query): void
    {
        $today = Carbon::now(self::HOTEL_TZ)->toDateString();
        $query->where('priority_date', $today);
    }

    /**
     * Active priorities for a specific date.
     */
    public function scopeForDate($query, string $date): void
    {
        $query->where('priority_date', $date);
    }

    // ── Domain Methods ───────────────────────────────────

    /**
     * Set or update priority for a room on a date.
     * Uses updateOrCreate — idempotent, one row per room per day.
     */
    public static function setPriority(
        int $roomNumber,
        string $priority,
        ?string $reason,
        ?int $setBy,
        ?string $date = null,
    ): self {
        $tz = self::HOTEL_TZ;
        $date = $date ?? Carbon::now($tz)->toDateString();

        // Expires at midnight Tashkent of the next day
        $expiresAt = Carbon::parse($date, $tz)->endOfDay();

        return self::updateOrCreate(
            ['room_number' => $roomNumber, 'priority_date' => $date],
            [
                'priority' => $priority,
                'reason' => $reason,
                'set_by' => $setBy,
                'expires_at' => $expiresAt,
            ],
        );
    }

    /**
     * Clear priority for a room on a date.
     */
    public static function clearPriority(int $roomNumber, ?string $date = null): void
    {
        $date = $date ?? Carbon::now(self::HOTEL_TZ)->toDateString();

        self::where('room_number', $roomNumber)
            ->where('priority_date', $date)
            ->delete();
    }

    /**
     * Get all active priorities for today, keyed by room number.
     * Returns: [7 => RoomPriority, 12 => RoomPriority, ...]
     */
    public static function todayByRoom(): array
    {
        $today = Carbon::now(self::HOTEL_TZ)->toDateString();

        return self::where('priority_date', $today)
            ->get()
            ->keyBy('room_number')
            ->all();
    }

    // ── Display Helpers ──────────────────────────────────

    public function badge(): string
    {
        return match ($this->priority) {
            'urgent' => '🔴',
            'important' => '🟡',
            default => '',
        };
    }

    public function label(): string
    {
        return match ($this->priority) {
            'urgent' => 'SHOSHILINCH',
            'important' => 'MUHIM',
            default => '',
        };
    }

    /**
     * Sort weight — lower = higher priority.
     */
    public function sortWeight(): int
    {
        return match ($this->priority) {
            'urgent' => 0,
            'important' => 1,
            default => 2,
        };
    }

    /**
     * Formatted line for cleaner-facing displays.
     * Compact: badge + label + truncated reason.
     */
    public function formatForCleaner(): string
    {
        $line = "{$this->badge()} {$this->label()}";
        if ($this->reason) {
            $short = mb_substr($this->reason, 0, 60);
            if (mb_strlen($this->reason) > 60) {
                $short .= '…';
            }
            $line .= ": {$short}";
        }

        return $line;
    }
}
