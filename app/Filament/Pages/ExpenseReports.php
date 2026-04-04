<?php

namespace App\Filament\Pages;

use Filament\Forms;
use App\Models\Hotel;
use App\Models\Expense;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

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

    // Drill-down state: category name being expanded, null = all collapsed
    public ?string $expandedCategory = null;

    // Individual expense rows for the expanded category
    public array $expandedRows = [];

    // Sort state for main table
    public string $sortBy = 'Sum';
    public string $sortDir = 'desc';

    // Toggle zero-value rows
    public bool $showZeroRows = false;

    // Cached USD rate used in the last report
    public float $usdRate = 0;

    protected static string $view = 'filament.pages.expense-reports';

    public function mount(): void
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
                Forms\Components\DatePicker::make('end_date')
                    ->label('End Date')
                    ->required(),
                Forms\Components\Select::make('hotel_id')
                    ->label('Hotel')
                    ->options(['all' => 'All Hotels'] + $this->hotels)
                    ->required(),
            ]);
    }

    public function createReport(): void
    {
        $this->expandedCategory = null;
        $this->expandedRows = [];

        $this->usdRate = $this->fetchUSDConversionRate($this->end_date);

        $query = Expense::query()
            ->whereBetween('expense_date', [$this->start_date, $this->end_date])
            ->select('category_id', 'hotel_id', DB::raw('SUM(amount) as total_amount'))
            ->with('category', 'hotel')
            ->groupBy('category_id', 'hotel_id');

        if ($this->hotel_id !== 'all') {
            $query->where('hotel_id', $this->hotel_id);
        }

        $expenses = $query->get();

        // Build hotel id → column key map dynamically
        $hotelColumnMap = [];
        foreach ($this->hotels as $id => $name) {
            $hotelColumnMap[$id] = $name;
        }

        $reportData = [];

        foreach ($expenses as $expense) {
            $categoryName = $expense->category->name ?? 'Unknown';
            $hotelName    = $hotelColumnMap[$expense->hotel_id] ?? 'Unknown';
            $amountUzs    = $expense->total_amount / 100;

            if (!isset($reportData[$categoryName])) {
                $reportData[$categoryName] = [
                    'Sum'        => 0,
                    'Sum_USD'    => 0,
                    'hotels'     => [],  // hotel_name => amount
                    'row_count'  => 0,
                ];
                foreach ($this->hotels as $hName) {
                    $reportData[$categoryName]['hotels'][$hName] = 0;
                }
            }

            $reportData[$categoryName]['Sum']     += $amountUzs;
            $reportData[$categoryName]['Sum_USD'] += $amountUzs / $this->usdRate;
            $reportData[$categoryName]['hotels'][$hotelName] = ($reportData[$categoryName]['hotels'][$hotelName] ?? 0) + $amountUzs;
        }

        // Attach row counts per category
        $rowCounts = Expense::query()
            ->whereBetween('expense_date', [$this->start_date, $this->end_date])
            ->when($this->hotel_id !== 'all', fn($q) => $q->where('hotel_id', $this->hotel_id))
            ->select('category_id', DB::raw('COUNT(*) as cnt'))
            ->with('category')
            ->groupBy('category_id')
            ->get();

        foreach ($rowCounts as $row) {
            $catName = $row->category->name ?? 'Unknown';
            if (isset($reportData[$catName])) {
                $reportData[$catName]['row_count'] = $row->cnt;
            }
        }

        // Sort
        uasort($reportData, function ($a, $b) {
            $va = $a[$this->sortBy === 'Sum' ? 'Sum' : 'Sum'];
            $vb = $b[$this->sortBy === 'Sum' ? 'Sum' : 'Sum'];
            return $this->sortDir === 'desc' ? $vb <=> $va : $va <=> $vb;
        });

        // Calculate totals
        $totalUzs = array_sum(array_column($reportData, 'Sum'));
        $totalUsd = $totalUzs / $this->usdRate;

        // Attach % of total
        foreach ($reportData as $cat => &$data) {
            $data['pct'] = $totalUzs > 0 ? round($data['Sum'] / $totalUzs * 100, 1) : 0;
        }
        unset($data);

        $this->reportData = [
            'rows'      => $reportData,
            'total_uzs' => $totalUzs,
            'total_usd' => $totalUsd,
        ];
    }

    /**
     * Toggle drill-down for a category row.
     */
    public function toggleCategory(string $category): void
    {
        if ($this->expandedCategory === $category) {
            $this->expandedCategory = null;
            $this->expandedRows = [];
            return;
        }

        $this->expandedCategory = $category;

        $this->expandedRows = Expense::query()
            ->whereBetween('expense_date', [$this->start_date, $this->end_date])
            ->when($this->hotel_id !== 'all', fn($q) => $q->where('hotel_id', $this->hotel_id))
            ->whereHas('category', fn($q) => $q->where('name', $category))
            ->with('category', 'hotel')
            ->orderBy('expense_date')
            ->get()
            ->map(fn($e) => [
                'date'         => $e->expense_date,
                'name'         => $e->name,
                'payment_type' => $e->payment_type,
                'hotel'        => $e->hotel->name ?? '—',
                'amount_uzs'   => $e->amount,
                'amount_usd'   => $e->amount / $this->usdRate,
            ])
            ->toArray();
    }

    /**
     * Sort the report table by a column.
     */
    public function sortTable(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'desc' ? 'asc' : 'desc';
        } else {
            $this->sortBy  = $column;
            $this->sortDir = 'desc';
        }

        // Re-run report with new sort (reuses existing data if already generated)
        if ($this->reportData) {
            $this->createReport();
        }
    }

    /**
     * Export current report as CSV.
     */
    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $filename = 'expense-report-' . $this->start_date . '-' . $this->end_date . '.csv';

        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Category', 'Total UZS', 'Total USD', '% of Total', 'Transactions']);

            foreach ($this->reportData['rows'] as $cat => $data) {
                fputcsv($handle, [
                    $cat,
                    number_format($data['Sum'], 2),
                    number_format($data['Sum_USD'], 2),
                    $data['pct'] . '%',
                    $data['row_count'],
                ]);
            }

            fputcsv($handle, [
                'TOTAL',
                number_format($this->reportData['total_uzs'], 2),
                number_format($this->reportData['total_usd'], 2),
                '100%',
                '',
            ]);

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function fetchUSDConversionRate(string $date): float
    {
        return Cache::remember("usd_rate_{$date}", 3600, function () use ($date) {
            try {
                $response = Http::timeout(5)->get("https://cbu.uz/ru/arkhiv-kursov-valyut/json/USD/{$date}/");
                $data = $response->json();
                if (!empty($data) && isset($data[0]['Rate'])) {
                    return (float) $data[0]['Rate'];
                }
            } catch (\Exception $e) {
                Log::error('USD rate fetch failed: ' . $e->getMessage());
            }
            return 12700.0;
        });
    }
}
