<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ExpenseCategoryResource\Pages;
use App\Models\ExpenseCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Two-level expense category management (Phase 1.6.1).
 *
 * Operators see parents grouped at the top; clicking a parent expands
 * its children. Admin can re-parent a child, mark inactive, change
 * sort order, or set a cleaner display_name without touching the
 * legacy `name` column (which keeps historical operator labels).
 *
 * Mutation surface intentionally narrow:
 *   - super_admin / admin can create + edit
 *   - cannot delete categories already referenced by cash_expenses
 *     (FK with no cascade); the form denies destructive action via
 *     ->disabled().
 */
class ExpenseCategoryResource extends Resource
{
    protected static ?string $model = ExpenseCategory::class;

    protected static ?string $navigationIcon  = 'heroicon-o-rectangle-group';
    protected static ?string $navigationLabel = 'Expense categories';
    protected static ?string $navigationGroup = 'Finance';
    protected static ?int    $navigationSort  = 95;

    protected static ?string $recordTitleAttribute = 'display_name';

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
        return self::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return self::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        // Categories carry historical cash_expenses references — never
        // hard-delete from this surface. Use is_active=false instead.
        return false;
    }

    // ──────────────────────────────────────────────
    // Form
    // ──────────────────────────────────────────────

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('parent_id')
                ->label('Parent group')
                ->placeholder('(top-level — no parent)')
                ->options(fn () => ExpenseCategory::query()
                    ->whereNull('parent_id')
                    ->orderBy('sort_order')
                    ->pluck('display_name', 'id')
                    ->all())
                ->searchable(),

            Forms\Components\Grid::make(2)->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Internal name (legacy / English)')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Historical operator label. Avoid renaming — historical reports key on this.'),

                Forms\Components\TextInput::make('display_name')
                    ->label('Display name (operator-facing)')
                    ->maxLength(100)
                    ->placeholder('Russian preferred')
                    ->helperText('Falls back to the internal name when blank.'),
            ]),

            Forms\Components\Grid::make(3)->schema([
                Forms\Components\TextInput::make('slug')
                    ->maxLength(64)
                    ->placeholder('auto'),
                Forms\Components\TextInput::make('sort_order')
                    ->numeric()
                    ->default(0),
                Forms\Components\Toggle::make('is_active')
                    ->default(true),
            ]),
        ]);
    }

    // ──────────────────────────────────────────────
    // Table
    // ──────────────────────────────────────────────

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('parent:id,display_name,name'))
            ->defaultGroup('parent.display_name')
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->sortable(),
                Tables\Columns\TextColumn::make('display_name')
                    ->label('Display name')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('name')
                    ->label('Internal name')
                    ->searchable()
                    ->limit(40),
                Tables\Columns\TextColumn::make('parent.display_name')
                    ->label('Parent group')
                    ->badge()
                    ->color('info')
                    ->placeholder('— top-level —'),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Order')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('parent_id')
                    ->label('Parent group')
                    ->options(fn () => ExpenseCategory::query()
                        ->whereNull('parent_id')
                        ->orderBy('sort_order')
                        ->pluck('display_name', 'id')
                        ->all()),
                Tables\Filters\TernaryFilter::make('is_active'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListExpenseCategories::route('/'),
            'create' => Pages\CreateExpenseCategory::route('/create'),
            'edit'   => Pages\EditExpenseCategory::route('/{record}/edit'),
        ];
    }
}
