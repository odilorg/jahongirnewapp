<?php

namespace App\Filament\Pages;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DatePicker;
use Illuminate\Support\Facades\DB;
use App\Models\Hotel;
use App\Models\Expense;

class ExpenseReports extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationLabel = 'Expense Reports';
    protected static ?string $title = 'Expense Reports';

    public ?array $reportData = null;

    protected static string $view = 'filament.pages.expense-reports';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                DatePicker::make('start_date')
                    ->label('Start Date')
                    ->required(),
                DatePicker::make('end_date')
                    ->label('End Date')
                    ->required(),
                Select::make('hotel_id')
                    ->label('Select Hotel')
                    ->options(Hotel::pluck('name', 'id'))
                    ->required(),
            ])
            ->statePath('data');
    }

    public function createReport(): void
    {
        $data = $this->form->getState();

        $this->reportData = Expense::query()
            ->where('hotel_id', $data['hotel_id'])
            ->whereBetween('expense_date', [$data['start_date'], $data['end_date']])
            ->select(
                'payment_type',
                'category_id',
                DB::raw('SUM(amount) as total_amount')
            )
            ->with('category')
            ->groupBy('payment_type', 'category_id')
            ->get();
    }
}
