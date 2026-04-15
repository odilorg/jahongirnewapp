<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\BookingInquiryResource\Pages;
use App\Models\BookingInquiry;
use App\Services\InquiryTemplateRenderer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filament admin resource for website booking inquiries (leads).
 *
 * Operator-focused: fast search, one-click status transitions, clean detail
 * view. Intentionally simple — no create form (inquiries originate from the
 * public website, never from the admin), no complicated relations, no bulk
 * edit flow. v1 priority is making operators find and action rows fast.
 */
class BookingInquiryResource extends Resource
{
    protected static ?string $model = BookingInquiry::class;

    protected static ?string $navigationIcon  = 'heroicon-o-inbox-arrow-down';
    protected static ?string $navigationLabel = 'Website Inquiries';
    protected static ?string $navigationGroup = 'Bookings';
    protected static ?int    $navigationSort  = 0;
    protected static ?string $recordTitleAttribute = 'reference';

    public static function getNavigationBadge(): ?string
    {
        // Unread signal: count of rows still at status=new.
        $count = (int) static::getModel()::where('status', BookingInquiry::STATUS_NEW)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Form $form): Form
    {
        // Edit form is only used for the internal_notes field + manual status
        // override. All customer-submitted fields are read-only on the detail
        // page (via Infolist below).
        return $form->schema([
            Forms\Components\Section::make('Operator')
                ->description('Internal fields — not visible to the customer.')
                ->schema([
                    Forms\Components\Select::make('status')
                        ->options([
                            BookingInquiry::STATUS_NEW               => 'New',
                            BookingInquiry::STATUS_CONTACTED         => 'Contacted',
                            BookingInquiry::STATUS_AWAITING_CUSTOMER => 'Awaiting customer',
                            BookingInquiry::STATUS_CONFIRMED         => 'Confirmed',
                            BookingInquiry::STATUS_CANCELLED         => 'Cancelled',
                            BookingInquiry::STATUS_SPAM              => 'Spam',
                        ])
                        ->required(),

                    Forms\Components\Textarea::make('internal_notes')
                        ->rows(6)
                        ->columnSpanFull()
                        ->helperText('Free-form notes for the operator team.'),
                ])
                ->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('reference')
                    ->label('Ref')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Reference copied')
                    ->fontFamily('mono')
                    ->size(Tables\Columns\TextColumn\TextColumnSize::Small),

                Tables\Columns\TextColumn::make('tour_name_snapshot')
                    ->label('Tour')
                    ->searchable()
                    ->limit(40)
                    ->tooltip(fn (BookingInquiry $record): string => (string) $record->tour_name_snapshot)
                    ->wrap(),

                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Customer')
                    ->searchable()
                    ->description(fn (BookingInquiry $record): string => (string) $record->customer_email),

                Tables\Columns\TextColumn::make('customer_phone')
                    ->label('Phone')
                    ->searchable()
                    ->copyable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('people_adults')
                    ->label('Pax')
                    ->formatStateUsing(fn (BookingInquiry $record): string => $record->people_children > 0
                        ? "{$record->people_adults}+{$record->people_children}"
                        : (string) $record->people_adults)
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('travel_date')
                    ->label('Travel')
                    ->date('M j, Y')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        BookingInquiry::STATUS_NEW               => 'New',
                        BookingInquiry::STATUS_CONTACTED         => 'Contacted',
                        BookingInquiry::STATUS_AWAITING_CUSTOMER => 'Awaiting customer',
                        BookingInquiry::STATUS_CONFIRMED         => 'Confirmed',
                        BookingInquiry::STATUS_CANCELLED         => 'Cancelled',
                        BookingInquiry::STATUS_SPAM              => 'Spam',
                        default                                  => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        BookingInquiry::STATUS_NEW               => 'warning',
                        BookingInquiry::STATUS_CONTACTED         => 'info',
                        BookingInquiry::STATUS_AWAITING_CUSTOMER => 'primary',
                        BookingInquiry::STATUS_CONFIRMED         => 'success',
                        BookingInquiry::STATUS_CANCELLED         => 'gray',
                        BookingInquiry::STATUS_SPAM              => 'danger',
                        default                                  => 'gray',
                    }),

                Tables\Columns\TextColumn::make('source')
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Received')
                    ->dateTime('M j, H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        BookingInquiry::STATUS_NEW               => 'New',
                        BookingInquiry::STATUS_CONTACTED         => 'Contacted',
                        BookingInquiry::STATUS_AWAITING_CUSTOMER => 'Awaiting customer',
                        BookingInquiry::STATUS_CONFIRMED         => 'Confirmed',
                        BookingInquiry::STATUS_CANCELLED         => 'Cancelled',
                        BookingInquiry::STATUS_SPAM              => 'Spam',
                    ])
                    ->multiple(),

                SelectFilter::make('source')
                    ->options([
                        'website'  => 'Website',
                        'telegram' => 'Telegram',
                        'manual'   => 'Manual',
                        'gyg'      => 'GYG',
                    ])
                    ->multiple(),

                // Custom date-range filter removed temporarily while we
                // diagnose a getModel() null issue inside filter form init.
                // Status + source filters are sufficient for v1.
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                // ── WhatsApp quick-reply templates ──────────────────────
                // Grouped under a single dropdown to keep the row compact.
                // Each action opens WhatsApp in a new tab with a prefilled,
                // rendered template. No server-side WA send in v1 — deep
                // links only, operator stays in control of the actual
                // message (they can edit before hitting send on WhatsApp).
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('waInitial')
                        ->label('WA: Initial reply')
                        ->icon('heroicon-o-chat-bubble-left-ellipsis')
                        ->color('success')
                        ->action(function (BookingInquiry $record, $livewire): void {
                            static::openWhatsApp(
                                $record,
                                $livewire,
                                'wa_initial',
                                [],
                                'Initial reply',
                            );
                        }),

                    Tables\Actions\Action::make('waOffer')
                        ->label('WA: Offer + payment')
                        ->icon('heroicon-o-currency-dollar')
                        ->color('success')
                        ->form([
                            Forms\Components\TextInput::make('price')
                                ->label('Total price for the group')
                                ->prefix('$')
                                ->required()
                                ->numeric()
                                ->step('0.01')
                                ->helperText('Enter the total, not per-person — e.g. 560 for 2 pax at $280.'),
                        ])
                        ->modalSubmitActionLabel('Open WhatsApp')
                        ->action(function (BookingInquiry $record, array $data, $livewire): void {
                            static::openWhatsApp(
                                $record,
                                $livewire,
                                'wa_offer_payment',
                                ['price' => '$' . number_format((float) $data['price'], 2)],
                                'Offer + payment (price: $' . $data['price'] . ')',
                            );
                        }),

                    Tables\Actions\Action::make('waPaymentLink')
                        ->label('WA: Payment link')
                        ->icon('heroicon-o-link')
                        ->color('success')
                        ->form([
                            Forms\Components\TextInput::make('link')
                                ->label('Secure payment link')
                                ->url()
                                ->required()
                                ->placeholder('https://pay.example.com/...'),
                        ])
                        ->modalSubmitActionLabel('Open WhatsApp')
                        ->action(function (BookingInquiry $record, array $data, $livewire): void {
                            static::openWhatsApp(
                                $record,
                                $livewire,
                                'wa_payment_link',
                                ['link' => $data['link']],
                                'Payment link sent',
                            );
                        }),
                ])
                    ->label('WhatsApp')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('success')
                    ->button(),

                Tables\Actions\Action::make('markContacted')
                    ->label('Mark contacted')
                    ->icon('heroicon-o-phone-arrow-up-right')
                    ->color('info')
                    ->visible(fn (BookingInquiry $record): bool => $record->status === BookingInquiry::STATUS_NEW
                        || $record->status === BookingInquiry::STATUS_AWAITING_CUSTOMER)
                    ->action(function (BookingInquiry $record): void {
                        $record->update([
                            'status'       => BookingInquiry::STATUS_CONTACTED,
                            'contacted_at' => $record->contacted_at ?: now(),
                        ]);
                        Notification::make()->title('Marked as contacted')->success()->send();
                    }),

                Tables\Actions\Action::make('markConfirmed')
                    ->label('Mark confirmed')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (BookingInquiry $record): bool => $record->status !== BookingInquiry::STATUS_CONFIRMED
                        && $record->status !== BookingInquiry::STATUS_SPAM)
                    ->requiresConfirmation()
                    ->action(function (BookingInquiry $record): void {
                        $record->update([
                            'status'       => BookingInquiry::STATUS_CONFIRMED,
                            'confirmed_at' => now(),
                        ]);
                        Notification::make()->title('Marked as confirmed')->success()->send();
                    }),

                Tables\Actions\Action::make('markCancelled')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->visible(fn (BookingInquiry $record): bool => $record->status !== BookingInquiry::STATUS_CANCELLED
                        && $record->status !== BookingInquiry::STATUS_SPAM)
                    ->requiresConfirmation()
                    ->action(function (BookingInquiry $record): void {
                        $record->update([
                            'status'       => BookingInquiry::STATUS_CANCELLED,
                            'cancelled_at' => now(),
                        ]);
                        Notification::make()->title('Marked as cancelled')->warning()->send();
                    }),

                Tables\Actions\Action::make('markSpam')
                    ->label('Spam')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (BookingInquiry $record): bool => $record->status !== BookingInquiry::STATUS_SPAM)
                    ->requiresConfirmation()
                    ->action(function (BookingInquiry $record): void {
                        $record->update(['status' => BookingInquiry::STATUS_SPAM]);
                        Notification::make()->title('Marked as spam')->danger()->send();
                    }),

                Tables\Actions\Action::make('addNote')
                    ->label('Note')
                    ->icon('heroicon-o-pencil-square')
                    ->color('gray')
                    ->form([
                        Forms\Components\Textarea::make('note')
                            ->label('Append to internal notes')
                            ->rows(4)
                            ->required(),
                    ])
                    ->action(function (BookingInquiry $record, array $data): void {
                        $existing = $record->internal_notes ? $record->internal_notes . "\n\n" : '';
                        $stamp    = now()->format('Y-m-d H:i');
                        $record->update([
                            'internal_notes' => $existing . "[{$stamp}] " . $data['note'],
                        ]);
                        Notification::make()->title('Note added')->success()->send();
                    }),

                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-inbox')
            ->emptyStateHeading('No inquiries yet')
            ->emptyStateDescription('Submissions from jahongir-travel.uz tour pages will appear here.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Inquiry')
                ->schema([
                    Infolists\Components\TextEntry::make('reference')
                        ->label('Reference')
                        ->copyable()
                        ->fontFamily('mono'),

                    Infolists\Components\TextEntry::make('status')
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            BookingInquiry::STATUS_NEW               => 'warning',
                            BookingInquiry::STATUS_CONTACTED         => 'info',
                            BookingInquiry::STATUS_AWAITING_CUSTOMER => 'primary',
                            BookingInquiry::STATUS_CONFIRMED         => 'success',
                            BookingInquiry::STATUS_CANCELLED         => 'gray',
                            BookingInquiry::STATUS_SPAM              => 'danger',
                            default                                  => 'gray',
                        }),

                    Infolists\Components\TextEntry::make('source')->badge()->color('gray'),
                    Infolists\Components\TextEntry::make('created_at')->label('Received at')->dateTime('M j, Y H:i'),
                ])
                ->columns(4),

            Infolists\Components\Section::make('Tour')
                ->schema([
                    Infolists\Components\TextEntry::make('tour_name_snapshot')->label('Tour name (as submitted)'),
                    Infolists\Components\TextEntry::make('tour_slug')->label('Slug')->placeholder('—'),
                    Infolists\Components\TextEntry::make('page_url')
                        ->label('Page')
                        ->url(fn ($state): ?string => $state ? (string) $state : null, true)
                        ->placeholder('—'),
                ])
                ->columns(1),

            Infolists\Components\Section::make('Customer')
                ->schema([
                    Infolists\Components\TextEntry::make('customer_name')->label('Name'),
                    Infolists\Components\TextEntry::make('customer_email')
                        ->label('Email')
                        ->copyable()
                        ->url(fn ($state): ?string => $state ? "mailto:{$state}" : null),
                    Infolists\Components\TextEntry::make('customer_phone')
                        ->label('Phone')
                        ->copyable()
                        ->url(fn ($state): ?string => $state
                            ? 'https://wa.me/' . preg_replace('/[^0-9]/', '', (string) $state)
                            : null, true),
                    Infolists\Components\TextEntry::make('preferred_contact')->label('Preferred contact')->placeholder('—'),
                ])
                ->columns(2),

            Infolists\Components\Section::make('Trip')
                ->schema([
                    Infolists\Components\TextEntry::make('people_adults')->label('Adults'),
                    Infolists\Components\TextEntry::make('people_children')->label('Children'),
                    Infolists\Components\TextEntry::make('travel_date')->label('Travel date')->date('M j, Y')->placeholder('—'),
                    Infolists\Components\IconEntry::make('flexible_dates')->label('Flexible')->boolean(),
                    Infolists\Components\TextEntry::make('message')->label('Message')->columnSpanFull()->placeholder('—'),
                ])
                ->columns(4),

            Infolists\Components\Section::make('Operator notes')
                ->schema([
                    Infolists\Components\TextEntry::make('internal_notes')
                        ->label('Internal notes')
                        ->placeholder('No notes yet')
                        ->columnSpanFull(),
                ])
                ->collapsible(),

            Infolists\Components\Section::make('Workflow timestamps')
                ->schema([
                    Infolists\Components\TextEntry::make('submitted_at')->dateTime('M j, Y H:i')->placeholder('—'),
                    Infolists\Components\TextEntry::make('contacted_at')->dateTime('M j, Y H:i')->placeholder('—'),
                    Infolists\Components\TextEntry::make('confirmed_at')->dateTime('M j, Y H:i')->placeholder('—'),
                    Infolists\Components\TextEntry::make('cancelled_at')->dateTime('M j, Y H:i')->placeholder('—'),
                ])
                ->columns(4)
                ->collapsible(),

            Infolists\Components\Section::make('Provenance')
                ->schema([
                    Infolists\Components\TextEntry::make('ip_address')->label('IP')->placeholder('—'),
                    Infolists\Components\TextEntry::make('user_agent')->label('User agent')->placeholder('—')->columnSpanFull(),
                ])
                ->columns(1)
                ->collapsed(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBookingInquiries::route('/'),
            'view'  => Pages\ViewBookingInquiry::route('/{record}'),
            'edit'  => Pages\EditBookingInquiry::route('/{record}/edit'),
        ];
    }

    /**
     * Build a wa.me deep link with a pre-rendered template, open it in a new
     * tab via Livewire JS, append an audit entry to internal_notes, and show
     * a confirmation notification.
     *
     * Keeps all three WA actions DRY. Fails soft on missing phone — the
     * operator gets a danger notification but the row is unchanged.
     *
     * @param  array<string, string|int|float|null>  $extras
     */
    protected static function openWhatsApp(
        BookingInquiry $record,
        mixed $livewire,
        string $templateKey,
        array $extras,
        string $auditLabel,
    ): void {
        $phoneDigits = preg_replace('/[^0-9]/', '', (string) $record->customer_phone);

        if ($phoneDigits === '' || $phoneDigits === null) {
            Notification::make()
                ->title('No phone number on record')
                ->body('Cannot open WhatsApp without a valid phone.')
                ->danger()
                ->send();

            return;
        }

        $text = app(InquiryTemplateRenderer::class)->render($templateKey, $record, $extras);
        $url  = 'https://wa.me/' . $phoneDigits . '?text=' . rawurlencode($text);

        // Append timestamped audit entry so operators see WA send history
        // in the detail page without needing a separate events table (Phase 5).
        $stamp    = now()->format('Y-m-d H:i');
        $existing = $record->internal_notes ? $record->internal_notes . "\n\n" : '';
        $record->update([
            'internal_notes' => $existing . "[{$stamp}] WA: {$auditLabel}",
        ]);

        // Open WhatsApp in a new tab — keeps the operator on the admin page.
        $livewire->js('window.open(' . json_encode($url) . ", '_blank');");

        Notification::make()
            ->title('WhatsApp opened')
            ->body('Message prefilled — review and send from WhatsApp.')
            ->success()
            ->send();
    }
}
