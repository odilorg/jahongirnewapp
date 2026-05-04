<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\TourFeedbackResource\Pages;
use App\Models\BookingInquiry;
use App\Models\TourFeedback;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Read-only admin view of post-tour feedback collected from guests.
 *
 * Strict invariants:
 *   - No create / edit / delete (feedback is guest-authored; admin
 *     never mutates a guest's submission)
 *   - Visible only to super_admin + admin (cashiers/kitchen/housekeeping
 *     have no operational reason to see verbatim guest sentiment)
 *   - Viewing a row never changes its state (no "mark as seen")
 *
 * The list defaults to submitted feedback only — sent-but-unfilled rows
 * exist in the table for completion-rate accounting but are not the
 * primary signal an operator wants on first load.
 */
class TourFeedbackResource extends Resource
{
    protected static ?string $model = TourFeedback::class;

    protected static ?string $navigationIcon  = 'heroicon-o-star';
    protected static ?string $navigationLabel = 'Guest Feedback';
    protected static ?string $navigationGroup = 'Tour Operations';
    protected static ?int    $navigationSort  = 50;

    protected static ?string $recordTitleAttribute = 'token';

    // ──────────────────────────────────────────────
    // Authorization — read-only, admin/super_admin only
    // ──────────────────────────────────────────────

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->hasAnyRole(['super_admin', 'admin']);
    }

    public static function canView(Model $record): bool
    {
        return self::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    // ──────────────────────────────────────────────
    // List
    // ──────────────────────────────────────────────

    public static function table(Table $table): Table
    {
        return $table
            // Eager-load relations actually rendered in columns to keep the
            // list query under control as feedback grows.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'inquiry:id,reference,customer_name,tour_name_snapshot,travel_date',
                'driver:id,first_name,last_name',
                'guide:id,first_name,last_name',
                'accommodation:id,name',
            ]))
            ->defaultSort('submitted_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('submitted_at')
                    ->label('Submitted')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->placeholder('— not yet —'),

                Tables\Columns\TextColumn::make('inquiry.customer_name')
                    ->label('Guest')
                    ->searchable()
                    ->limit(32),

                Tables\Columns\TextColumn::make('inquiry.tour_name_snapshot')
                    ->label('Tour')
                    ->limit(40)
                    ->tooltip(fn (TourFeedback $r): ?string => $r->inquiry?->tour_name_snapshot),

                Tables\Columns\TextColumn::make('inquiry.travel_date')
                    ->label('Travel date')
                    ->date('d M Y'),

                Tables\Columns\TextColumn::make('overall_rating')
                    ->label('Overall')
                    ->formatStateUsing(fn (?int $state): string => $state ? str_repeat('⭐', $state) : '—'),

                Tables\Columns\TextColumn::make('driver_rating')
                    ->label('Driver')
                    ->formatStateUsing(fn (?int $state): string => $state ? (string) $state . '★' : '—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('guide_rating')
                    ->label('Guide')
                    ->formatStateUsing(fn (?int $state): string => $state ? (string) $state . '★' : '—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('accommodation_rating')
                    ->label('Stay')
                    ->formatStateUsing(fn (?int $state): string => $state ? (string) $state . '★' : '—')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('low_rated')
                    ->label('Low')
                    ->state(fn (TourFeedback $r): bool => $r->isLowRated())
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->falseIcon('')
                    ->trueColor('danger'),

                Tables\Columns\TextColumn::make('comments')
                    ->label('Comment')
                    ->limit(60)
                    ->tooltip(fn (TourFeedback $r): ?string => $r->comments)
                    ->wrap(),
            ])
            ->filters([
                Filter::make('submitted_only')
                    ->label('Submitted only')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->submitted()),

                Filter::make('low_rated_only')
                    ->label('Low-rated only (≤3★)')
                    ->query(fn (Builder $query): Builder => $query->lowRated()),

                Filter::make('submitted_at')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('From'),
                        \Filament\Forms\Components\DatePicker::make('to')->label('To'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('submitted_at', '>=', $d))
                            ->when($data['to'] ?? null, fn (Builder $q, $d) => $q->whereDate('submitted_at', '<=', $d));
                    })
                    ->indicateUsing(function (array $data): array {
                        $out = [];
                        if (! empty($data['from'])) $out[] = 'From '.$data['from'];
                        if (! empty($data['to']))   $out[] = 'To '.$data['to'];
                        return $out;
                    }),

                SelectFilter::make('tour_product_id')
                    ->label('Tour')
                    ->options(fn (): array => BookingInquiry::query()
                        ->whereNotNull('tour_name_snapshot')
                        ->orderBy('tour_name_snapshot')
                        ->pluck('tour_name_snapshot', 'tour_product_id')
                        ->filter()
                        ->unique()
                        ->all())
                    ->query(fn (Builder $query, $data): Builder => $data['value']
                        ? $query->whereHas('inquiry', fn (Builder $i) => $i->where('tour_product_id', $data['value']))
                        : $query),

                SelectFilter::make('driver_id')
                    ->label('Driver')
                    ->relationship('driver', 'first_name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('guide_id')
                    ->label('Guide')
                    ->relationship('guide', 'first_name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('accommodation_id')
                    ->label('Accommodation')
                    ->relationship('accommodation', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    // ──────────────────────────────────────────────
    // View page
    // ──────────────────────────────────────────────

    public static function infolist(Infolist $infolist): Infolist
    {
        $tagDictionary = (array) config('feedback_issue_tags', []);

        $tagFormatter = function (?array $keys, string $role) use ($tagDictionary): string {
            if (empty($keys)) {
                return '—';
            }
            $labels = array_map(
                fn (string $k): string => $tagDictionary[$role][$k] ?? $k,
                $keys,
            );

            return implode(' · ', $labels);
        };

        return $infolist
            ->schema([
                Section::make('Submission')
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('submitted_at')
                                ->label('Submitted at')
                                ->dateTime('d M Y H:i')
                                ->placeholder('Not yet submitted'),
                            TextEntry::make('source')
                                ->label('Channel')
                                ->placeholder('—'),
                            TextEntry::make('opener_index')
                                ->label('Opener variant')
                                ->placeholder('—'),
                        ]),
                    ]),

                Section::make('Ratings')
                    ->schema([
                        Grid::make(4)->schema([
                            TextEntry::make('overall_rating')
                                ->label('Overall')
                                ->formatStateUsing(fn (?int $state): string => $state ? str_repeat('⭐', $state) : '—'),
                            TextEntry::make('driver_rating')
                                ->label('Driver')
                                ->formatStateUsing(fn (?int $state): string => $state ? str_repeat('⭐', $state) : '—'),
                            TextEntry::make('guide_rating')
                                ->label('Guide')
                                ->formatStateUsing(fn (?int $state): string => $state ? str_repeat('⭐', $state) : '—'),
                            TextEntry::make('accommodation_rating')
                                ->label('Stay')
                                ->formatStateUsing(fn (?int $state): string => $state ? str_repeat('⭐', $state) : '—'),
                        ]),
                    ]),

                Section::make('Issue tags')
                    ->visible(fn (TourFeedback $r): bool => $r->isLowRated())
                    ->schema([
                        TextEntry::make('driver_issue_tags')
                            ->label('Driver')
                            ->state(fn (TourFeedback $r): string => $tagFormatter($r->driver_issue_tags, 'driver')),
                        TextEntry::make('guide_issue_tags')
                            ->label('Guide')
                            ->state(fn (TourFeedback $r): string => $tagFormatter($r->guide_issue_tags, 'guide')),
                        TextEntry::make('accommodation_issue_tags')
                            ->label('Accommodation')
                            ->state(fn (TourFeedback $r): string => $tagFormatter($r->accommodation_issue_tags, 'accommodation')),
                    ]),

                Section::make('Comments')
                    ->schema([
                        TextEntry::make('comments')
                            ->label('')
                            ->placeholder('— guest left no written comment —')
                            ->prose(),
                    ]),

                Section::make('Linked booking inquiry')
                    ->schema([
                        Grid::make(2)->schema([
                            TextEntry::make('inquiry.reference')
                                ->label('Reference')
                                ->url(fn (TourFeedback $r): ?string => $r->inquiry
                                    ? BookingInquiryResource::getUrl('view', ['record' => $r->inquiry_id])
                                    : null)
                                ->openUrlInNewTab(),
                            TextEntry::make('inquiry.customer_name')->label('Guest'),
                            TextEntry::make('inquiry.tour_name_snapshot')->label('Tour'),
                            TextEntry::make('inquiry.travel_date')
                                ->label('Travel date')
                                ->date('d M Y'),
                            TextEntry::make('driver.first_name')
                                ->label('Driver (snapshot)')
                                ->state(fn (TourFeedback $r): string => trim(((string) $r->driver?->first_name).' '.((string) $r->driver?->last_name)) ?: '—'),
                            TextEntry::make('guide.first_name')
                                ->label('Guide (snapshot)')
                                ->state(fn (TourFeedback $r): string => trim(((string) $r->guide?->first_name).' '.((string) $r->guide?->last_name)) ?: '—'),
                            TextEntry::make('accommodation.name')->label('Accommodation (snapshot)')->placeholder('—'),
                        ]),
                    ]),

                Section::make('Send-side metadata')
                    ->collapsed()
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('inquiry.feedback_request_sent_at')
                                ->label('Feedback request sent')
                                ->dateTime('d M Y H:i')
                                ->placeholder('—'),
                            TextEntry::make('inquiry.review_request_sent_at')
                                ->label('Review request sent')
                                ->dateTime('d M Y H:i')
                                ->placeholder('—'),
                            TextEntry::make('ip_address')
                                ->label('Submit IP')
                                ->placeholder('—'),
                        ]),
                        TextEntry::make('token')
                            ->label('Token')
                            ->copyable()
                            ->formatStateUsing(fn (string $state): string => Str::limit($state, 12, '…')),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTourFeedback::route('/'),
            'view'  => Pages\ViewTourFeedback::route('/{record}'),
        ];
    }
}
