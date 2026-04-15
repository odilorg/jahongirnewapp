<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\BookingInquiryResource\Pages;
use App\Models\BookingInquiry;
use App\Services\InquiryTemplateRenderer;
use App\Services\OctoPaymentService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn\TextColumnSize;
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
                            BookingInquiry::STATUS_AWAITING_PAYMENT  => 'Awaiting payment',
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

            Forms\Components\Section::make('Operations')
                ->description('Tour dispatch — driver, guide, pickup details. Separate from the commercial status above.')
                ->schema([
                    Forms\Components\TextInput::make('assigned_driver_name')
                        ->label('Driver')
                        ->maxLength(191)
                        ->placeholder('e.g. Akmal — +998 90 123 45 67'),

                    Forms\Components\TextInput::make('assigned_guide_name')
                        ->label('Guide')
                        ->maxLength(191)
                        ->placeholder('e.g. Dilshod (EN)'),

                    Forms\Components\TimePicker::make('pickup_time')
                        ->label('Pickup time')
                        ->seconds(false),

                    Forms\Components\TextInput::make('pickup_point')
                        ->label('Pickup point')
                        ->maxLength(255)
                        ->placeholder('Hotel name or landmark'),

                    Forms\Components\Textarea::make('operational_notes')
                        ->rows(4)
                        ->columnSpanFull()
                        ->helperText('Driver brief, dietary needs, languages, special requests.'),

                    Forms\Components\Select::make('prep_status')
                        ->label('Prep status')
                        ->options([
                            BookingInquiry::PREP_NOT_PREPARED => 'Not prepared',
                            BookingInquiry::PREP_PREPARED     => 'Prepared',
                            BookingInquiry::PREP_DISPATCHED   => 'Dispatched',
                            BookingInquiry::PREP_COMPLETED    => 'Completed',
                        ])
                        ->placeholder('Not set')
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            // Row click → detail page. Removes the need for a row-level
            // View/Edit action button and makes the whole card tappable
            // on mobile, which is how operators actually use this.
            ->recordUrl(fn (BookingInquiry $record): string => static::getUrl('view', ['record' => $record]))
            ->columns([
                // Mobile-first card layout: on < md this stacks into a
                // single vertical card per row; on md+ it becomes a
                // conventional horizontal table row.
                Split::make([
                    Stack::make([
                        Tables\Columns\TextColumn::make('reference')
                            ->label('Ref')
                            ->fontFamily('mono')
                            ->size(TextColumnSize::Small)
                            ->color('gray')
                            ->searchable()
                            ->copyable()
                            ->copyMessage('Reference copied'),

                        Tables\Columns\TextColumn::make('tour_name_snapshot')
                            ->label('Tour')
                            ->weight(FontWeight::Medium)
                            ->searchable()
                            ->wrap(),

                        // Combined customer line: name (searchable) with
                        // pax + travel date in the description slot so the
                        // card stays compact but readable.
                        Tables\Columns\TextColumn::make('customer_name')
                            ->label('Customer')
                            ->searchable(['customer_name', 'customer_email', 'customer_phone'])
                            ->size(TextColumnSize::Small)
                            ->description(function (BookingInquiry $record): string {
                                $pax = $record->people_children > 0
                                    ? "{$record->people_adults}+{$record->people_children} pax"
                                    : ($record->people_adults === 1
                                        ? '1 pax'
                                        : "{$record->people_adults} pax");

                                $date = $record->travel_date
                                    ? $record->travel_date->format('M j, Y')
                                    : 'no date';

                                return $pax . ' · ' . $date;
                            }),
                    ]),

                    Stack::make([
                        Tables\Columns\TextColumn::make('status')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                BookingInquiry::STATUS_NEW               => 'New',
                                BookingInquiry::STATUS_CONTACTED         => 'Contacted',
                                BookingInquiry::STATUS_AWAITING_CUSTOMER => 'Awaiting',
                                BookingInquiry::STATUS_AWAITING_PAYMENT  => 'Awaiting pay',
                                BookingInquiry::STATUS_CONFIRMED         => 'Confirmed',
                                BookingInquiry::STATUS_CANCELLED         => 'Cancelled',
                                BookingInquiry::STATUS_SPAM              => 'Spam',
                                default                                  => $state,
                            })
                            ->color(fn (string $state): string => match ($state) {
                                BookingInquiry::STATUS_NEW               => 'warning',
                                BookingInquiry::STATUS_CONTACTED         => 'info',
                                BookingInquiry::STATUS_AWAITING_CUSTOMER => 'primary',
                                BookingInquiry::STATUS_AWAITING_PAYMENT  => 'warning',
                                BookingInquiry::STATUS_CONFIRMED         => 'success',
                                BookingInquiry::STATUS_CANCELLED         => 'gray',
                                BookingInquiry::STATUS_SPAM              => 'danger',
                                default                                  => 'gray',
                            }),

                        Tables\Columns\TextColumn::make('created_at')
                            ->since()
                            ->size(TextColumnSize::Small)
                            ->color('gray')
                            ->sortable(),
                    ])->alignment(Alignment::End),
                ])->from('md'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        BookingInquiry::STATUS_NEW               => 'New',
                        BookingInquiry::STATUS_CONTACTED         => 'Contacted',
                        BookingInquiry::STATUS_AWAITING_CUSTOMER => 'Awaiting customer',
                        BookingInquiry::STATUS_AWAITING_PAYMENT  => 'Awaiting payment',
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

                    // ── One-tap: generate Octo link AND open WhatsApp ──
                    // The daily-driver action for closing a sale. Replaces
                    // the two-step "Generate payment link" → "WA: Payment
                    // link" flow with a single modal. Link is still saved
                    // to the inquiry even if the operator cancels the
                    // WhatsApp tab, so nothing is lost.
                    Tables\Actions\Action::make('waGenerateAndSend')
                        ->label('WA: Generate & send payment')
                        ->icon('heroicon-o-credit-card')
                        ->color('success')
                        // Only visible when there is NO existing payment_link.
                        // Once a link exists, operators must use "Resend
                        // existing link" — regenerating a new one would
                        // orphan the old Octo transaction and break webhook
                        // lookup if the customer pays the first link.
                        ->visible(fn (BookingInquiry $record): bool => $record->status !== BookingInquiry::STATUS_CONFIRMED
                            && $record->status !== BookingInquiry::STATUS_SPAM
                            && $record->status !== BookingInquiry::STATUS_CANCELLED
                            && blank($record->payment_link))
                        ->form([
                            Forms\Components\TextInput::make('price')
                                ->label('Total price for group (USD)')
                                ->prefix('$')
                                ->required()
                                ->numeric()
                                ->step('0.01')
                                ->helperText('Generates Octo link, saves it to the inquiry, and opens WhatsApp prefilled with the message.'),
                        ])
                        ->modalSubmitActionLabel('Generate & open WhatsApp')
                        ->action(function (BookingInquiry $record, array $data, $livewire): void {
                            try {
                                $result = app(OctoPaymentService::class)
                                    ->createPaymentLinkForInquiry($record, (float) $data['price']);
                            } catch (\Throwable $e) {
                                Notification::make()
                                    ->title('Octo payment link failed')
                                    ->body(mb_substr($e->getMessage(), 0, 300))
                                    ->danger()
                                    ->persistent()
                                    ->send();

                                return;
                            }

                            $priceFormatted = '$' . number_format((float) $data['price'], 2);

                            $record->update([
                                'price_quoted'         => $data['price'],
                                'currency'             => 'USD',
                                'payment_method'       => BookingInquiry::PAYMENT_ONLINE,
                                'payment_link'         => $result['url'],
                                'payment_link_sent_at' => now(),
                                'octo_transaction_id'  => $result['transaction_id'],
                                'status'               => BookingInquiry::STATUS_AWAITING_PAYMENT,
                            ]);

                            static::openWhatsApp(
                                $record,
                                $livewire,
                                'wa_generate_and_send',
                                [
                                    'price' => $priceFormatted,
                                    'link'  => $result['url'],
                                ],
                                "Payment link generated & sent ({$priceFormatted})",
                            );
                        }),

                    Tables\Actions\Action::make('waPaymentLink')
                        ->label('WA: Resend existing link')
                        ->icon('heroicon-o-link')
                        ->color('success')
                        ->visible(fn (BookingInquiry $record): bool => filled($record->payment_link))
                        ->fillForm(fn (BookingInquiry $record): array => [
                            'link' => (string) $record->payment_link,
                        ])
                        ->form([
                            Forms\Components\TextInput::make('link')
                                ->label('Secure payment link')
                                ->url()
                                ->required()
                                ->helperText('Prefilled from the previously generated Octo link.'),
                        ])
                        ->modalSubmitActionLabel('Open WhatsApp')
                        ->action(function (BookingInquiry $record, array $data, $livewire): void {
                            static::openWhatsApp(
                                $record,
                                $livewire,
                                'wa_payment_link',
                                ['link' => $data['link']],
                                'Payment link re-sent',
                            );
                        }),
                ])
                    ->label('WhatsApp')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('success')
                    ->button(),

                // ── Everything else collapses into a single "⋮" dropdown.
                //    Keeps the row clean on mobile and puts WhatsApp as
                //    the dominant green button, which is the primary
                //    operator action for conversion.
                Tables\Actions\ActionGroup::make([
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

                    // ── Operations quick actions (prep lifecycle) ───────
                    // Separate from commercial status; only meaningful after
                    // sale is confirmed. The state machine is strict:
                    //   (null | not_prepared) → prepared → dispatched → completed
                    Tables\Actions\Action::make('markPrepared')
                        ->label('Mark prepared')
                        ->icon('heroicon-o-clipboard-document-check')
                        ->color('info')
                        ->visible(fn (BookingInquiry $record): bool => $record->status === BookingInquiry::STATUS_CONFIRMED
                            && ! in_array($record->prep_status, [
                                BookingInquiry::PREP_PREPARED,
                                BookingInquiry::PREP_DISPATCHED,
                                BookingInquiry::PREP_COMPLETED,
                            ], true))
                        ->action(function (BookingInquiry $record): void {
                            $record->update(['prep_status' => BookingInquiry::PREP_PREPARED]);
                            Notification::make()->title('Marked as prepared')->success()->send();
                        }),

                    Tables\Actions\Action::make('markDispatched')
                        ->label('Mark dispatched')
                        ->icon('heroicon-o-truck')
                        ->color('primary')
                        ->visible(fn (BookingInquiry $record): bool => $record->status === BookingInquiry::STATUS_CONFIRMED
                            && $record->prep_status === BookingInquiry::PREP_PREPARED)
                        ->action(function (BookingInquiry $record): void {
                            $record->update(['prep_status' => BookingInquiry::PREP_DISPATCHED]);
                            Notification::make()->title('Dispatched')->success()->send();
                        }),

                    Tables\Actions\Action::make('markCompleted')
                        ->label('Mark completed')
                        ->icon('heroicon-o-flag')
                        ->color('success')
                        ->visible(fn (BookingInquiry $record): bool => $record->status === BookingInquiry::STATUS_CONFIRMED
                            && in_array($record->prep_status, [
                                BookingInquiry::PREP_PREPARED,
                                BookingInquiry::PREP_DISPATCHED,
                            ], true))
                        ->requiresConfirmation()
                        ->action(function (BookingInquiry $record): void {
                            $record->update(['prep_status' => BookingInquiry::PREP_COMPLETED]);
                            Notification::make()->title('Tour completed 🎉')->success()->send();
                        }),

                    // ── Offline path: cash or card at the office ────────
                    Tables\Actions\Action::make('markPaidOffline')
                        ->label('Mark paid (cash / card)')
                        ->icon('heroicon-o-banknotes')
                        ->color('success')
                        ->visible(fn (BookingInquiry $record): bool => $record->status !== BookingInquiry::STATUS_CONFIRMED
                            && $record->status !== BookingInquiry::STATUS_SPAM
                            && $record->status !== BookingInquiry::STATUS_CANCELLED)
                        ->form([
                            Forms\Components\Radio::make('payment_method')
                                ->label('Payment method')
                                ->options([
                                    BookingInquiry::PAYMENT_CASH        => 'Cash in office',
                                    BookingInquiry::PAYMENT_CARD_OFFICE => 'Card in office',
                                ])
                                ->required()
                                ->inline(),
                            Forms\Components\TextInput::make('price')
                                ->label('Amount paid (USD)')
                                ->prefix('$')
                                ->numeric()
                                ->step('0.01')
                                ->helperText('Optional — leave blank if you only want to mark the status without recording an amount.'),
                        ])
                        ->modalSubmitActionLabel('Confirm payment')
                        ->action(function (BookingInquiry $record, array $data): void {
                            $updates = [
                                'status'         => BookingInquiry::STATUS_CONFIRMED,
                                'payment_method' => $data['payment_method'],
                                'paid_at'        => now(),
                                'confirmed_at'   => $record->confirmed_at ?: now(),
                            ];

                            if (filled($data['price'] ?? null)) {
                                $updates['price_quoted'] = $data['price'];
                                $updates['currency']     = 'USD';
                            }

                            $record->update($updates);

                            Notification::make()
                                ->title('Marked as paid')
                                ->body('Inquiry confirmed.')
                                ->success()
                                ->send();
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
                        ->label('Add note')
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
                    ->label('Actions')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->color('gray')
                    ->button(),
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
                            BookingInquiry::STATUS_AWAITING_PAYMENT  => 'warning',
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

            Infolists\Components\Section::make('Payment')
                ->schema([
                    Infolists\Components\TextEntry::make('price_quoted')
                        ->label('Quoted (USD)')
                        ->money('USD')
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('payment_method')
                        ->label('Method')
                        ->formatStateUsing(fn (?string $state): string => match ($state) {
                            BookingInquiry::PAYMENT_ONLINE      => 'Online (Octobank)',
                            BookingInquiry::PAYMENT_CASH        => 'Cash in office',
                            BookingInquiry::PAYMENT_CARD_OFFICE => 'Card in office',
                            default                             => '—',
                        }),
                    Infolists\Components\TextEntry::make('payment_link')
                        ->label('Payment link')
                        ->copyable()
                        ->url(fn ($state): ?string => $state ? (string) $state : null, true)
                        ->placeholder('—')
                        ->columnSpanFull(),
                    Infolists\Components\TextEntry::make('payment_link_sent_at')->label('Link generated')->dateTime('M j, Y H:i')->placeholder('—'),
                    Infolists\Components\TextEntry::make('paid_at')->label('Paid at')->dateTime('M j, Y H:i')->placeholder('—'),
                    Infolists\Components\TextEntry::make('octo_transaction_id')->label('Octo txn id')->copyable()->placeholder('—')->columnSpanFull(),
                ])
                ->columns(2),

            Infolists\Components\Section::make('Operations')
                ->schema([
                    Infolists\Components\TextEntry::make('prep_status')
                        ->label('Prep status')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => match ($state) {
                            BookingInquiry::PREP_NOT_PREPARED => 'Not prepared',
                            BookingInquiry::PREP_PREPARED     => 'Prepared',
                            BookingInquiry::PREP_DISPATCHED   => 'Dispatched',
                            BookingInquiry::PREP_COMPLETED    => 'Completed',
                            default                            => 'Not set',
                        })
                        ->color(fn (?string $state): string => match ($state) {
                            BookingInquiry::PREP_PREPARED   => 'info',
                            BookingInquiry::PREP_DISPATCHED => 'primary',
                            BookingInquiry::PREP_COMPLETED  => 'success',
                            default                         => 'gray',
                        }),
                    Infolists\Components\TextEntry::make('pickup_time')->label('Pickup time')->placeholder('—'),
                    Infolists\Components\TextEntry::make('pickup_point')->label('Pickup point')->placeholder('—'),
                    Infolists\Components\TextEntry::make('assigned_driver_name')->label('Driver')->placeholder('—'),
                    Infolists\Components\TextEntry::make('assigned_guide_name')->label('Guide')->placeholder('—'),
                    Infolists\Components\TextEntry::make('operational_notes')
                        ->label('Operational notes')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->columns(2),

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
