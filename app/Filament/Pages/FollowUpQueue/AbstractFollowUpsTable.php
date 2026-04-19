<?php

declare(strict_types=1);

namespace App\Filament\Pages\FollowUpQueue;

use App\Actions\Leads\CompleteFollowUp;
use App\Actions\Leads\SnoozeFollowUp;
use App\Enums\LeadPriority;
use App\Models\LeadFollowUp;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Shared shell for the three "open follow-ups" sections (Overdue, Due Today,
 * Upcoming). Subclasses only diff in their row query, label, color and poll
 * interval. Section 4 ("Leads without follow-up") does NOT inherit from this
 * because it queries a different table entirely.
 */
abstract class AbstractFollowUpsTable extends Component implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    abstract protected function rowsQuery(): Builder;

    abstract protected function sectionLabel(): string;

    abstract protected function sectionColor(): string;

    abstract protected function pollSeconds(): int;

    abstract protected function emptyHeading(): string;

    #[On('followup-queue-updated')]
    public function refreshSection(): void
    {
        $this->resetTable();
    }

    public function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->query($this->rowsQuery())
            ->columns([
                Tables\Columns\TextColumn::make('lead.name')
                    ->label('Lead')
                    ->weight('bold')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('lead.priority')
                    ->label('Priority')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof LeadPriority ? ucfirst($state->value) : (string) $state)
                    ->color(fn ($state) => match ($state instanceof LeadPriority ? $state->value : $state) {
                        'urgent' => 'danger',
                        'high'   => 'warning',
                        'medium' => 'gray',
                        'low'    => 'gray',
                        default  => 'gray',
                    }),

                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('effective_due')
                    ->label('Due')
                    ->dateTime('d M H:i', config('app.timezone'))
                    ->description(fn ($record) => $record->effective_due
                        ? \Carbon\Carbon::parse($record->effective_due)->diffForHumans()
                        : null),

                Tables\Columns\TextColumn::make('note')
                    ->label('Note')
                    ->limit(60)
                    ->tooltip(fn ($record) => $record->note),

                Tables\Columns\TextColumn::make('lead.assignee.name')
                    ->label('Assignee')
                    ->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\Action::make('done')
                    ->label('Done')
                    ->icon('heroicon-m-check')
                    ->color('success')
                    ->button()
                    ->action(function (LeadFollowUp $record): void {
                        app(CompleteFollowUp::class)->handle($record);
                        $this->dispatch('followup-queue-updated');
                    }),

                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('snooze_1h')
                        ->label('+1 hour')
                        ->action(function (LeadFollowUp $record): void {
                            app(SnoozeFollowUp::class)->handle($record, now()->addHour());
                            $this->dispatch('followup-queue-updated');
                        }),
                    Tables\Actions\Action::make('snooze_4h')
                        ->label('+4 hours')
                        ->action(function (LeadFollowUp $record): void {
                            app(SnoozeFollowUp::class)->handle($record, now()->addHours(4));
                            $this->dispatch('followup-queue-updated');
                        }),
                    Tables\Actions\Action::make('snooze_1d')
                        ->label('+1 day')
                        ->action(function (LeadFollowUp $record): void {
                            app(SnoozeFollowUp::class)->handle($record, now()->addDay());
                            $this->dispatch('followup-queue-updated');
                        }),
                ])
                    ->label('Snooze')
                    ->icon('heroicon-m-clock')
                    ->button(),

                Tables\Actions\Action::make('open_lead')
                    ->label('Open')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->disabled()
                    ->tooltip('Lead detail — coming in Phase 2b'),
            ])
            ->poll($this->pollSeconds().'s')
            ->emptyStateHeading($this->emptyHeading())
            ->emptyStateIcon('heroicon-o-check-circle')
            ->paginated([10, 25])
            ->defaultPaginationPageOption(25);
    }

    public function render()
    {
        return view('filament.pages.follow-up-queue.follow-up-table', [
            'label' => $this->sectionLabel(),
            'color' => $this->sectionColor(),
        ]);
    }
}
