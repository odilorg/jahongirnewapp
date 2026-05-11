<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\HR\ApplicationStatus;
use App\Enums\HR\ExperienceLevel;
use App\Enums\HR\LanguageLevel;
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
            Forms\Components\Section::make('Контакт')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('full_name')->label('Имя')->disabled(),
                    Forms\Components\TextInput::make('phone')->label('Телефон')->disabled(),
                    Forms\Components\TextInput::make('whatsapp_phone')->label('WhatsApp')->disabled(),
                    Forms\Components\TextInput::make('age')->label('Возраст')->disabled(),
                    Forms\Components\TextInput::make('city')->label('Город')->disabled(),
                ]),

            Forms\Components\Section::make('Должность')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('position')
                        ->label('Должность')
                        ->formatStateUsing(fn ($state) => $state instanceof Position ? $state->label() : $state)
                        ->disabled(),
                    Forms\Components\TextInput::make('source')->label('Источник')->disabled(),
                    Forms\Components\TextInput::make('source_reference')->label('Источник (доп.)')->disabled(),
                    Forms\Components\TextInput::make('expected_salary_uzs')
                        ->label('Ожидаемая зарплата')
                        ->formatStateUsing(fn ($state) => $state ? number_format((int) $state, 0, '.', ' ').' UZS' : '—')
                        ->disabled(),
                    Forms\Components\DatePicker::make('available_from')->label('Доступен с')->disabled(),
                    Forms\Components\TextInput::make('can_work_weekends')
                        ->label('Выходные')
                        ->formatStateUsing(fn ($state) => $state ? 'Да' : 'Нет')
                        ->disabled(),
                    Forms\Components\TextInput::make('can_work_nights')
                        ->label('Ночные смены')
                        ->formatStateUsing(fn ($state) => $state ? 'Да' : 'Нет')
                        ->disabled(),
                ]),

            Forms\Components\Section::make('Опыт и языки')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('experience_level')
                        ->label('Опыт')
                        ->formatStateUsing(fn ($state) => $state instanceof ExperienceLevel ? $state->label() : $state)
                        ->disabled(),
                    Forms\Components\Textarea::make('previous_workplace_text')->label('Последнее место работы')->disabled()->columnSpanFull(),
                    Forms\Components\TextInput::make('uzbek_level')
                        ->label('Узбекский')
                        ->formatStateUsing(fn ($state) => $state instanceof LanguageLevel ? $state->label() : $state)
                        ->disabled(),
                    Forms\Components\TextInput::make('russian_level')
                        ->label('Русский')
                        ->formatStateUsing(fn ($state) => $state instanceof LanguageLevel ? $state->label() : $state)
                        ->disabled(),
                    Forms\Components\TextInput::make('english_level')
                        ->label('Английский')
                        ->formatStateUsing(fn ($state) => $state instanceof LanguageLevel ? $state->label() : $state)
                        ->disabled(),
                    Forms\Components\KeyValue::make('position_answers')
                        ->label('Доп. ответ для должности')
                        ->disabled()
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
