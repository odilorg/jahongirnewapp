<x-filament::page>
    <form wire:submit.prevent="createReport">
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem;">
            {{ $this->form }}
        </div>
        <button type="submit" style="margin-top: 1rem; padding: 0.5rem 1rem; background-color: #3490dc; color: white; border: none; border-radius: 5px; cursor: pointer;">
            Create Report
        </button>
    </form>

    @if ($reportData)
        <div style="margin-top: 2rem;">
            <h2 style="text-align: center; font-size: 1.5rem; font-weight: bold; margin-bottom: 1rem; color: white;">
                Expense Report for {{ \Carbon\Carbon::parse($start_date)->format('F Y') }}
                @if ($hotel_id !== 'all')
                    and {{ $hotels[$hotel_id] ?? 'Unknown Hotel' }}
                @else
                    (All Hotels)
                @endif
            </h2>
            <table style="width: 100%; border-collapse: collapse; text-align: center; font-size: 14px; color: black;">
                <thead style="background-color: #f5f5f5; font-weight: bold;">
                    <tr>
                        <th style="border: 1px solid #ddd; padding: 8px;">Expense Category</th>
                        @if ($hotel_id === 'all')
                            <th style="border: 1px solid #ddd; padding: 8px;">Premium</th>
                            <th style="border: 1px solid #ddd; padding: 8px;">Premium in USD</th>
                            <th style="border: 1px solid #ddd; padding: 8px;">Jahongir</th>
                            <th style="border: 1px solid #ddd; padding: 8px;">Jahongir in USD</th>
                        @endif
                        <th style="border: 1px solid #ddd; padding: 8px;">Sum</th>
                        <th style="border: 1px solid #ddd; padding: 8px;">Sum in USD</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($reportData as $category => $data)
                        @if ($category !== 'Total')
                            <tr style="background-color: {{ $loop->odd ? '#f9f9f9' : 'white' }};">
                                <td style="border: 1px solid #ddd; padding: 8px;">{{ $category }}</td>
                                @if ($hotel_id === 'all')
                                    <td style="border: 1px solid #ddd; padding: 8px;">{{ number_format($data['Premium'], 2) }} UZS</td>
                                    <td style="border: 1px solid #ddd; padding: 8px;">{{ number_format($data['Premium_in_USD'], 2) }} USD</td>
                                    <td style="border: 1px solid #ddd; padding: 8px;">{{ number_format($data['Jahongir'], 2) }} UZS</td>
                                    <td style="border: 1px solid #ddd; padding: 8px;">{{ number_format($data['Jahongir_in_USD'], 2) }} USD</td>
                                @endif
                                <td style="border: 1px solid #ddd; padding: 8px;">{{ number_format($data['Sum'], 2) }} UZS</td>
                                <td style="border: 1px solid #ddd; padding: 8px;">{{ number_format($data['Sum_in_USD'], 2) }} USD</td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
                <tfoot style="background-color: #fce4e4; font-weight: bold;">
                    <tr>
                        <td style="border: 1px solid #ddd; padding: 8px;">Total</td>
                        @if ($hotel_id === 'all')
                            <td style="border: 1px solid #ddd; padding: 8px;">{{ number_format($reportData['Total']['Premium'], 2) }} UZS</td>
                            <td style="border: 1px solid #ddd; padding: 8px;">{{ number_format($reportData['Total']['Premium_in_USD'], 2) }} USD</td>
                            <td style="border: 1px solid #ddd; padding: 8px;">{{ number_format($reportData['Total']['Jahongir'], 2) }} UZS</td>
                            <td style="border: 1px solid #ddd; padding: 8px;">{{ number_format($reportData['Total']['Jahongir_in_USD'], 2) }} USD</td>
                        @endif
                        <td style="border: 1px solid #ddd; padding: 8px;">{{ number_format($reportData['Total']['Sum'], 2) }} UZS</td>
                        <td style="border: 1px solid #ddd; padding: 8px;">{{ number_format($reportData['Total']['Sum_in_USD'], 2) }} USD</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</x-filament::page>
