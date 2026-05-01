<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Post-tour internal feedback row. Created at reminder-send time
 * (sent-but-unfilled), then completed when the guest submits the public
 * form. Persisting unfilled rows lets us measure completion rate.
 *
 * Supplier ids are deliberately snapshotted at send time — a later
 * reassignment on the inquiry must NOT shift this rating onto the new
 * driver / guide.
 */
class TourFeedback extends Model
{
    // "Feedback" is uncountable in Laravel's inflector — auto-resolution
    // would give us tour_feedback (singular), but the migration uses the
    // plural form for consistency with every other table in the schema.
    protected $table = 'tour_feedbacks';

    protected $fillable = [
        'inquiry_id',
        'driver_id',
        'guide_id',
        'accommodation_id',
        'driver_rating',
        'guide_rating',
        'accommodation_rating',
        'overall_rating',
        'driver_issue_tags',
        'guide_issue_tags',
        'accommodation_issue_tags',
        'comments',
        'token',
        'source',
        'opener_index',
        'submitted_at',
        'ip_address',
    ];

    protected $casts = [
        'driver_issue_tags'        => 'array',
        'guide_issue_tags'         => 'array',
        'accommodation_issue_tags' => 'array',
        'submitted_at'             => 'datetime',
    ];

    public static function generateToken(): string
    {
        // 32 chars, URL-safe. ~190 bits of entropy — more than enough for
        // single-use feedback links. Loop-guard against the (cosmically
        // unlikely) collision so we never throw on insert.
        do {
            $token = Str::random(32);
        } while (self::where('token', $token)->exists());

        return $token;
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(BookingInquiry::class, 'inquiry_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function guide(): BelongsTo
    {
        return $this->belongsTo(Guide::class);
    }

    public function accommodation(): BelongsTo
    {
        return $this->belongsTo(Accommodation::class);
    }

    /** Submitted feedback whose worst rating is ≤ 3. Drives ops alerts + admin filters. */
    public function scopeLowRated(Builder $query): Builder
    {
        return $query->whereNotNull('submitted_at')->where(function (Builder $q) {
            $q->where('driver_rating', '<=', 3)
              ->orWhere('guide_rating', '<=', 3)
              ->orWhere('accommodation_rating', '<=', 3)
              ->orWhere('overall_rating', '<=', 3);
        });
    }

    public function scopeSubmitted(Builder $query): Builder
    {
        return $query->whereNotNull('submitted_at');
    }

    /** Cheapest-possible "is this row low-rated" check for use in code paths. */
    public function isLowRated(): bool
    {
        $ratings = array_filter([
            $this->driver_rating,
            $this->guide_rating,
            $this->accommodation_rating,
            $this->overall_rating,
        ], fn ($r) => $r !== null);

        if ($ratings === []) {
            return false;
        }

        return min($ratings) <= 3;
    }
}
