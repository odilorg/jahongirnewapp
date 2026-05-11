<?php

declare(strict_types=1);

namespace App\Enums\HR;

/**
 * HR workflow status for a JobCandidate row.
 *
 * Happy path:
 *   new → contacted → phone_screened → interview_scheduled →
 *   interviewed → offered → hired
 *
 * Terminal off-paths reachable from any non-terminal state:
 *   rejected (HR decided no), withdrew (candidate decided no).
 *
 * State transitions are NOT enforced at the model level today —
 * Filament action buttons drive the supported transitions and any
 * other change goes through the standard edit form (allowed for
 * super_admin / hr roles). If state-machine enforcement becomes
 * necessary later (e.g. to block "hired" without a prior
 * "interviewed"), wrap the transition in a dedicated Action.
 */
enum ApplicationStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case PhoneScreened = 'phone_screened';
    case InterviewScheduled = 'interview_scheduled';
    case Interviewed = 'interviewed';
    case Offered = 'offered';
    case Hired = 'hired';
    case Rejected = 'rejected';
    case Withdrew = 'withdrew';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Новая',
            self::Contacted => 'Связались',
            self::PhoneScreened => 'Прошёл скрининг',
            self::InterviewScheduled => 'Интервью назначено',
            self::Interviewed => 'Прошёл интервью',
            self::Offered => 'Сделано предложение',
            self::Hired => 'Принят',
            self::Rejected => 'Отказ',
            self::Withdrew => 'Отозвал',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::New => 'info',
            self::Contacted => 'warning',
            self::PhoneScreened => 'warning',
            self::InterviewScheduled => 'primary',
            self::Interviewed => 'primary',
            self::Offered => 'success',
            self::Hired => 'success',
            self::Rejected => 'danger',
            self::Withdrew => 'gray',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Hired, self::Rejected, self::Withdrew], true);
    }
}
