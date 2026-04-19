<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\LeadFollowUp;
use Filament\Pages\Page;

/**
 * Lead CRM Phase 2a — operator's daily-start screen.
 *
 * Four sections in one view: Overdue, Leads-without-followup, Due Today,
 * Upcoming. Each rendered by its own Livewire child so tables can poll and
 * re-render independently without fighting Filament's single-table-per-page
 * constraint.
 */
class FollowUpQueuePage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $navigationLabel = 'Follow-up Queue';

    protected static ?string $navigationGroup = 'Leads';

    protected static ?int $navigationSort = -100;

    protected static string $view = 'filament.pages.follow-up-queue';

    protected static ?string $slug = 'follow-up-queue';

    protected static ?string $title = 'Follow-up Queue';

    public static function getNavigationBadge(): ?string
    {
        $count = LeadFollowUp::overdue()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'danger';
    }
}
