<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\BookingInquiryResource\Pages;
use App\Models\Accommodation;
use App\Models\BookingInquiry;
use App\Models\Driver;
use App\Models\Guide;
use App\Models\TourProduct;
use App\Services\DriverDispatchNotifier;
use App\Services\InquiryTemplateRenderer;
use App\Services\OctoPaymentService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Illuminate\Support\HtmlString;
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
    protected static ?string $navigationLabel = 'Bookings';
    protected static ?string $navigationGroup = 'Leads';
    protected static ?int    $navigationSort  = 10;
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
        // Form is shared between Edit and Create (manual entry from
        // WhatsApp / phone / walk-in). Sections are collapsible so the
        // edit experience stays scannable while the create experience
        // has every field within easy reach.
        return $form->schema([
            Forms\Components\Section::make('Source & customer')
                ->description('Where did this inquiry come from + who is the guest?')
                ->schema([
                    Forms\Components\Select::make('source')
                        ->options(BookingInquiry::SOURCE_LABELS)
                        ->default('whatsapp')
                        ->required()
                        ->native(false)
                        ->live(),

                    Forms\Components\TextInput::make('external_reference')
                        ->label('OTA booking reference')
                        ->placeholder('e.g. GYGX7NLH94W8')
                        ->maxLength(64)
                        ->visible(fn (Forms\Get $get): bool => in_array($get('source'), BookingInquiry::OTA_SOURCES)),

                    Forms\Components\TextInput::make('customer_name')
                        ->required()
                        ->maxLength(191)
                        ->placeholder('Lara Smith'),

                    Forms\Components\TextInput::make('customer_phone')
                        ->label('Phone')
                        ->tel()
                        ->required()
                        ->maxLength(64)
                        ->placeholder('+1 555 123 4567'),

                    Forms\Components\TextInput::make('customer_email')
                        ->label('Email')
                        ->email()
                        ->maxLength(191)
                        ->helperText('Optional for manual entries — required when guest submits via website.'),

                    Forms\Components\TextInput::make('customer_country')
                        ->label('Country')
                        ->maxLength(100)
                        ->placeholder('USA'),

                    Forms\Components\Select::make('preferred_contact')
                        ->options([
                            'email'    => 'Email',
                            'phone'    => 'Phone',
                            'whatsapp' => 'WhatsApp',
                            'telegram' => 'Telegram',
                        ])
                        ->placeholder('No preference')
                        ->native(false),
                ])
                ->columns(2)
                ->collapsible(),

            Forms\Components\Section::make('Trip')
                ->description('Tour, dates, group size, message from the guest.')
                ->schema([
                    // ── Tour catalog link (structure) ─────────────────
                    // Preferred path: operator picks a TourProduct from
                    // the catalog. Selecting one fills the snapshot
                    // fields below ONLY if they're currently empty, so
                    // historical inquiries never get their original
                    // "as submitted" title overwritten.
                    Forms\Components\Select::make('tour_product_id')
                        ->label('Tour product (catalog)')
                        ->relationship('tourProduct', 'title', fn ($query) => $query->where('is_active', true)->orderBy('title'))
                        ->searchable(['title', 'slug'])
                        ->preload()
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set, callable $get): void {
                            // Reset direction whenever product changes — a direction
                            // from the old product doesn't belong on the new one.
                            $set('tour_product_direction_id', null);

                            if (! $state) {
                                return;
                            }

                            $product = TourProduct::find($state);
                            if (! $product) {
                                return;
                            }

                            // Only auto-fill snapshots when blank. Never
                            // clobber an existing historical snapshot.
                            if (blank($get('tour_slug'))) {
                                $set('tour_slug', $product->slug);
                            }
                            if (blank($get('tour_name_snapshot'))) {
                                $set('tour_name_snapshot', $product->title);
                            }
                            if (blank($get('tour_type'))) {
                                $set('tour_type', $product->tour_type);
                            }
                        })
                        ->placeholder('— not in catalog / free-text only —')
                        ->helperText('Catalog link. Leave empty for tours not yet catalogued.')
                        ->columnSpanFull(),

                    Forms\Components\Select::make('tour_product_direction_id')
                        ->label('Direction (route variant)')
                        ->options(function (callable $get): array {
                            $productId = $get('tour_product_id');
                            if (! $productId) {
                                return [];
                            }

                            return TourProduct::find($productId)
                                ?->directions()
                                ->where('is_active', true)
                                ->orderBy('sort_order')
                                ->get()
                                ->mapWithKeys(fn ($d) => [$d->id => "{$d->name} ({$d->code})"])
                                ->all() ?? [];
                        })
                        ->disabled(fn (callable $get): bool => blank($get('tour_product_id')))
                        ->placeholder('— all directions / unspecified —')
                        ->helperText('Only populated when a tour product is selected.')
                        ->native(false),

                    Forms\Components\Select::make('tour_type')
                        ->label('Type')
                        ->options([
                            BookingInquiry::TOUR_TYPE_PRIVATE => 'Private',
                            BookingInquiry::TOUR_TYPE_GROUP   => 'Group',
                        ])
                        ->placeholder('— unspecified —')
                        ->native(false)
                        ->helperText('Affects which price tier applies when a quote is calculated.'),

                    // ── Snapshot fields (historical truth) ────────────
                    // These are the values as they appeared at the time
                    // the inquiry was captured. They are preserved even
                    // if the catalog tour is renamed/re-slugged later.
                    Forms\Components\TextInput::make('tour_name_snapshot')
                        ->label('Tour name (as submitted)')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('Nuratau Homestay 3 Days 2 Nights')
                        ->helperText('Historical snapshot — preserved for audit. Not overwritten by catalog changes.')
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('tour_slug')
                        ->label('Tour slug (as submitted)')
                        ->maxLength(191)
                        ->placeholder('nuratau-homestay-3-days')
                        ->helperText('Historical snapshot. Auto-filled from catalog when the product field is set and this is blank.'),

                    Forms\Components\TextInput::make('page_url')
                        ->label('Source URL')
                        ->url()
                        ->maxLength(500)
                        ->placeholder('https://jahongir-travel.uz/...'),

                    Forms\Components\TextInput::make('people_adults')
                        ->label('Adults')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->default(1),

                    Forms\Components\TextInput::make('people_children')
                        ->label('Children')
                        ->numeric()
                        ->minValue(0)
                        ->default(0),

                    Forms\Components\DatePicker::make('travel_date')
                        ->label('Travel date'),

                    Forms\Components\Toggle::make('flexible_dates')
                        ->label('Flexible dates'),

                    Forms\Components\Textarea::make('message')
                        ->label('Customer message / notes')
                        ->rows(3)
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->collapsible(),

            Forms\Components\Section::make('Pricing')
                ->description('Price quoted to the guest. OTA commission auto-calculates from source config.')
                ->collapsible()
                ->schema([
                    Forms\Components\TextInput::make('price_quoted')
                        ->label('Price quoted')
                        ->prefix('$')
                        ->numeric()
                        ->step('0.01')
                        ->helperText('Gross amount charged to the guest (before OTA commission).'),

                    Forms\Components\TextInput::make('currency')
                        ->default('USD')
                        ->maxLength(3),

                    Forms\Components\TextInput::make('commission_rate')
                        ->label('OTA commission %')
                        ->numeric()
                        ->suffix('%')
                        ->helperText('Auto-set from config for GYG/Viator. Manual entry otherwise.'),

                    Forms\Components\TextInput::make('commission_amount')
                        ->label('Commission amount')
                        ->prefix('$')
                        ->numeric()
                        ->step('0.01'),

                    Forms\Components\TextInput::make('net_revenue')
                        ->label('Net revenue override')
                        ->prefix('$')
                        ->numeric()
                        ->step('0.01')
                        ->helperText('Leave blank to auto-compute as price_quoted - commission.'),
                ])
                ->columns(2),

            Forms\Components\Section::make('Operator')
                ->description('Internal fields — not visible to the customer.')
                ->collapsible()
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
                    // Driver / Guide pull from the normalised drivers/guides
                    // catalog tables. createOptionForm lets operators add a
                    // new supplier inline without leaving the inquiry detail.
                    Forms\Components\Select::make('driver_id')
                        ->label('Driver')
                        ->relationship('driver', 'full_name')
                        ->searchable(['first_name', 'last_name', 'phone01'])
                        ->preload()
                        ->live()
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('driver_rate_id', null))
                        ->createOptionForm([
                            Forms\Components\TextInput::make('first_name')->required()->maxLength(255),
                            Forms\Components\TextInput::make('last_name')->required()->maxLength(255),
                            Forms\Components\TextInput::make('phone01')->label('Phone')->tel()->required()->maxLength(255),
                            Forms\Components\TextInput::make('email')->email()->required()->maxLength(255),
                            Forms\Components\Select::make('fuel_type')
                                ->options(['petrol' => 'Petrol', 'diesel' => 'Diesel', 'methane' => 'Methane', 'propane' => 'Propane', 'hybrid' => 'Hybrid', 'electric' => 'Electric'])
                                ->default('petrol')
                                ->required(),
                            Forms\Components\Toggle::make('is_active')->default(true),
                        ])
                        ->createOptionUsing(function (array $data): int {
                            return Driver::create($data)->id;
                        }),

                    Forms\Components\Select::make('driver_rate_id')
                        ->label('Driver rate')
                        ->options(function (Forms\Get $get): array {
                            $driverId = $get('driver_id');
                            if (! $driverId) {
                                return [];
                            }

                            return \App\Models\DriverRate::where('driver_id', $driverId)
                                ->where('is_active', true)
                                ->orderBy('sort_order')
                                ->orderBy('label')
                                ->get()
                                ->mapWithKeys(fn ($r) => [
                                    $r->id => "{$r->label} — \${$r->cost_usd} ({$r->rate_type})",
                                ])
                                ->all();
                        })
                        ->live()
                        ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, ?string $state): void {
                            if (! $state || $get('driver_cost_override')) {
                                return;
                            }
                            $rate = \App\Models\DriverRate::find($state);
                            if ($rate) {
                                $set('driver_cost', (string) $rate->cost_usd);
                            }
                        })
                        ->visible(fn (Forms\Get $get): bool => filled($get('driver_id')))
                        ->placeholder('Select rate...'),

                    Forms\Components\TextInput::make('driver_cost')
                        ->label('Driver cost ($)')
                        ->prefix('$')
                        ->numeric()
                        ->step('0.01')
                        ->visible(fn (Forms\Get $get): bool => filled($get('driver_id'))),

                    Forms\Components\Toggle::make('driver_cost_override')
                        ->label('Override driver cost')
                        ->live()
                        ->inline()
                        ->visible(fn (Forms\Get $get): bool => filled($get('driver_id'))),

                    Forms\Components\TextInput::make('driver_cost_override_reason')
                        ->label('Override reason')
                        ->placeholder('Extra stops, negotiated rate, etc.')
                        ->maxLength(255)
                        ->visible(fn (Forms\Get $get): bool => (bool) $get('driver_cost_override'))
                        ->required(fn (Forms\Get $get): bool => (bool) $get('driver_cost_override')),

                    Forms\Components\Select::make('guide_id')
                        ->label('Guide')
                        ->relationship('guide', 'full_name')
                        ->searchable(['first_name', 'last_name', 'phone01'])
                        ->preload()
                        ->live()
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('guide_rate_id', null))
                        ->createOptionForm([
                            Forms\Components\TextInput::make('first_name')->required()->maxLength(255),
                            Forms\Components\TextInput::make('last_name')->required()->maxLength(255),
                            Forms\Components\TextInput::make('phone01')->label('Phone')->tel()->required()->maxLength(255),
                            Forms\Components\TextInput::make('email')->email()->required()->maxLength(255),
                            Forms\Components\Toggle::make('is_active')->default(true),
                        ])
                        ->createOptionUsing(function (array $data): int {
                            return Guide::create($data)->id;
                        }),

                    Forms\Components\Select::make('guide_rate_id')
                        ->label('Guide rate')
                        ->options(function (Forms\Get $get): array {
                            $guideId = $get('guide_id');
                            if (! $guideId) {
                                return [];
                            }

                            return \App\Models\GuideRate::where('guide_id', $guideId)
                                ->where('is_active', true)
                                ->orderBy('sort_order')
                                ->orderBy('label')
                                ->get()
                                ->mapWithKeys(fn ($r) => [
                                    $r->id => "{$r->label} — \${$r->cost_usd} ({$r->rate_type})",
                                ])
                                ->all();
                        })
                        ->live()
                        ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, ?string $state): void {
                            if (! $state || $get('guide_cost_override')) {
                                return;
                            }
                            $rate = \App\Models\GuideRate::find($state);
                            if ($rate) {
                                $set('guide_cost', (string) $rate->cost_usd);
                            }
                        })
                        ->visible(fn (Forms\Get $get): bool => filled($get('guide_id')))
                        ->placeholder('Select rate...'),

                    Forms\Components\TextInput::make('guide_cost')
                        ->label('Guide cost ($)')
                        ->prefix('$')
                        ->numeric()
                        ->step('0.01')
                        ->visible(fn (Forms\Get $get): bool => filled($get('guide_id'))),

                    Forms\Components\Toggle::make('guide_cost_override')
                        ->label('Override guide cost')
                        ->live()
                        ->inline()
                        ->visible(fn (Forms\Get $get): bool => filled($get('guide_id'))),

                    Forms\Components\TextInput::make('guide_cost_override_reason')
                        ->label('Override reason')
                        ->placeholder('Special language, negotiated rate, etc.')
                        ->maxLength(255)
                        ->visible(fn (Forms\Get $get): bool => (bool) $get('guide_cost_override'))
                        ->required(fn (Forms\Get $get): bool => (bool) $get('guide_cost_override')),

                    Forms\Components\TimePicker::make('pickup_time')
                        ->label('Pickup time')
                        ->seconds(false),

                    Forms\Components\TextInput::make('pickup_point')
                        ->label('Pickup point')
                        ->maxLength(255)
                        ->placeholder('Hotel name or landmark'),

                    Forms\Components\TextInput::make('dropoff_point')
                        ->label('Dropoff point')
                        ->maxLength(255)
                        ->placeholder('End of tour handoff location'),

                    Forms\Components\TextInput::make('customer_country')
                        ->label('Guest country')
                        ->maxLength(100)
                        ->placeholder('e.g. France, USA'),

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

            // ── Tour Costs ──────────────────────────────────────────
            Forms\Components\Section::make('Tour Costs')
                ->description('Other costs and margin overview. Driver + guide costs are in Operations above.')
                ->schema([
                    Forms\Components\TextInput::make('other_costs')
                        ->label('Other costs ($)')
                        ->prefix('$')
                        ->numeric()
                        ->step('0.01')
                        ->helperText('Entrance fees, meals, activities, etc.'),

                    Forms\Components\Textarea::make('cost_notes')
                        ->label('Cost notes')
                        ->rows(2)
                        ->placeholder('Breakdown of other costs, special arrangements')
                        ->columnSpanFull(),

                    Forms\Components\Placeholder::make('margin_summary')
                        ->label('Margin')
                        ->content(function (Forms\Get $get, $record): string {
                            $gross      = (float) ($get('price_quoted') ?: 0);
                            $commission = (float) ($get('commission_amount') ?: 0);
                            $netRevenue = $commission > 0 ? $gross - $commission : $gross;

                            $driver  = (float) ($get('driver_cost') ?: 0);
                            $guide   = (float) ($get('guide_cost') ?: 0);
                            $other   = (float) ($get('other_costs') ?: 0);
                            $accCost = (float) ($record?->stays?->sum('total_accommodation_cost') ?? 0);

                            $totalCost = $driver + $guide + $accCost + $other;
                            $margin    = $netRevenue - $totalCost;
                            $pct       = $netRevenue > 0 ? round($margin / $netRevenue * 100) : 0;

                            if ($gross <= 0) {
                                return '—';
                            }

                            $parts = [];
                            if ($commission > 0) {
                                $parts[] = sprintf('Gross $%.2f − Commission $%.2f = Net $%.2f', $gross, $commission, $netRevenue);
                            }
                            $parts[] = sprintf(
                                'Net $%.2f − Costs $%.2f (acc $%.2f + driver $%.2f + guide $%.2f + other $%.2f) = **$%.2f margin (%d%%)**',
                                $netRevenue, $totalCost, $accCost, $driver, $guide, $other, $margin, $pct
                            );

                            return implode("\n", $parts);
                        })
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->collapsible()
                ->collapsed(),

            // Multi-stay accommodation assignment. A Nuratau 3-day tour
            // typically has yurt night + homestay night, so we use a
            // Repeater bound to the InquiryStay relation rather than a
            // single accommodation_id field.
            Forms\Components\Section::make('Lodging (stays)')
                ->description('Add one row per night/leg. e.g. yurt camp night 1 + village homestay night 2.')
                ->schema([
                    Forms\Components\Repeater::make('stays')
                        ->relationship()
                        ->schema([
                            Forms\Components\Select::make('accommodation_id')
                                ->label('Accommodation')
                                ->relationship('accommodation', 'name')
                                ->searchable(['name', 'location', 'contact_name'])
                                ->preload()
                                ->required()
                                ->live()
                                ->createOptionForm([
                                    Forms\Components\TextInput::make('name')->required()->maxLength(191),
                                    Forms\Components\Select::make('type')->options([
                                        'yurt' => 'Yurt camp', 'homestay' => 'Homestay',
                                        'hotel' => 'Hotel', 'guesthouse' => 'Guesthouse',
                                    ])->native(false),
                                    Forms\Components\TextInput::make('location')->maxLength(191),
                                    Forms\Components\TextInput::make('contact_name')->label('Manager')->maxLength(191),
                                    Forms\Components\TextInput::make('phone_primary')->label('Phone')->tel()->required()->maxLength(64),
                                    Forms\Components\Toggle::make('is_active')->default(true),
                                ])
                                ->createOptionUsing(function (array $data): int {
                                    return Accommodation::create($data)->id;
                                }),

                            Forms\Components\TextInput::make('sort_order')
                                ->numeric()
                                ->default(0)
                                ->label('#'),

                            Forms\Components\DatePicker::make('stay_date')
                                ->label('Check-in'),

                            Forms\Components\TextInput::make('nights')
                                ->numeric()
                                ->minValue(1)
                                ->default(1)
                                ->live(onBlur: true),

                            Forms\Components\TextInput::make('guest_count')
                                ->numeric()
                                ->placeholder('defaults to inquiry pax')
                                ->live(onBlur: true),

                            Forms\Components\TextInput::make('meal_plan')
                                ->placeholder('B+D, HB, FB, etc.')
                                ->maxLength(100),

                            // ── Cost auto-calculation ──────────────────
                            Forms\Components\Placeholder::make('computed_cost_display')
                                ->label('Auto cost')
                                ->content(function (Forms\Get $get): string {
                                    $accId  = $get('accommodation_id');
                                    $guests = (int) ($get('guest_count') ?: 0);
                                    $nights = (int) ($get('nights') ?: 1);

                                    if (! $accId || $guests < 1) {
                                        return '—';
                                    }

                                    $acc  = \App\Models\Accommodation::find($accId);
                                    $rate = $acc?->costForGuests($guests);

                                    if (! $rate) {
                                        return 'No rate for ' . $guests . ' guests';
                                    }

                                    $total = $rate->cost_usd * $guests * $nights;

                                    return "\${$rate->cost_usd}/person × {$guests} guests × {$nights} night(s) = \${$total}";
                                }),

                            Forms\Components\TextInput::make('total_accommodation_cost')
                                ->label('Accommodation cost ($)')
                                ->prefix('$')
                                ->numeric()
                                ->step('0.01')
                                ->live()
                                ->afterStateHydrated(function (Forms\Components\TextInput $component, Forms\Get $get, ?string $state): void {
                                    // Auto-fill on load if not already set and not overridden
                                    if ($state !== null && $state !== '') {
                                        return;
                                    }
                                    if ($get('cost_override')) {
                                        return;
                                    }
                                    $accId  = $get('accommodation_id');
                                    $guests = (int) ($get('guest_count') ?: 0);
                                    $nights = (int) ($get('nights') ?: 1);
                                    if (! $accId || $guests < 1) {
                                        return;
                                    }
                                    $acc  = \App\Models\Accommodation::find($accId);
                                    $rate = $acc?->costForGuests($guests);
                                    if ($rate) {
                                        $component->state((string) ($rate->cost_usd * $guests * $nights));
                                    }
                                }),

                            Forms\Components\Toggle::make('cost_override')
                                ->label('Override cost')
                                ->helperText('Enable to manually set a different cost')
                                ->live()
                                ->inline(),

                            Forms\Components\TextInput::make('cost_override_reason')
                                ->label('Override reason')
                                ->placeholder('Negotiated rate, invoice difference, etc.')
                                ->maxLength(255)
                                ->visible(fn (Forms\Get $get): bool => (bool) $get('cost_override'))
                                ->required(fn (Forms\Get $get): bool => (bool) $get('cost_override')),

                            Forms\Components\Textarea::make('notes')
                                ->rows(2)
                                ->columnSpanFull(),
                        ])
                        ->columns(3)
                        ->orderColumn('sort_order')
                        ->reorderable()
                        ->collapsible()
                        ->cloneable()
                        ->itemLabel(fn (array $state): ?string => filled($state['accommodation_id'] ?? null)
                            ? (\App\Models\Accommodation::find($state['accommodation_id'])?->name . ' (#' . ($state['sort_order'] ?? '?') . ')')
                            : 'New stay')
                        ->addActionLabel('+ Add stay')
                        ->defaultItems(0),
                ])
                ->collapsible(),
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
                    ->options(BookingInquiry::SOURCE_LABELS)
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
                        // Prefill from price_quoted (Phase 8.3a) so a catalog-calculated
                        // quote flows straight through to Octo without retyping.
                        // Operator can still override in the modal.
                        ->fillForm(fn (BookingInquiry $record): array => [
                            'price' => $record->price_quoted ? (string) $record->price_quoted : '',
                        ])
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

                    // ── Phase 8.3a: Calculate quote from tour catalog ──
                    //
                    // Strict rules (per handoff):
                    //  - Modal ONLY opens on successful tier resolution.
                    //  - No silent default for tour_type — operator must
                    //    have set it on the inquiry first.
                    //  - No "apply without a matched tier" path — if
                    //    priceFor() returns null, we fire a danger toast
                    //    and cancel the modal mount so there is a hard
                    //    separation between "calculated from catalog"
                    //    and "manual override".
                    //  - Audit note explicitly records exact-vs-fallback
                    //    so later debugging/trust is easy.
                    Tables\Actions\Action::make('calculateQuote')
                        ->label('Calculate quote')
                        ->icon('heroicon-o-calculator')
                        ->color('success')
                        ->visible(fn (BookingInquiry $record): bool => $record->tour_product_id !== null
                            && $record->status !== BookingInquiry::STATUS_SPAM
                            && $record->status !== BookingInquiry::STATUS_CANCELLED)
                        ->mountUsing(function (Tables\Actions\Action $action, BookingInquiry $record): void {
                            $ctx = static::resolveQuoteContext($record);

                            if (! $ctx['ok']) {
                                Notification::make()
                                    ->title('Cannot calculate quote')
                                    ->body($ctx['error'])
                                    ->danger()
                                    ->persistent()
                                    ->send();

                                $action->halt();
                            }
                        })
                        ->fillForm(function (BookingInquiry $record): array {
                            $ctx = static::resolveQuoteContext($record);

                            return [
                                'price' => $ctx['ok'] ? (string) $ctx['total'] : '',
                            ];
                        })
                        ->form([
                            Forms\Components\Placeholder::make('quote_preview')
                                ->label('Matched tier')
                                ->content(function (BookingInquiry $record): HtmlString {
                                    $ctx = static::resolveQuoteContext($record);

                                    if (! $ctx['ok']) {
                                        return new HtmlString(
                                            '<span style="color:#dc2626;">⚠ ' . e($ctx['error']) . '</span>'
                                        );
                                    }

                                    $tier = $ctx['tier'];
                                    $kind = $ctx['is_fallback']
                                        ? '<span style="color:#d97706;">fallback</span>'
                                        : '<span style="color:#059669;">exact match</span>';

                                    $lines = [
                                        '<strong>' . e($record->tourProduct->title) . '</strong>',
                                        'Direction: <code>' . e($ctx['direction_code'] ?? '(global)') . '</code>',
                                        'Type: <code>' . e($record->tour_type) . '</code>',
                                        'Pax: ' . $record->people_adults . ' adults + ' . (int) $record->people_children . ' children = <strong>' . $ctx['pax'] . ' total</strong>',
                                        '',
                                        'Tier: <strong>group_size=' . $tier->group_size . ' @ $' . number_format((float) $tier->price_per_person_usd, 2) . '/person</strong> ' . $kind,
                                        'Computed total: <strong>$' . number_format($ctx['total'], 2) . '</strong>',
                                    ];

                                    return new HtmlString(implode('<br>', $lines));
                                })
                                ->columnSpanFull(),

                            Forms\Components\TextInput::make('price')
                                ->label('Quote (USD)')
                                ->prefix('$')
                                ->numeric()
                                ->step('0.01')
                                ->minValue(0)
                                ->required()
                                ->helperText('Prefilled from the matched tier. You can override before applying.'),
                        ])
                        ->modalHeading('Calculate quote from tour rates')
                        ->modalSubmitActionLabel('Apply quote')
                        ->action(function (BookingInquiry $record, array $data): void {
                            // Re-resolve at apply time so the audit note
                            // reflects the state we actually wrote against,
                            // not what was shown when the modal opened.
                            $ctx = static::resolveQuoteContext($record);

                            if (! $ctx['ok']) {
                                Notification::make()
                                    ->title('Cannot apply quote')
                                    ->body($ctx['error'])
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $tier = $ctx['tier'];
                            $finalPrice = (float) $data['price'];
                            $overrode = abs($finalPrice - (float) $ctx['total']) > 0.005;

                            $record->update([
                                'price_quoted' => $finalPrice,
                                'currency'     => $record->currency ?? 'USD',
                            ]);

                            $stamp = now()->format('Y-m-d H:i');
                            $note = sprintf(
                                '[%s] Quote calculated from catalog: product=%s dir=%s type=%s pax=%d → %s group_size=%d @ $%s → total $%s%s',
                                $stamp,
                                $record->tourProduct->slug,
                                $ctx['direction_code'] ?? '(global)',
                                $record->tour_type,
                                $ctx['pax'],
                                $ctx['is_fallback'] ? 'fallback tier' : 'exact tier',
                                $tier->group_size,
                                number_format((float) $tier->price_per_person_usd, 2),
                                number_format($finalPrice, 2),
                                $overrode ? ' (operator override from $' . number_format($ctx['total'], 2) . ')' : '',
                            );

                            $existing = $record->internal_notes ? $record->internal_notes . "\n\n" : '';
                            $record->update([
                                'internal_notes' => $existing . $note,
                            ]);

                            Notification::make()
                                ->title('Quote applied')
                                ->body('price_quoted = $' . number_format($finalPrice, 2) . ($overrode ? ' (overridden)' : ''))
                                ->success()
                                ->send();
                        }),

                    // ── Operations quick actions (prep lifecycle) ───────
                    // Separate from commercial status; only meaningful after
                    // sale is confirmed. The state machine is strict:
                    //   (null | not_prepared) → prepared → dispatched → completed
                    // ── Dispatch driver via Telegram DM (tg-direct) ─────
                    // Sends an Uzbek dispatch brief to the assigned
                    // driver's phone number using Odil's personal Telethon
                    // session via the autossh tunnel to vps-main. Driver
                    // does not need to have started any bot — raw phone
                    // number works.
                    //
                    // Safety: confirmation modal shows the destination phone
                    // so the operator visually verifies before send. A
                    // warning is displayed if pickup_time or pickup_point
                    // are empty, but the send is not blocked — operator
                    // decides whether to proceed with partial info.
                    Tables\Actions\Action::make('dispatchDriver')
                        ->label('Dispatch via Telegram')
                        ->icon('heroicon-o-paper-airplane')
                        ->color('primary')
                        ->visible(fn (BookingInquiry $record): bool => ($record->driver_id !== null
                            || $record->guide_id !== null
                            || $record->stays()->exists())
                            && $record->isDispatchable())
                        ->requiresConfirmation()
                        ->modalHeading('Send dispatch via Telegram')
                        ->modalDescription(function (BookingInquiry $record): string {
                            $record->loadMissing(['driver', 'guide', 'stays.accommodation']);

                            $recipients = [];
                            if ($record->driver) {
                                $recipients[] = '🚐 Driver: ' . $record->driver->full_name . ' · ' . ($record->driver->phone01 ?: 'no phone');
                            }
                            if ($record->guide) {
                                $recipients[] = '🧑‍✈️ Guide: ' . $record->guide->full_name . ' · ' . ($record->guide->phone01 ?: 'no phone');
                            }
                            foreach ($record->stays as $stay) {
                                if (! $stay->accommodation) {
                                    continue;
                                }
                                $night = $stay->stay_date ? $stay->stay_date->format('M j') : '?';
                                $recipients[] = '🏕 Stay #' . $stay->sort_order . ' ' . $night
                                    . ': ' . $stay->accommodation->name
                                    . ' · ' . ($stay->accommodation->phone_primary ?: 'no phone');
                            }

                            $desc = "Recipients:\n" . implode("\n", $recipients);

                            $warnings = [];
                            if (blank($record->pickup_time)) {
                                $warnings[] = '⚠️ Pickup time is empty';
                            }
                            if (blank($record->pickup_point)) {
                                $warnings[] = '⚠️ Pickup point is empty';
                            }

                            if ($warnings !== []) {
                                $desc .= "\n\n" . implode("\n", $warnings)
                                    . "\n\nYou can still send — placeholders will show '—' where info is missing.";
                            }

                            return $desc;
                        })
                        ->modalSubmitActionLabel('Send dispatch')
                        ->action(function (BookingInquiry $record): void {
                            $results = app(DriverDispatchNotifier::class)->dispatchAssigned($record);

                            $stamp     = now()->format('Y-m-d H:i');
                            $auditLines = [];
                            $okCount    = 0;
                            $failCount  = 0;

                            foreach (['driver', 'guide'] as $role) {
                                $r = $results[$role] ?? null;
                                if ($r === null) {
                                    continue; // not assigned, skip
                                }

                                $supplier = $role === 'driver' ? $record->driver : $record->guide;
                                $name     = $supplier?->full_name ?? $role;

                                if ($r['ok']) {
                                    $auditLines[] = "TG → {$role} {$name} ok (msg_id={$r['msg_id']})";
                                    $okCount++;
                                } else {
                                    $auditLines[] = "⚠️ TG → {$role} {$name} FAILED: " . ($r['reason'] ?? 'unknown');
                                    $failCount++;
                                }
                            }

                            foreach ($results['stays'] ?? [] as $sr) {
                                if ($sr['ok']) {
                                    $auditLines[] = "TG → stay {$sr['accommodation']} ok (msg_id={$sr['msg_id']})";
                                    $okCount++;
                                } else {
                                    $auditLines[] = "⚠️ TG → stay {$sr['accommodation']} FAILED: " . ($sr['reason'] ?? 'unknown');
                                    $failCount++;
                                }
                            }

                            $existing = $record->internal_notes ? $record->internal_notes . "\n\n" : '';
                            $record->update([
                                'internal_notes' => $existing . "[{$stamp}] " . implode("\n[{$stamp}] ", $auditLines),
                            ]);

                            if ($failCount === 0 && $okCount > 0) {
                                Notification::make()
                                    ->title('Dispatch sent')
                                    ->body("Sent to {$okCount} recipient(s) via Telegram.")
                                    ->success()
                                    ->send();
                            } elseif ($okCount > 0) {
                                Notification::make()
                                    ->title('Partial dispatch')
                                    ->body("{$okCount} sent, {$failCount} failed. Check operator notes for details.")
                                    ->warning()
                                    ->persistent()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('Dispatch failed')
                                    ->body('No recipients were reached. Check operator notes / laravel.log.')
                                    ->danger()
                                    ->persistent()
                                    ->send();
                            }
                        }),

                    Tables\Actions\Action::make('redispatchDriver')
                        ->label('Re-dispatch driver only')
                        ->icon('heroicon-o-truck')
                        ->color('gray')
                        ->visible(fn (BookingInquiry $record): bool => $record->driver_id !== null
                            && $record->isDispatchable())
                        ->requiresConfirmation()
                        ->modalDescription(fn (BookingInquiry $record): string => 'Send dispatch to: 🚐 '
                            . ($record->driver?->full_name ?? '—')
                            . ' via ' . ($record->driver?->telegram_chat_id ?: $record->driver?->phone01 ?: 'no contact'))
                        ->action(function (BookingInquiry $record): void {
                            $result = app(DriverDispatchNotifier::class)->dispatchSupplier($record, 'driver');
                            $stamp  = now()->format('Y-m-d H:i');
                            $name   = $record->driver?->full_name ?? 'driver';

                            $existing = $record->internal_notes ? $record->internal_notes . "\n" : '';
                            if ($result['ok']) {
                                $record->update([
                                    'internal_notes' => $existing . "[{$stamp}] Re-dispatch TG → driver {$name} ok (msg_id={$result['msg_id']})",
                                ]);
                                Notification::make()->title('Driver dispatch sent')->success()->send();
                            } else {
                                $reason = $result['reason'] ?? 'unknown';
                                $record->update([
                                    'internal_notes' => $existing . "[{$stamp}] ⚠️ Re-dispatch TG → driver {$name} FAILED: {$reason}",
                                ]);
                                Notification::make()->title('Driver dispatch failed')->body($reason)->danger()->persistent()->send();
                            }
                        }),

                    Tables\Actions\Action::make('redispatchGuide')
                        ->label('Re-dispatch guide only')
                        ->icon('heroicon-o-academic-cap')
                        ->color('gray')
                        ->visible(fn (BookingInquiry $record): bool => $record->guide_id !== null
                            && $record->isDispatchable())
                        ->requiresConfirmation()
                        ->modalDescription(fn (BookingInquiry $record): string => 'Send dispatch to: 🧭 '
                            . ($record->guide?->full_name ?? '—')
                            . ' via ' . ($record->guide?->telegram_chat_id ?: $record->guide?->phone01 ?: 'no contact'))
                        ->action(function (BookingInquiry $record): void {
                            $result = app(DriverDispatchNotifier::class)->dispatchSupplier($record, 'guide');
                            $stamp  = now()->format('Y-m-d H:i');
                            $name   = $record->guide?->full_name ?? 'guide';

                            $existing = $record->internal_notes ? $record->internal_notes . "\n" : '';
                            if ($result['ok']) {
                                $record->update([
                                    'internal_notes' => $existing . "[{$stamp}] Re-dispatch TG → guide {$name} ok (msg_id={$result['msg_id']})",
                                ]);
                                Notification::make()->title('Guide dispatch sent')->success()->send();
                            } else {
                                $reason = $result['reason'] ?? 'unknown';
                                $record->update([
                                    'internal_notes' => $existing . "[{$stamp}] ⚠️ Re-dispatch TG → guide {$name} FAILED: {$reason}",
                                ]);
                                Notification::make()->title('Guide dispatch failed')->body($reason)->danger()->persistent()->send();
                            }
                        }),

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
                        // Prefill the amount from price_quoted (Phase 8.3a)
                        // so cash/card confirmations inherit the calculator
                        // result without retyping.
                        ->fillForm(fn (BookingInquiry $record): array => [
                            'price' => $record->price_quoted ? (string) $record->price_quoted : null,
                        ])
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
                                'confirmed_at'   => $record->confirmed_at ?: now(),
                            ];

                            if (filled($data['price'] ?? null)) {
                                $updates['price_quoted'] = $data['price'];
                                $updates['currency']     = 'USD';
                            }

                            $record->update($updates);

                            // Phase 16.3 — record as guest payment. Observer auto-sets paid_at.
                            $amount = filled($data['price'] ?? null)
                                ? (float) $data['price']
                                : (float) ($record->price_quoted ?? 0);

                            if ($amount > 0) {
                                \App\Models\GuestPayment::create([
                                    'booking_inquiry_id'  => $record->id,
                                    'amount'              => $amount,
                                    'currency'            => 'USD',
                                    'payment_type'        => 'full',
                                    'payment_method'      => $data['payment_method'] === 'online' ? 'octo' : ($data['payment_method'] ?? 'cash'),
                                    'payment_date'        => now()->toDateString(),
                                    'recorded_by_user_id' => auth()->id(),
                                    'status'              => 'recorded',
                                ]);
                            }

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

                    Tables\Actions\Action::make('convertToDirect')
                        ->label('Convert to Direct')
                        ->icon('heroicon-o-arrow-path')
                        ->color('success')
                        ->visible(fn (BookingInquiry $record): bool =>
                            $record->status === BookingInquiry::STATUS_CANCELLED
                            && in_array($record->source, ['gyg', 'viator'], true)
                        )
                        ->modalHeading('Convert cancelled OTA booking to direct')
                        ->modalDescription(fn (BookingInquiry $record) =>
                            "This reactivates a cancelled {$record->source} booking as a direct one. Guest: {$record->customer_name}. Original ref: {$record->external_reference}. Note: OTA may still send follow-up emails — this booking will no longer be linked to them."
                        )
                        ->form([
                            Forms\Components\Select::make('new_source')
                                ->label('New source')
                                ->options([
                                    'whatsapp' => 'WhatsApp',
                                    'manual'   => 'Manual / Office',
                                    'website'  => 'Website',
                                    'phone'    => 'Phone',
                                ])
                                ->required()
                                ->native(false)
                                ->default('whatsapp'),
                            Forms\Components\TextInput::make('price')
                                ->label('New price quoted')
                                ->prefix('$')
                                ->numeric()
                                ->step('0.01')
                                ->minValue(0.01)
                                ->required()
                                ->default(fn (BookingInquiry $record) => (string) ($record->price_quoted ?? '')),
                            Forms\Components\Textarea::make('operator_note')
                                ->label('Operator note (optional)')
                                ->rows(2)
                                ->placeholder('e.g. Guest cancelled via GYG to book direct by WhatsApp'),
                        ])
                        ->modalSubmitActionLabel('Convert booking')
                        ->action(function (BookingInquiry $record, array $data): void {
                            // Enforce non-OTA source server-side (guard against form tampering).
                            if (! in_array($data['new_source'], ['whatsapp', 'manual', 'website', 'phone'], true)) {
                                Notification::make()->title('Invalid source')->danger()->send();
                                return;
                            }

                            \Illuminate\Support\Facades\DB::transaction(function () use ($record, $data) {
                                $originalSource       = $record->source;
                                $originalRef          = $record->external_reference;
                                $originalCancelledAt  = $record->cancelled_at?->format('Y-m-d H:i');
                                $originalCommission   = $record->commission_rate;
                                $hadDriver            = (bool) $record->driver_id;
                                $hadGuide             = (bool) $record->guide_id;

                                $record->update([
                                    'status'             => BookingInquiry::STATUS_AWAITING_PAYMENT,
                                    'source'             => $data['new_source'],
                                    'price_quoted'       => $data['price'],
                                    'cancelled_at'       => null,
                                    'external_reference' => null,
                                    'commission_rate'    => 0,
                                    'commission_amount'  => 0,
                                    'net_revenue'        => null,
                                ]);

                                $noteLines = [
                                    'Converted cancelled OTA booking to direct.',
                                    "  Original source: {$originalSource}",
                                    "  Original external_reference: " . ($originalRef ?: '—'),
                                    "  Original cancelled_at: " . ($originalCancelledAt ?: '—'),
                                    "  Original commission_rate: {$originalCommission}%",
                                    "  New source: {$data['new_source']}",
                                    "  New price: \${$data['price']}",
                                    "  Operator: " . (auth()->user()?->name ?? 'unknown'),
                                ];

                                if ($hadDriver || $hadGuide) {
                                    $suppliers = [];
                                    if ($hadDriver) $suppliers[] = 'driver';
                                    if ($hadGuide)  $suppliers[] = 'guide';
                                    $noteLines[] = '  ⚠️ ' . implode(' + ', $suppliers)
                                        . ' were previously notified of cancellation — re-confirm assignment manually.';
                                }

                                if (! empty($data['operator_note'])) {
                                    $noteLines[] = "  Operator note: {$data['operator_note']}";
                                }

                                $timestamp = now()->format('Y-m-d H:i');
                                $existing  = $record->internal_notes ?? '';
                                $separator = $existing ? "\n\n" : '';
                                $record->update([
                                    'internal_notes' => $existing . $separator . "[{$timestamp}] " . implode("\n", $noteLines),
                                ]);
                            });

                            Notification::make()
                                ->title('Converted to direct booking')
                                ->body('Status: Awaiting payment. Generate payment link or record offline payment next.')
                                ->success()
                                ->send();
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

                    Infolists\Components\TextEntry::make('source')->badge()->color('gray')
                        ->formatStateUsing(fn (string $state): string => BookingInquiry::SOURCE_LABELS[$state] ?? $state),
                    Infolists\Components\TextEntry::make('external_reference')
                        ->label('OTA ref')
                        ->visible(fn ($record): bool => $record->external_reference !== null)
                        ->copyable(),
                    Infolists\Components\TextEntry::make('created_at')->label('Received at')->dateTime('M j, Y H:i'),
                ])
                ->columns(4),

            Infolists\Components\Section::make('Tour catalog (structure)')
                ->description('FK links to the tour product catalog. Blank when the tour is not yet in the catalog.')
                ->schema([
                    Infolists\Components\TextEntry::make('tourProduct.title')
                        ->label('Tour product')
                        ->placeholder('— not linked —')
                        ->url(fn ($record): ?string => $record->tourProduct
                            ? route('filament.admin.resources.tour-products.edit', ['record' => $record->tourProduct])
                            : null),
                    // Infolist TextEntry has no description() method
                    // (that's a Table column API). Inline the code into
                    // the state so direction reads 'Samarkand → Bukhara (sam-bukhara)'.
                    Infolists\Components\TextEntry::make('tourProductDirection.name')
                        ->label('Direction')
                        ->placeholder('—')
                        ->formatStateUsing(function ($state, $record): string {
                            if (! $state || ! $record->tourProductDirection) {
                                return $state ?? '—';
                            }
                            $code = $record->tourProductDirection->code;

                            return $code ? "{$state} ({$code})" : $state;
                        }),
                    Infolists\Components\TextEntry::make('tour_type')
                        ->label('Type')
                        ->badge()
                        ->placeholder('—')
                        ->color(fn (?string $state): string => $state === BookingInquiry::TOUR_TYPE_GROUP ? 'info' : 'gray'),
                ])
                ->columns(3)
                ->collapsible(),

            Infolists\Components\Section::make('Tour (as submitted)')
                ->description('Historical snapshot of what the guest saw / typed. Preserved even if the catalog is later renamed.')
                ->schema([
                    Infolists\Components\TextEntry::make('tour_name_snapshot')->label('Tour name'),
                    Infolists\Components\TextEntry::make('tour_slug')->label('Slug')->placeholder('—'),
                    Infolists\Components\TextEntry::make('page_url')
                        ->label('Page')
                        ->url(fn ($state): ?string => $state ? (string) $state : null, true)
                        ->placeholder('—'),
                ])
                ->columns(1)
                ->collapsible(),

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
                    Infolists\Components\TextEntry::make('customer_country')->label('Country')->placeholder('—'),
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
                    Infolists\Components\TextEntry::make('dropoff_point')->label('Dropoff point')->placeholder('—'),
                    // Driver/guide — tap the name to open WhatsApp with a
                    // short assignment message prefilled. Phone is appended
                    // inline via formatStateUsing because Infolist TextEntry
                    // has no description() method (only Table columns do).
                    Infolists\Components\TextEntry::make('driver.full_name')
                        ->label('Driver')
                        ->placeholder('—')
                        ->formatStateUsing(function ($state, $record): string {
                            if (! $state || ! $record->driver) {
                                return $state ?? '—';
                            }
                            $phone = (string) $record->driver->phone01;

                            return $phone ? "{$state} · {$phone}" : $state;
                        })
                        ->url(fn ($record): ?string => $record->driver
                            ? 'https://wa.me/'
                                . preg_replace('/[^0-9]/', '', (string) $record->driver->phone01)
                                . '?text=' . rawurlencode('Hi ' . $record->driver->first_name . ', you have been assigned to a tour via Jahongir Travel. I will send details shortly.')
                            : null, true),
                    Infolists\Components\TextEntry::make('guide.full_name')
                        ->label('Guide')
                        ->placeholder('—')
                        ->formatStateUsing(function ($state, $record): string {
                            if (! $state || ! $record->guide) {
                                return $state ?? '—';
                            }
                            $phone = (string) $record->guide->phone01;

                            return $phone ? "{$state} · {$phone}" : $state;
                        })
                        ->url(fn ($record): ?string => $record->guide
                            ? 'https://wa.me/'
                                . preg_replace('/[^0-9]/', '', (string) $record->guide->phone01)
                                . '?text=' . rawurlencode('Hi ' . $record->guide->first_name . ', you have been assigned to a tour via Jahongir Travel. I will send details shortly.')
                            : null, true),
                    Infolists\Components\TextEntry::make('operational_notes')
                        ->label('Operational notes')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Infolists\Components\Section::make('Lodging (stays)')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('stays')
                        ->label('')
                        ->schema([
                            Infolists\Components\TextEntry::make('sort_order')->label('#'),
                            Infolists\Components\TextEntry::make('accommodation.name')->label('Accommodation'),
                            Infolists\Components\TextEntry::make('accommodation.phone_primary')->label('Phone')->copyable()->placeholder('—'),
                            Infolists\Components\TextEntry::make('stay_date')->date('M j, Y')->label('Check-in')->placeholder('—'),
                            Infolists\Components\TextEntry::make('nights')->label('Nights'),
                            Infolists\Components\TextEntry::make('guest_count')->label('Guests')->placeholder('—'),
                            Infolists\Components\TextEntry::make('total_accommodation_cost')
                                ->label('Accommodation cost')
                                ->money('USD')
                                ->placeholder('—')
                                ->color(fn ($record): string => $record->cost_override ? 'warning' : 'success')
                                ->suffix(fn ($record): string => $record->cost_override ? ' (override)' : ''),
                            Infolists\Components\TextEntry::make('meal_plan')->label('Meals')->placeholder('—'),
                            Infolists\Components\TextEntry::make('notes')->columnSpanFull()->placeholder('—'),
                        ])
                        ->columns(3)
                        ->grid(1),
                ])
                ->visible(fn ($record): bool => $record->stays()->exists())
                ->collapsible(),

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
            'index'  => Pages\ListBookingInquiries::route('/'),
            'create' => Pages\CreateBookingInquiry::route('/create'),
            'view'   => Pages\ViewBookingInquiry::route('/{record}'),
            'edit'   => Pages\EditBookingInquiry::route('/{record}/edit'),
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

    /**
     * Phase 8.3a — resolve a quote context from an inquiry + catalog.
     *
     * Returns a structured array used by the Calculate Quote action to
     * gate modal mount, render the Placeholder preview, and write the
     * audit note. Never throws — always returns a result with ok=false
     * and a human error string when any precondition fails.
     *
     * Preconditions enforced (strict — no silent defaults):
     *   1. tour_product_id must be set (operator linked the inquiry)
     *   2. tour_type must be set (no fallback to private, operator must pick)
     *   3. people_adults + people_children >= 1
     *   4. tourProduct's priceFor() must return a non-null tier
     *
     * @return array{
     *   ok: bool,
     *   error: ?string,
     *   tier: ?\App\Models\TourPriceTier,
     *   pax: int,
     *   total: float,
     *   is_fallback: bool,
     *   direction_code: ?string,
     * }
     */
    protected static function resolveQuoteContext(BookingInquiry $record): array
    {
        $empty = [
            'ok'             => false,
            'error'          => null,
            'tier'           => null,
            'pax'            => 0,
            'total'          => 0.0,
            'is_fallback'    => false,
            'direction_code' => null,
        ];

        if ($record->tour_product_id === null) {
            return array_merge($empty, ['error' => 'No tour product linked. Edit the inquiry and pick a product from the catalog first.']);
        }

        if ($record->tour_type === null) {
            return array_merge($empty, ['error' => 'Tour type is not set. Edit the inquiry → Trip section → Type (private/group) before calculating.']);
        }

        $pax = (int) $record->people_adults + (int) ($record->people_children ?? 0);
        if ($pax < 1) {
            return array_merge($empty, ['error' => 'Traveller count is zero. Set at least 1 adult on the inquiry.']);
        }

        $product = $record->tourProduct;
        if (! $product) {
            return array_merge($empty, ['error' => 'Linked tour product not found in catalog (may have been deleted).']);
        }

        // Eager-load what priceFor() walks so we don't hit N+1 inside a loop.
        $product->loadMissing(['priceTiers', 'directions']);

        $directionCode = $record->tourProductDirection?->code;
        $tier = $product->priceFor($pax, $directionCode, $record->tour_type);

        if (! $tier) {
            $directionLabel = $directionCode ?? '(any)';

            return array_merge($empty, [
                'pax'            => $pax,
                'direction_code' => $directionCode,
                'error'          => "No matching price tier for {$pax} pax · type={$record->tour_type} · direction={$directionLabel}. Add a tier in Tour Products → {$product->slug} → Price tiers.",
            ]);
        }

        $isFallback = (int) $tier->group_size !== $pax;
        $total = round((float) $tier->price_per_person_usd * $pax, 2);

        return [
            'ok'             => true,
            'error'          => null,
            'tier'           => $tier,
            'pax'            => $pax,
            'total'          => $total,
            'is_fallback'    => $isFallback,
            'direction_code' => $directionCode,
        ];
    }

    public static function getRelations(): array
    {
        return [
            BookingInquiryResource\RelationManagers\GuestPaymentsRelationManager::class,
        ];
    }
}
