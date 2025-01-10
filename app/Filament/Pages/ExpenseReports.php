<?php

namespace App\Filament\Pages;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use App\Models\Hotel;
use App\Models\Expense;

class ExpenseReports extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationLabel = 'Expense Reports';
    protected static ?string $title = 'Expense Reports';
    public $start_date;
    public $end_date;
    public $hotel_id;
    public $reportData = null;
    public $hotels = [];

    protected static string $view = 'filament.pages.expense-reports';

    public function mount()
    {
        $this->hotels = Hotel::pluck('name', 'id')->toArray();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\DatePicker::make('start_date')
                    ->label('Start Date')
                    ->required(),
                    //->reactive(),
                Forms\Components\DatePicker::make('end_date')
                    ->label('End Date')
                    ->required(),
                    //->reactive(),
                Forms\Components\Select::make('hotel_id')
                    ->label('Hotel')
                    ->options($this->hotels + ['all' => 'All Hotels'])
                    ->required(),
                   // ->reactive(),
            ]);
    }

    public function createReport(): void
    {
        $this->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);
    
        $startDate = $this->start_date;
        $endDate = $this->end_date;
        $hotelId = $this->hotel_id;
        $usdConversionRate = 12850;
    
        $hotels = Hotel::pluck('name', 'id')->toArray();
        $expensesQuery = Expense::query()
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->select(
                'category_id',
                'hotel_id',
                DB::raw('SUM(amount) as total_amount')
            )
            ->with('category', 'hotel')
            ->groupBy('category_id', 'hotel_id');
    
        if ($hotelId !== 'all') {
            $expensesQuery->where('hotel_id', $hotelId);
        }
    
        $expenses = $expensesQuery->get();
    
        $reportData = [];
    
        foreach ($expenses as $expense) {
            $hotelName = $hotels[$expense->hotel_id] ?? 'Unknown';
            $categoryName = $expense->category->name ?? 'Unknown';
    
            if (!isset($reportData[$categoryName])) {
                $reportData[$categoryName] = [
                    'Premium' => 0,
                    'Premium_in_USD' => 0,
                    'Jahongir' => 0,
                    'Jahongir_in_USD' => 0,
                    'Sum' => 0,
                    'Sum_in_USD' => 0,
                ];
            }
    
            if (str_contains(strtolower($hotelName), 'premium')) {
                $reportData[$categoryName]['Premium'] += $expense->total_amount / 100;
                $reportData[$categoryName]['Premium_in_USD'] += ($expense->total_amount / 100) / $usdConversionRate;
            } elseif (str_contains(strtolower($hotelName), 'jahongir')) {
                $reportData[$categoryName]['Jahongir'] += $expense->total_amount / 100;
                $reportData[$categoryName]['Jahongir_in_USD'] += ($expense->total_amount / 100) / $usdConversionRate;
            }
    
            $reportData[$categoryName]['Sum'] += $expense->total_amount / 100;
            $reportData[$categoryName]['Sum_in_USD'] += ($expense->total_amount / 100) / $usdConversionRate;
        }
    
        // Calculate total row
        $reportData['Total'] = [
            'Premium' => array_sum(array_column($reportData, 'Premium')),
            'Premium_in_USD' => array_sum(array_column($reportData, 'Premium_in_USD')),
            'Jahongir' => array_sum(array_column($reportData, 'Jahongir')),
            'Jahongir_in_USD' => array_sum(array_column($reportData, 'Jahongir_in_USD')),
            'Sum' => array_sum(array_column($reportData, 'Sum')),
            'Sum_in_USD' => array_sum(array_column($reportData, 'Sum_in_USD')),
        ];
    
        $this->reportData = $reportData;
    }
    

}
