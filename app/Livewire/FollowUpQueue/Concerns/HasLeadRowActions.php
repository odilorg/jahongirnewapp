<?php

declare(strict_types=1);

namespace App\Livewire\FollowUpQueue\Concerns;

use App\Actions\Leads\LogInteraction;
use App\Actions\Leads\SetLeadPriority;
use App\Actions\Leads\TransitionLeadStatus;
use App\Actions\Leads\UpdateLeadContact;
use App\Enums\LeadInteractionChannel;
use App\Enums\LeadInteractionDirection;
use App\Enums\LeadPriority;
use App\Enums\LeadStatus;
use App\Models\Lead;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables;

/**
 * Phase 2b — row actions shared by the follow-up queue's four section tables.
 *
 * Three sections hold LeadFollowUp rows (lead accessed via ->lead); the
 * "Leads without follow-up" section holds Lead rows directly. The trait
 * delegates that difference to the consumer's leadFromRecord() method.
 */
trait HasLeadRowActions
{
    // Each consumer returns the Lead the row refers to. LeadFollowUp rows
    // return $record->lead; Lead rows return $record.
    abstract protected function leadFromRecord(mixed $record): Lead;

    protected function editContactAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('edit_contact')
            ->label('Edit contact')
            ->icon('heroicon-m-pencil-square')
            ->modalHeading('Edit contact')
            ->modalWidth('md')
            ->fillForm(fn ($record) => [
                'name'            => $this->leadFromRecord($record)->name,
                'phone'           => $this->leadFromRecord($record)->phone,
                'email'           => $this->leadFromRecord($record)->email,
                'whatsapp_number' => $this->leadFromRecord($record)->whatsapp_number,
            ])
            ->form([
                Forms\Components\TextInput::make('name')->maxLength(255),
                Forms\Components\TextInput::make('phone')->tel()->maxLength(32),
                Forms\Components\TextInput::make('email')->email()->maxLength(255),
                Forms\Components\TextInput::make('whatsapp_number')->tel()->maxLength(32)->label('WhatsApp'),
            ])
            ->action(function ($record, array $data): void {
                app(UpdateLeadContact::class)->handle($this->leadFromRecord($record), $data);
                Notification::make()->title('Contact updated')->success()->send();
                $this->dispatch('followup-queue-updated');
            });
    }

    protected function changePriorityAction(): Tables\Actions\ActionGroup
    {
        $actions = [];
        foreach (LeadPriority::cases() as $target) {
            $actions[] = Tables\Actions\Action::make('priority_'.$target->value)
                ->label(ucfirst($target->value))
                ->color(match ($target) {
                    LeadPriority::Urgent => 'danger',
                    LeadPriority::High   => 'warning',
                    default              => 'gray',
                })
                ->action(function ($record) use ($target): void {
                    app(SetLeadPriority::class)->handle($this->leadFromRecord($record), $target);
                    Notification::make()->title('Priority: '.ucfirst($target->value))->success()->send();
                    $this->dispatch('followup-queue-updated');
                });
        }

        return Tables\Actions\ActionGroup::make($actions)
            ->label('Priority')
            ->icon('heroicon-m-fire')
            ->button();
    }

    protected function changeStatusAction(): Tables\Actions\ActionGroup
    {
        $actions = [];
        foreach (LeadStatus::cases() as $target) {
            $needsConfirm = in_array($target, [LeadStatus::Converted, LeadStatus::Lost], true);

            $action = Tables\Actions\Action::make('status_'.$target->value)
                ->label(ucfirst(str_replace('_', ' ', $target->value)))
                ->visible(fn ($record) => in_array(
                    $target,
                    TransitionLeadStatus::allowedTransitionsFrom($this->leadFromRecord($record)->status),
                    true,
                ))
                ->action(function ($record, ?array $data = null) use ($target): void {
                    app(TransitionLeadStatus::class)->handle(
                        $this->leadFromRecord($record),
                        $target,
                        $data['waiting_reason'] ?? null,
                    );
                    Notification::make()->title('Status: '.$target->value)->success()->send();
                    $this->dispatch('followup-queue-updated');
                });

            if ($needsConfirm) {
                $action = $action
                    ->requiresConfirmation()
                    ->modalDescription("Mark this lead as {$target->value}? This is a terminal state.")
                    ->color('danger');
            }

            if (in_array($target, [LeadStatus::WaitingGuest, LeadStatus::WaitingInternal], true)) {
                $action = $action->form([
                    Forms\Components\TextInput::make('waiting_reason')
                        ->label('Waiting reason')
                        ->helperText('What are we waiting on?')
                        ->maxLength(500),
                ]);
            }

            $actions[] = $action;
        }

        return Tables\Actions\ActionGroup::make($actions)
            ->label('Status')
            ->icon('heroicon-m-arrows-right-left')
            ->button();
    }

    protected function logInteractionAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('log_interaction')
            ->label('Log interaction')
            ->icon('heroicon-m-chat-bubble-left-right')
            ->modalHeading('Log interaction')
            ->modalWidth('lg')
            ->form([
                Forms\Components\Select::make('channel')
                    ->options(collect(LeadInteractionChannel::cases())->mapWithKeys(
                        fn ($c) => [$c->value => ucfirst(str_replace('_', ' ', $c->value))]
                    ))
                    ->default(LeadInteractionChannel::WhatsApp->value)
                    ->required(),
                Forms\Components\Select::make('direction')
                    ->options(collect(LeadInteractionDirection::cases())->mapWithKeys(
                        fn ($d) => [$d->value => ucfirst($d->value)]
                    ))
                    ->default(LeadInteractionDirection::Inbound->value)
                    ->required(),
                Forms\Components\Textarea::make('body')
                    ->label('What happened')
                    ->rows(4)
                    ->required(),
                Forms\Components\Toggle::make('is_important')
                    ->label('Pin as important')
                    ->helperText('Highlights this entry on the lead timeline.'),
            ])
            ->action(function ($record, array $data): void {
                app(LogInteraction::class)->handle($this->leadFromRecord($record), [
                    'channel'      => $data['channel'],
                    'direction'    => $data['direction'],
                    'body'         => $data['body'],
                    'is_important' => (bool) ($data['is_important'] ?? false),
                ]);
                Notification::make()->title('Interaction logged')->success()->send();
                $this->dispatch('followup-queue-updated');
            });
    }
}
