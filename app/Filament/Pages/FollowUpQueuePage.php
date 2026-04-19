<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\Leads\CreateFollowUp;
use App\Actions\Leads\FindOrCreateLeadByContact;
use App\Actions\Leads\LogInteraction;
use App\Enums\LeadFollowUpType;
use App\Enums\LeadInteractionChannel;
use App\Enums\LeadInteractionDirection;
use App\Enums\LeadSource;
use App\Exceptions\Leads\AmbiguousLeadMatchException;
use App\Models\Lead;
use App\Models\LeadFollowUp;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Lead CRM Phase 2a — operator's daily-start screen.
 *
 * Four sections rendered by their own Livewire children. Header has a
 * "New lead" quick-create (phase 2a.1): creates (or matches) a lead and
 * stamps an initial follow-up so the lead appears in the queue immediately.
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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('new_lead')
                ->label('New lead')
                ->icon('heroicon-o-plus')
                ->color('primary')
                ->modalHeading('New lead')
                ->modalSubmitActionLabel('Create lead + follow-up')
                ->modalWidth('lg')
                ->form([
                    Forms\Components\TextInput::make('name')
                        ->label('Name')
                        ->maxLength(255),
                    Forms\Components\TextInput::make('phone')
                        ->label('Phone')
                        ->tel()
                        ->maxLength(32),
                    Forms\Components\TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('whatsapp_number')
                        ->label('WhatsApp')
                        ->tel()
                        ->maxLength(32),
                    Forms\Components\Placeholder::make('hint')
                        ->label('')
                        ->content('Provide at least one of: phone, email, or WhatsApp.'),
                    Forms\Components\Textarea::make('note')
                        ->label('Note')
                        ->rows(3)
                        ->helperText('Context / where they came from. Saved to timeline and to the initial follow-up.'),
                ])
                ->action(function (array $data): void {
                    $this->handleNewLead($data);
                }),
        ];
    }

    private function handleNewLead(array $data): void
    {
        $contact = array_filter([
            'whatsapp_number' => $data['whatsapp_number'] ?? null,
            'phone'           => $data['phone']           ?? null,
            'email'           => $data['email']           ?? null,
        ]);

        if ($contact === []) {
            Notification::make()
                ->title('At least one contact field is required')
                ->body('Provide phone, email, or WhatsApp so we can match this person later.')
                ->danger()
                ->send();

            return;
        }

        try {
            $lead = app(FindOrCreateLeadByContact::class)->handle($contact, [
                'name'   => $data['name'] ?? null,
                'source' => LeadSource::Other->value,
            ]);
        } catch (AmbiguousLeadMatchException $e) {
            $lines = $e->matches
                ->map(fn (Lead $l) => '#'.$l->id.' — '.($l->name ?: $l->phone ?: $l->email ?: '(no name)'))
                ->implode(', ');

            Notification::make()
                ->title('Multiple leads match this contact')
                ->body($lines.'. Use a distinct contact field or open one of those leads directly (Leads page lands in Phase 2b).')
                ->warning()
                ->persistent()
                ->send();

            return;
        }

        $wasExisting = $lead->wasRecentlyCreated === false;

        if (! empty($data['note'])) {
            app(LogInteraction::class)->handle($lead, [
                'channel'   => LeadInteractionChannel::InternalNote->value,
                'direction' => LeadInteractionDirection::Internal->value,
                'body'      => $data['note'],
            ]);
        }

        app(CreateFollowUp::class)->handle($lead, [
            'due_at' => now()->addHour(),
            'type'   => LeadFollowUpType::Message->value,
            'note'   => $data['note'] ?? 'Initial contact',
        ]);

        Notification::make()
            ->title($wasExisting
                ? "Matched existing lead #{$lead->id}"
                : "Lead #{$lead->id} created")
            ->body('Follow-up due in 1 hour.')
            ->success()
            ->send();

        $this->dispatch('followup-queue-updated');
    }
}
