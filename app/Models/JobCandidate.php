<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HR\ApplicationStatus;
use App\Enums\HR\ExperienceLevel;
use App\Enums\HR\LanguageLevel;
use App\Enums\HR\Position;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Public-form job application + HR review state.
 *
 * Created exclusively by the public application form
 * (`StoreJobApplicationController`); HR users update workflow columns
 * (status, notes, rating, assignment) via Filament. No code path
 * creates a candidate from inside Filament — by design, all intake is
 * the candidate's own self-report.
 *
 * Phase 1, 2026-05-11.
 */
class JobCandidate extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        // Contact
        'full_name',
        'phone',
        'whatsapp_phone',
        'age',
        'city',

        // Position & source
        'position',
        'source',
        'source_reference',

        // Compensation & availability
        'expected_salary_uzs',
        'available_from',
        'can_work_weekends',
        'can_work_nights',

        // Background
        'experience_level',
        'previous_workplace_text',
        'uzbek_level',
        'russian_level',
        'english_level',

        // Position-specific
        'position_answers',

        // File
        'cv_path',

        // HR workflow
        'status',
        'status_reason',
        'interview_scheduled_at',
        'assigned_to_user_id',
        'interviewer_user_id',
        'internal_rating',
        'notes',
        'last_contacted_at',

        // Audit
        'submitted_ip',
        'submitted_user_agent',
    ];

    protected $casts = [
        'position' => Position::class,
        'status' => ApplicationStatus::class,
        'experience_level' => ExperienceLevel::class,
        'uzbek_level' => LanguageLevel::class,
        'russian_level' => LanguageLevel::class,
        'english_level' => LanguageLevel::class,

        'age' => 'integer',
        'expected_salary_uzs' => 'integer',
        'internal_rating' => 'integer',
        'can_work_weekends' => 'boolean',
        'can_work_nights' => 'boolean',

        'position_answers' => 'array',

        'available_from' => 'date',
        'interview_scheduled_at' => 'datetime',
        'last_contacted_at' => 'datetime',
    ];

    // -----------------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------------

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function interviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'interviewer_user_id');
    }

    // -----------------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------------

    /**
     * Candidates that HR hasn't touched in N days. Used by the
     * Filament "Stale" filter to surface follow-up gaps.
     */
    public function scopeStale($query, int $days = 7)
    {
        $cutoff = now()->subDays($days);

        return $query->where(function ($q) use ($cutoff) {
            $q->whereNull('last_contacted_at')
                ->where('created_at', '<', $cutoff)
                ->orWhere('last_contacted_at', '<', $cutoff);
        })->whereNotIn('status', [
            ApplicationStatus::Hired->value,
            ApplicationStatus::Rejected->value,
            ApplicationStatus::Withdrew->value,
        ]);
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [
            ApplicationStatus::Hired->value,
            ApplicationStatus::Rejected->value,
            ApplicationStatus::Withdrew->value,
        ]);
    }

    // -----------------------------------------------------------------------
    // Display helpers — Russian labels for raw string columns that aren't
    // backed by an enum (source vocabulary is a varchar so operators can
    // extend it via config). Centralised here so Filament resource, reports,
    // and any future export share one mapping.
    // -----------------------------------------------------------------------

    /**
     * @var array<string, string>
     */
    private const SOURCE_LABELS = [
        'olx' => 'OLX',
        'telegram' => 'Telegram',
        'referral' => 'Реферал',
        'walk_in' => 'Зашёл сам',
        'direct' => 'Прямой',
        'other' => 'Другое',
    ];

    public function sourceLabel(): string
    {
        return self::SOURCE_LABELS[$this->source] ?? $this->source ?? '—';
    }

    /**
     * The single position-specific answer formatted for display.
     * Knows how to translate yes/no/select values back to Russian
     * labels (e.g. raw 'cook' → 'Повар' for kitchen role).
     */
    public function positionAnswerLabel(): string
    {
        $answers = $this->position_answers;
        if (! is_array($answers) || $answers === []) {
            return '—';
        }

        // Single key per Phase 1 design (one question per position).
        $rawValue = (string) reset($answers);

        if ($rawValue === '') {
            return '—';
        }

        // Yes/no answers — most positions
        if ($rawValue === 'yes') {
            return 'Да';
        }
        if ($rawValue === 'no') {
            return 'Нет';
        }

        // Kitchen-role select widget vocabulary
        $kitchenRoles = [
            'cook' => 'Повар',
            'assistant_cook' => 'Помощник повара',
            'dishwasher' => 'Посудомойщик',
            'prep' => 'Заготовщик',
            'other' => 'Другое',
        ];
        if (isset($kitchenRoles[$rawValue])) {
            return $kitchenRoles[$rawValue];
        }

        // Free-text answer (Position::Other) — return verbatim.
        return $rawValue;
    }
}
