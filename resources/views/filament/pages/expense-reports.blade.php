<x-filament::page>
    <form wire:submit.prevent="createReport">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            {{ $this->form }}
        </div>
        <button type="submit" class="mt-4 filament-button">Create Report</button>
    </form>

    @if ($reportData)
        <div class="mt-8">
            <h2 class="text-xl font-bold">Expense Report</h2>
            <table class="w-full mt-4 filament-table">
                <thead>
                    <tr>
                        <th>Payment Type</th>
                        <th>Category</th>
                        <th>Total Amount (UZS)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($reportData as $report)
                        <tr>
                            <td>{{ ucfirst($report->payment_type) }}</td>
                            <td>{{ $report->category->name ?? 'N/A' }}</td>
                            <td>{{ number_format($report->total_amount / 100, 2) }} UZS</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="mt-4 text-right">
                <strong>Grand Total: {{ number_format($reportData->sum('total_amount') / 100, 2) }} UZS</strong>
            </div>
        </div>
    @endif
</x-filament::page>
