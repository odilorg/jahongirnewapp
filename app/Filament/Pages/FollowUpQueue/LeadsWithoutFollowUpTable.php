<?php

declare(strict_types=1);

namespace App\Filament\Pages\FollowUpQueue;

use App\Actions\Leads\CreateFollowUp;
use App\Enums\LeadFollowUpType;
use App\Enums\LeadPriority;
use App\Models\Lead;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Section 4 — the "dangerous forgotten" bucket.
 *
 * Surfaces active leads with NO open follow-up. Distinguishes leads that
 * never had one (red "Never") from leads whose follow-ups all got closed
 * without a next action ("Lapsed").
 */
class LeadsWithoutFollowUpTable extends Component implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    #[On('followup-queue-updated')]
    public function refreshSection(): void
    {
        $this->resetTable();
    }

    public function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->query(fn () => Lead::query()
                ->whereNotIn('status', ['converted', 'lost'])
                ->whereDoesntHave('followUps', fn (Builder $q) => $q->where('status', 'open'))
                ->selectRaw('leads.*, (CASE WHEN (SELECT COUNT(*) FROM lead_followups WHERE lead_followups.lead_id = leads.id) = 0 THEN 1 ELSE 0 END) as is_never_touched')
                ->with(['assignee:id,name'])
                ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END ASC")
                ->orderByDesc('is_never_touched')
                ->orderByRaw('last_interaction_at IS NULL DESC')
                ->orderBy('last_interaction_at', 'asc'))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Lead')
                    ->weight('bold')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('is_never_touched')
                    ->label('Reason')
                    ->badge()
                    ->formatStateUsing(fn ($state) => ((int) $state) === 1 ? 'Never' : 'Lapsed')
                    ->color(fn ($state) => ((int) $state) === 1 ? 'danger' : 'warning'),

                Tables\Columns\TextColumn::make('priority')
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

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('last_interaction_at')
                    ->label('Last contact')
                    ->since()
                    ->placeholder('Never')
                    ->color(fn ($state) => $state === null || \Carbon\Carbon::parse($state)->lt(now()->subDays(7))
                        ? 'danger'
                        : null),

                Tables\Columns\TextColumn::make('assignee.name')
                    ->label('Assignee')
                    ->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\Action::make('add_followup')
                    ->label('Add follow-up')
                    ->icon('heroicon-m-plus-circle')
                    ->color('primary')
                    ->button()
                    ->form([
                        Forms\Components\DateTimePicker::make('due_at')
                            ->label('Due at')
                            ->required()
                            ->default(fn () => now()->addHour())
                            ->seconds(false)
                            ->timezone(config('app.timezone')),
                        Forms\Components\Select::make('type')
                            ->label('Type')
                            ->options(collect(LeadFollowUpType::cases())->mapWithKeys(
                                fn ($t) => [$t->value => ucfirst(str_replace('_', ' ', $t->value))]
                            ))
                            ->default(LeadFollowUpType::CheckIn->value)
                            ->required(),
                        Forms\Components\Textarea::make('note')
                            ->label('Note')
                            ->rows(3),
                    ])
                    ->action(function (Lead $record, array $data): void {
                        app(CreateFollowUp::class)->handle($record, [
                            'due_at' => $data['due_at'],
                            'type'   => $data['type'],
                            'note'   => $data['note'] ?? null,
                        ]);
                        Notification::make()
                            ->title("Follow-up added for {$record->name}")
                            ->success()
                            ->send();
                        $this->dispatch('followup-queue-updated');
                    }),
            ])
            ->poll('60s')
            ->emptyStateHeading('Every active lead has a follow-up. 👍')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->paginated([10, 25])
            ->defaultPaginationPageOption(25);
    }

    public function render()
    {
        return view('filament.pages.follow-up-queue.follow-up-table', [
            'label' => 'No follow-up',
            'color' => 'gray',
        ]);
    }
}
