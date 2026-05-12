<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\HR\ApplicationStatus;
use App\Enums\HR\Position;
use App\Filament\Resources\JobCandidateResource\Pages;
use App\Models\JobCandidate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Log;

/**
 * HR review surface for job applications submitted via the public
 * `/jobs/apply` form.
 *
 * Phase 1, 2026-05-11. Access restricted to `super_admin` and `hr`
 * roles (PII scope) via `canAccess` + the panel-level navigation
 * check below. Read-only by design from inside Filament — there is
 * no "create" page; all candidates arrive through the public form.
 * Edit is allowed so HR can update status, notes, rating, etc.
 *
 * Status changes via the quick-action buttons stamp
 * `last_contacted_at = now()` so the "Stale" filter can surface
 * follow-up gaps.
 */
class JobCandidateResource extends Resource
{
    protected static ?string $model = JobCandidate::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-plus';

    protected static ?string $navigationGroup = 'HR';

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return 'Кандидаты';
    }

    public static function getModelLabel(): string
    {
        return 'Кандидат';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Кандидаты';
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        return $user->hasAnyRole(['super_admin', 'hr']);
    }

    public static function canCreate(): bool
    {
        // Public form is the only intake path — HR doesn't create
        // candidates from inside Filament.
        return false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            // All candidate-self-reported fields are read-only here —
            // they came in via the public form and are NOT editable
            // by HR. Use Placeholder components which give us a free
            // ->content() callback receiving the model record, so we
            // can render Russian enum labels / formatted values / "—"
            // for nulls without fighting Filament's form-input lifecycle
            // (TextInput->formatStateUsing receives the raw column
            // value on hydration, not the enum cast, so the previous
            // `$state instanceof Position` check was always falsy and
            // raw values leaked through).
            Forms\Components\Section::make('Контакт')
                ->columns(2)
                ->schema([
                    Forms\Components\Placeholder::make('full_name_display')
                        ->label('Имя')
                        ->content(fn ($record) => $record?->full_name ?? '—'),
                    Forms\Components\Placeholder::make('phone_display')
                        ->label('Телефон')
                        ->content(fn ($record) => $record?->phone ?? '—'),
                    Forms\Components\Placeholder::make('whatsapp_display')
                        ->label('WhatsApp')
                        ->content(fn ($record) => $record?->whatsapp_phone ?? '—'),
                    Forms\Components\Placeholder::make('age_display')
                        ->label('Возраст')
                        ->content(fn ($record) => $record?->age ?? '—'),
                    Forms\Components\Placeholder::make('city_display')
                        ->label('Город')
                        ->content(fn ($record) => $record?->city ?? '—'),
                ]),

            Forms\Components\Section::make('Должность')
                ->columns(2)
                ->schema([
                    Forms\Components\Placeholder::make('position_display')
                        ->label('Должность')
                        ->content(fn ($record) => $record?->position?->label() ?? '—'),
                    Forms\Components\Placeholder::make('source_display')
                        ->label('Источник')
                        ->content(fn ($record) => $record?->sourceLabel() ?? '—'),
                    Forms\Components\Placeholder::make('source_reference_display')
                        ->label('Источник (доп.)')
                        ->content(fn ($record) => $record?->source_reference ?? '—'),
                    Forms\Components\Placeholder::make('salary_display')
                        ->label('Ожидаемая зарплата')
                        ->content(fn ($record) => $record?->expected_salary_uzs
                            ? number_format((int) $record->expected_salary_uzs, 0, '.', ' ').' UZS'
                            : '—'),
                    Forms\Components\Placeholder::make('available_from_display')
                        ->label('Доступен с')
                        ->content(fn ($record) => $record?->available_from?->format('d.m.Y') ?? '—'),
                    Forms\Components\Placeholder::make('weekends_display')
                        ->label('Выходные')
                        ->content(fn ($record) => $record?->can_work_weekends ? 'Да' : 'Нет'),
                    Forms\Components\Placeholder::make('nights_display')
                        ->label('Ночные смены')
                        ->content(fn ($record) => $record?->can_work_nights ? 'Да' : 'Нет'),
                ]),

            Forms\Components\Section::make('Опыт и языки')
                ->columns(2)
                ->schema([
                    Forms\Components\Placeholder::make('experience_display')
                        ->label('Опыт')
                        ->content(fn ($record) => $record?->experience_level?->label() ?? '—'),
                    Forms\Components\Placeholder::make('previous_workplace_display')
                        ->label('Последнее место работы')
                        ->content(fn ($record) => $record?->previous_workplace_text ?: '—')
                        ->columnSpanFull(),

                    // Current occupation (new — 2026-05-11 follow-up).
                    Forms\Components\Placeholder::make('currently_working_display')
                        ->label('Сейчас работает')
                        ->content(fn ($record) => $record?->is_currently_working ? 'Да' : 'Нет'),
                    Forms\Components\Placeholder::make('currently_studying_display')
                        ->label('Сейчас учится')
                        ->content(fn ($record) => $record?->is_currently_studying ? 'Да' : 'Нет'),

                    // Time-of-day availability (new — 2026-05-11 follow-up).
                    // Spans full width because the comma-separated list
                    // can be long if all 4 slots are selected.
                    Forms\Components\Placeholder::make('availability_slots_display')
                        ->label('Доступное время')
                        ->content(fn ($record) => $record?->availabilitySlotsLabel() ?? '—')
                        ->columnSpanFull(),

                    Forms\Components\Placeholder::make('uzbek_display')
                        ->label('Узбекский')
                        ->content(fn ($record) => $record?->uzbek_level?->label() ?? '—'),
                    Forms\Components\Placeholder::make('russian_display')
                        ->label('Русский')
                        ->content(fn ($record) => $record?->russian_level?->label() ?? '—'),
                    Forms\Components\Placeholder::make('english_display')
                        ->label('Английский')
                        ->content(fn ($record) => $record?->english_level?->label() ?? '—'),
                    Forms\Components\Placeholder::make('position_answer_display')
                        ->label('Доп. ответ для должности')
                        ->content(fn ($record) => $record?->positionAnswerLabel() ?? '—')
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('HR — рабочий процесс')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('status')
                        ->label('Статус')
                        ->options(collect(ApplicationStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all())
                        ->required(),
                    Forms\Components\TextInput::make('status_reason')->label('Причина / комментарий статуса')->maxLength(255),
                    Forms\Components\DateTimePicker::make('interview_scheduled_at')->label('Интервью назначено на'),
                    Forms\Components\Select::make('assigned_to_user_id')
                        ->label('Ответственный')
                        ->relationship('assignedTo', 'name')
                        ->searchable()
                        ->preload(),
                    Forms\Components\Select::make('interviewer_user_id')
                        ->label('Интервьюер')
                        ->relationship('interviewer', 'name')
                        ->searchable()
                        ->preload(),
                    Forms\Components\Select::make('internal_rating')
                        ->label('Рейтинг (1–5)')
                        ->options([1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5']),
                    Forms\Components\Textarea::make('notes')->label('Заметки HR')->rows(3)->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('full_name')->label('Имя')->searchable(),
                Tables\Columns\TextColumn::make('phone')->label('Телефон')->searchable(),
                Tables\Columns\TextColumn::make('position')
                    ->label('Должность')
                    ->formatStateUsing(fn ($state) => $state instanceof Position ? $state->label() : $state)
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn ($state) => $state instanceof ApplicationStatus ? $state->badgeColor() : 'gray')
                    ->formatStateUsing(fn ($state) => $state instanceof ApplicationStatus ? $state->label() : $state),
                Tables\Columns\TextColumn::make('city')->label('Город')->toggleable(),
                Tables\Columns\TextColumn::make('expected_salary_uzs')
                    ->label('Зарплата')
                    ->formatStateUsing(fn ($state) => $state ? number_format((int) $state, 0, '.', ' ') : '—')
                    ->alignRight(),
                Tables\Columns\TextColumn::make('source')->label('Источник')->toggleable(),
                Tables\Columns\TextColumn::make('last_contacted_at')->label('Последний контакт')->dateTime('d.m.Y H:i')->since()->toggleable(),
                Tables\Columns\TextColumn::make('assignedTo.name')->label('Ответственный')->toggleable()->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->label('Подана')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('position')
                    ->label('Должность')
                    ->options(Position::publicOptions()),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Статус')
                    ->options(collect(ApplicationStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()),
                Tables\Filters\SelectFilter::make('source')
                    ->label('Источник')
                    ->options([
                        'olx' => 'OLX',
                        'telegram' => 'Telegram',
                        'referral' => 'Реферал',
                        'walk_in' => 'Зашёл сам',
                        'direct' => 'Прямой',
                        'other' => 'Другое',
                    ]),
                Tables\Filters\Filter::make('stale')
                    ->label('🕓 Без контакта > 7 дней')
                    ->query(fn ($query) => $query->stale(7))
                    ->toggle(),
                Tables\Filters\Filter::make('active_only')
                    ->label('Только активные')
                    ->query(fn ($query) => $query->active())
                    ->default()
                    ->toggle(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),

                // Status quick-actions — short, named buttons so HR can
                // move a candidate down the funnel without opening the
                // full edit form. Each stamps last_contacted_at.
                Tables\Actions\Action::make('markContacted')
                    ->label('Связались')
                    ->icon('heroicon-o-phone')
                    ->color('warning')
                    ->visible(fn (JobCandidate $r): bool => $r->status === ApplicationStatus::New)
                    ->action(fn (JobCandidate $r) => self::transition($r, ApplicationStatus::Contacted)),

                Tables\Actions\Action::make('markRejected')
                    ->label('Отказ')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Причина отказа')
                            ->required()
                            ->maxLength(255)
                            ->rows(2),
                    ])
                    ->visible(fn (JobCandidate $r): bool => ! $r->status?->isTerminal())
                    ->action(fn (JobCandidate $r, array $data) => self::transition($r, ApplicationStatus::Rejected, $data['reason'] ?? null)),

                Tables\Actions\Action::make('downloadCv')
                    ->label('CV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->visible(fn (JobCandidate $r): bool => $r->cv_path !== null
                        && (auth()->user()?->hasAnyRole(['super_admin', 'hr']) ?? false))
                    ->action(fn (JobCandidate $r) => app(\App\Actions\HR\DownloadCandidateCvAction::class)->execute($r)),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Apply a status transition with audit side-effects.
     * Stamps `last_contacted_at`, sets the reason (if provided), and
     * emits a Log::info line. Kept private/static here because it's
     * short (<10 LOC) and pure data — if it grows we'll extract it.
     */
    protected static function transition(JobCandidate $candidate, ApplicationStatus $to, ?string $reason = null): void
    {
        $previous = $candidate->status?->value;

        $candidate->forceFill([
            'status' => $to->value,
            'status_reason' => $reason,
            'last_contacted_at' => now(),
        ])->save();

        Log::info('JobCandidate status transition', [
            'candidate_id' => $candidate->id,
            'from' => $previous,
            'to' => $to->value,
            'reason' => $reason,
            'by_user_id' => auth()->id(),
        ]);

        Notification::make()
            ->title('Статус обновлён: '.$to->label())
            ->success()
            ->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListJobCandidates::route('/'),
            'view' => Pages\ViewJobCandidate::route('/{record}'),
            'edit' => Pages\EditJobCandidate::route('/{record}/edit'),
        ];
    }
}
