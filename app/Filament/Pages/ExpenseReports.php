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

    public $start_date;
    public $end_date;

    protected static string $view = 'filament.pages.expense-reports';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                DatePicker::make('start_date')
                    ->label('Start Date')
                    ->required()
                    ->reactive(),
                DatePicker::make('end_date')
                    ->label('End Date')
                    ->required()
                    ->reactive(),
            ]);
    }

    public function createReport(): void
    {
        // Validate that dates are set
        if (!$this->start_date || !$this->end_date) {
            $this->reportData = [];
            return;
        }

        // Fetch expenses grouped by category and hotel
        $expenses = Expense::query()
            ->whereBetween('expense_date', [$this->start_date, $this->end_date])
            ->select(
                'category_id',
                'hotel_id',
                DB::raw('SUM(amount) as total_amount')
            )
            ->with('category', 'hotel')
            ->groupBy('category_id', 'hotel_id')
            ->get();

        // Format the data
        $categories = [];
        foreach ($expenses as $expense) {
            $categoryName = $expense->category->name;
            $hotelName = $expense->hotel->name;
            $amount = $expense->total_amount / 100; // Convert cents to dollars if MoneyCast is used

            if (!isset($categories[$categoryName])) {
                $categories[$categoryName] = [
                    'Premium' => 0,
                    'Jahongir' => 0,
                    'Sum' => 0,
                ];
            }

            $categories[$categoryName][$hotelName] = $amount;
            $categories[$categoryName]['Sum'] += $amount;
        }

        // Add totals row
        $totals = [
            'Premium' => array_sum(array_column($categories, 'Premium')),
            'Jahongir' => array_sum(array_column($categories, 'Jahongir')),
            'Sum' => array_sum(array_column($categories, 'Sum')),
        ];
        $categories['Total'] = $totals;

        $this->reportData = $categories;
    }
}
