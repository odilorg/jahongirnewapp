<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ViatorInboundEmailResource\Pages;
use App\Models\ViatorInboundEmail;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * V1 review queue for the Viator inbound-email pipeline.
 *
 * Strict invariants (mirror TourFeedbackResource):
 *   - canCreate / canEdit / canDelete = false (events are immutable;
 *     mutations would corrupt the audit log)
 *   - canViewAny gated to super_admin + admin (financial + customer-
 *     name surface; cashier/kitchen/housekeeping have no business need)
 *   - Index defaults to "needs_review" — operators see what demands
 *     attention without manually filtering
 *
 * V1 scope is deliberately read-only. Once operators trust the
 * pipeline, we'll add per-row "Approve amendment" / "Apply cancellation"
 * actions that perform the BookingInquiry mutation. Until then, raw
 * DB / artisan commands are the escape hatch.
 */
class ViatorInboundEmailResource extends Resource
{
    protected static ?string $model = ViatorInboundEmail::class;

    protected static ?string $navigationIcon  = 'heroicon-o-inbox-stack';
    protected static ?string $navigationLabel = 'Viator Review Queue';
    protected static ?string $navigationGroup = 'Tour Operations';
    protected static ?int    $navigationSort  = 60;

    protected static ?string $recordTitleAttribute = 'subject_raw';

    // ──────────────────────────────────────────────
    // Authorization — read-only, admin/super_admin only
    // ──────────────────────────────────────────────

    public static function canViewAny(): bool
    {
        $u = auth()->user();
        return $u !== null && $u->hasAnyRole(['super_admin', 'admin']);
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
    // Navigation badge — count of needs_review rows (red on any)
    // ──────────────────────────────────────────────

    public static function getNavigationBadge(): ?string
    {
        $count = (int) ViatorInboundEmail::query()
            ->where('processing_status', ViatorInboundEmail::STATUS_NEEDS_REVIEW)
            ->count();
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    // ──────────────────────────────────────────────
    // List
    // ──────────────────────────────────────────────

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Received')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('email_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'new'       => 'success',
                        'amended'   => 'warning',
                        'cancelled' => 'danger',
                        default     => 'gray',
                    }),

                Tables\Columns\TextColumn::make('external_reference')
                    ->label('BR')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('parsed_payload.lead_traveler_name')
                    ->label('Guest')
                    ->limit(28),

                Tables\Columns\TextColumn::make('parsed_payload.travel_date')
                    ->label('Travel date')
                    ->date('d M Y'),

                Tables\Columns\TextColumn::make('parsed_diff_summary')
                    ->label('Diff')
                    ->state(fn (ViatorInboundEmail $r): ?string => self::diffSummary($r->parsed_diff))
                    ->wrap()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('processing_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'applied'      => 'success',
                        'parsed'       => 'info',
                        'fetched'      => 'gray',
                        'needs_review' => 'warning',
                        'failed'       => 'danger',
                        default        => 'gray',
                    }),

                Tables\Columns\TextColumn::make('booking_inquiry.reference')
                    ->label('Inquiry')
                    ->placeholder('—')
                    ->url(fn (ViatorInboundEmail $r): ?string => $r->booking_inquiry_id
                        ? BookingInquiryResource::getUrl('view', ['record' => $r->booking_inquiry_id])
                        : null)
                    ->openUrlInNewTab(),
            ])
            ->filters([
                SelectFilter::make('processing_status')
                    ->label('Status')
                    ->options([
                        'needs_review' => 'Needs review',
                        'fetched'      => 'Fetched',
                        'parsed'       => 'Parsed',
                        'applied'      => 'Applied',
                        'failed'       => 'Failed',
                    ])
                    ->default('needs_review'),

                SelectFilter::make('email_type')
                    ->label('Event type')
                    ->options([
                        'new'       => 'New booking',
                        'amended'   => 'Amendment',
                        'cancelled' => 'Cancellation',
                        'unknown'   => 'Unknown',
                    ]),
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
        return $infolist->schema([
            Section::make('Event')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('created_at')->label('Received')->dateTime('d M Y H:i'),
                        TextEntry::make('email_type')->label('Type')->badge(),
                        TextEntry::make('processing_status')->label('Status')->badge(),
                    ]),
                    Grid::make(3)->schema([
                        TextEntry::make('external_reference')->label('Booking ref')->copyable(),
                        TextEntry::make('subject_raw')->label('Subject')->columnSpan(2),
                    ]),
                ]),

            Section::make('Diff against existing inquiry')
                ->visible(fn (ViatorInboundEmail $r): bool => ! empty($r->parsed_diff))
                ->schema([
                    KeyValueEntry::make('parsed_diff')
                        ->label('')
                        ->keyLabel('Field')
                        ->valueLabel('Old → New')
                        ->state(function (ViatorInboundEmail $r): array {
                            $out = [];
                            foreach ((array) ($r->parsed_diff ?? []) as $field => $delta) {
                                $out[$field] = ($delta['old'] ?? '—') . ' → ' . ($delta['new'] ?? '—');
                            }
                            return $out;
                        }),
                ]),

            Section::make('Parsed payload')
                ->collapsed()
                ->schema([
                    KeyValueEntry::make('parsed_payload')
                        ->keyLabel('Field')
                        ->valueLabel('Value')
                        ->state(function (ViatorInboundEmail $r): array {
                            $flat = [];
                            foreach ((array) ($r->parsed_payload ?? []) as $k => $v) {
                                if (is_array($v)) {
                                    $v = implode(' | ', array_map('strval', $v));
                                }
                                if ($v !== null && $v !== '' && $v !== []) {
                                    $flat[$k] = (string) $v;
                                }
                            }
                            return $flat;
                        }),
                ]),

            Section::make('Linked inquiry')
                ->visible(fn (ViatorInboundEmail $r): bool => $r->booking_inquiry_id !== null)
                ->schema([
                    TextEntry::make('booking_inquiry.reference')
                        ->label('Reference')
                        ->url(fn (ViatorInboundEmail $r): ?string => $r->booking_inquiry_id
                            ? BookingInquiryResource::getUrl('view', ['record' => $r->booking_inquiry_id])
                            : null)
                        ->openUrlInNewTab(),
                    TextEntry::make('booking_inquiry.customer_name')->label('Guest'),
                    TextEntry::make('booking_inquiry.travel_date')->label('Travel date')->date('d M Y'),
                ]),

            Section::make('Error trace')
                ->visible(fn (ViatorInboundEmail $r): bool => filled($r->error_message))
                ->schema([
                    TextEntry::make('error_message')->label('Error'),
                ]),

            Section::make('Raw email body')
                ->collapsed()
                ->schema([
                    TextEntry::make('raw_body')
                        ->label('')
                        ->prose(),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListViatorInboundEmails::route('/'),
            'view'  => Pages\ViewViatorInboundEmail::route('/{record}'),
        ];
    }

    /**
     * Compact one-line diff summary used in the list column. Returns
     * "travel_date, people_adults" for a row whose diff has those keys.
     */
    private static function diffSummary(?array $diff): ?string
    {
        if (! $diff) {
            return null;
        }
        return implode(', ', array_keys($diff));
    }
}
