<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Actions\WhatsApp\CreateInquiryFromWaCandidate;
use App\Actions\WhatsApp\DismissWaCandidate;
use App\Filament\Resources\WaLeadCandidateResource\Pages;
use App\Models\WaLeadCandidate;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Operator review queue for inbound WhatsApp prospects (wa_lead_candidates).
 * Read-only list + manual Create-inquiry / Dismiss actions. Admin/Manager only.
 * Manual create here is operator-initiated and always allowed — it is NOT the
 * gated auto-create (Phase 2d). No guest sends, no price, no payment.
 */
class WaLeadCandidateResource extends Resource
{
    protected static ?string $model = WaLeadCandidate::class;

    protected static ?string $navigationIcon  = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationLabel = 'WhatsApp Prospects';
    protected static ?string $navigationGroup = 'Tour Operations';
    protected static ?int    $navigationSort  = 15;
    protected static ?string $modelLabel      = 'WhatsApp prospect';

    // Admin/Manager only — bypass Shield policy with an explicit role check.
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin', 'manager']) ?? false;
    }

    public static function canAccess(): bool
    {
        return static::canViewAny();
    }

    public static function canView(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;   // candidates come from the scan, not hand-created here
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;   // review-only; mutate via the explicit actions
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }

        return (string) WaLeadCandidate::whereIn('status', [
            WaLeadCandidate::STATUS_PENDING, WaLeadCandidate::STATUS_REVIEW,
        ])->count();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_inbound_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('status')->badge()->colors([
                    'gray' => 'pending', 'warning' => 'review', 'success' => 'created', 'danger' => 'dismissed',
                ]),
                TextColumn::make('classification')->badge()->colors([
                    'success' => 'genuine_tour_inquiry', 'gray' => 'uncertain', 'danger' => 'not_lead',
                ])->toggleable(),
                TextColumn::make('not_lead_subtype')->label('Subtype')->badge()->toggleable(),
                TextColumn::make('confidence')->numeric(2)->sortable()->toggleable(),
                TextColumn::make('decision')->badge()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('detected_tour')->label('Tour')->limit(22)->searchable()->toggleable(),
                TextColumn::make('detected_party_size')->label('Pax')->toggleable(),
                TextColumn::make('detected_date')->label('Date')->date()->toggleable(),
                TextColumn::make('language')->label('Lang')->toggleable(),
                TextColumn::make('first_messages')->label('First message')
                    ->limit(90)->wrap()->searchable()
                    ->tooltip(fn (?string $state): ?string => $state ? mb_substr($state, 0, 200) : null),
                TextColumn::make('phone')->label('Phone')
                    ->formatStateUsing(fn (?string $state): string => $state
                        ? str_repeat('•', max(0, mb_strlen($state) - 3)) . mb_substr($state, -3) : '—'),
                TextColumn::make('reason')->limit(36)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_inbound_at')->label('Last inbound')->dateTime()->sortable()->toggleable(),
                TextColumn::make('booking_inquiry_id')->label('Inquiry')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'pending' => 'Pending', 'review' => 'Review', 'created' => 'Created', 'dismissed' => 'Dismissed',
                ]),
                SelectFilter::make('classification')->options([
                    'genuine_tour_inquiry' => 'Genuine tour', 'uncertain' => 'Uncertain', 'not_lead' => 'Not lead',
                ]),
                SelectFilter::make('not_lead_subtype')->label('Subtype')->options([
                    'accommodation' => 'Accommodation', 'logistics' => 'Logistics', 'b2b' => 'B2B',
                    'supplier' => 'Supplier', 'spam' => 'Spam', 'personal' => 'Personal', 'other' => 'Other',
                ]),
                SelectFilter::make('language')->options([
                    'en' => 'EN', 'ru' => 'RU', 'uz' => 'UZ', 'it' => 'IT', 'fr' => 'FR', 'ar' => 'AR', 'zh' => 'ZH',
                ]),
                SelectFilter::make('confidence_band')->label('Confidence')
                    ->options(['high' => '>= 0.85', 'medium' => '0.5 – 0.85', 'low' => '< 0.5'])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        'high'   => $query->where('confidence', '>=', 0.85),
                        'medium' => $query->whereBetween('confidence', [0.5, 0.8499]),
                        'low'    => $query->where('confidence', '<', 0.5),
                        default  => $query,
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('create_inquiry')
                    ->label('Create inquiry')->icon('heroicon-o-plus-circle')->color('success')
                    ->visible(fn (WaLeadCandidate $r): bool => $r->status !== WaLeadCandidate::STATUS_CREATED)
                    ->requiresConfirmation()
                    ->modalDescription('Create a CRM inquiry (source=whatsapp, status=new). No price, no message is sent to the guest.')
                    ->action(function (WaLeadCandidate $r): void {
                        $res = app(CreateInquiryFromWaCandidate::class)->create($r, auth()->user()?->name ?? 'operator');
                        Notification::make()
                            ->title($res['created'] ? "Inquiry #{$res['inquiry']->id} created" : "Linked to existing inquiry #{$res['inquiry']->id}")
                            ->success()->send();
                    }),
                Tables\Actions\Action::make('dismiss')
                    ->label('Dismiss')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (WaLeadCandidate $r): bool => ! in_array($r->status, [WaLeadCandidate::STATUS_DISMISSED, WaLeadCandidate::STATUS_CREATED], true))
                    ->form([Textarea::make('reason')->label('Dismiss reason')->required()->maxLength(191)])
                    ->action(function (WaLeadCandidate $r, array $data): void {
                        app(DismissWaCandidate::class)->dismiss($r, $data['reason'], auth()->user()?->name ?? 'operator');
                        Notification::make()->title('Candidate dismissed')->success()->send();
                    }),
                Tables\Actions\Action::make('mark_review')
                    ->label('Mark review')->icon('heroicon-o-eye')->color('warning')
                    ->visible(fn (WaLeadCandidate $r): bool => $r->status === WaLeadCandidate::STATUS_PENDING)
                    ->action(fn (WaLeadCandidate $r) => $r->forceFill(['status' => WaLeadCandidate::STATUS_REVIEW])->save()),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListWaLeadCandidates::route('/')];
    }
}
