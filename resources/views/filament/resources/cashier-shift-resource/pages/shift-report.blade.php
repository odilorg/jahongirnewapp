<x-filament-panels::page>
    <div class="max-w-6xl mx-auto">
        <x-filament::section>
            <x-slot name="heading">
                Shift Report #{{ $record->id }}
            </x-slot>

            <x-slot name="description">
                Detailed report for shift on {{ $record->cashDrawer->name }} by {{ $record->user->name }}
            </x-slot>

            <div class="space-y-6">
                <!-- Shift Summary -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div class="bg-blue-50 dark:bg-blue-900/20 p-4 rounded-lg">
                        <div class="text-sm font-medium text-blue-800 dark:text-blue-200">
                            Beginning Balance
                        </div>
                        <div class="text-xl font-bold text-blue-900 dark:text-blue-100">
                            {{ number_format($record->beginning_saldo, 2) }} UZS
                        </div>
                    </div>
                    
                    <div class="bg-green-50 dark:bg-green-900/20 p-4 rounded-lg">
                        <div class="text-sm font-medium text-green-800 dark:text-green-200">
                            Cash In
                        </div>
                        <div class="text-xl font-bold text-green-900 dark:text-green-100">
                            {{ number_format($record->total_cash_in, 2) }} UZS
                        </div>
                    </div>
                    
                    <div class="bg-red-50 dark:bg-red-900/20 p-4 rounded-lg">
                        <div class="text-sm font-medium text-red-800 dark:text-red-200">
                            Cash Out
                        </div>
                        <div class="text-xl font-bold text-red-900 dark:text-red-100">
                            {{ number_format($record->total_cash_out, 2) }} UZS
                        </div>
                    </div>
                    
                    <div class="bg-purple-50 dark:bg-purple-900/20 p-4 rounded-lg">
                        <div class="text-sm font-medium text-purple-800 dark:text-purple-200">
                            Expected Balance
                        </div>
                        <div class="text-xl font-bold text-purple-900 dark:text-purple-100">
                            {{ number_format($record->expected_end_saldo, 2) }} UZS
                        </div>
                    </div>
                </div>

                @if($record->isClosed())
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="bg-gray-50 dark:bg-gray-900/20 p-4 rounded-lg">
                            <div class="text-sm font-medium text-gray-800 dark:text-gray-200">
                                Counted Balance
                            </div>
                            <div class="text-xl font-bold text-gray-900 dark:text-gray-100">
                                {{ number_format($record->counted_end_saldo, 2) }} UZS
                            </div>
                        </div>
                        
                        <div class="bg-{{ $record->discrepancy > 0 ? 'green' : ($record->discrepancy < 0 ? 'red' : 'gray') }}-50 dark:bg-{{ $record->discrepancy > 0 ? 'green' : ($record->discrepancy < 0 ? 'red' : 'gray') }}-900/20 p-4 rounded-lg">
                            <div class="text-sm font-medium text-{{ $record->discrepancy > 0 ? 'green' : ($record->discrepancy < 0 ? 'red' : 'gray') }}-800 dark:text-{{ $record->discrepancy > 0 ? 'green' : ($record->discrepancy < 0 ? 'red' : 'gray') }}-200">
                                Discrepancy
                            </div>
                            <div class="text-xl font-bold text-{{ $record->discrepancy > 0 ? 'green' : ($record->discrepancy < 0 ? 'red' : 'gray') }}-900 dark:text-{{ $record->discrepancy > 0 ? 'green' : ($record->discrepancy < 0 ? 'red' : 'gray') }}-100">
                                {{ number_format($record->discrepancy, 2) }} UZS
                            </div>
                        </div>
                    </div>
                @endif

                <!-- Transactions Table -->
                <div>
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                        Transactions
                    </h3>
                    
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-800">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        Time
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        Type
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        Amount
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        Category
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        Reference
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        Notes
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                                @forelse($this->getTransactions() as $transaction)
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                            {{ $transaction->occurred_at->format('H:i:s') }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-{{ $transaction->type->value === 'in' ? 'green' : 'red' }}-100 text-{{ $transaction->type->value === 'in' ? 'green' : 'red' }}-800">
                                                {{ $transaction->type->label() }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                            {{ number_format($transaction->amount, 2) }} UZS
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                            {{ $transaction->category?->label() ?? '-' }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                            {{ $transaction->reference ?? '-' }}
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100">
                                            {{ $transaction->notes ?? '-' }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500 dark:text-gray-400">
                                            No transactions recorded
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                @if($record->isClosed() && $this->getCashCount())
                    <!-- Cash Count -->
                    <div>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                            Cash Count
                        </h3>
                        
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-800">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                            Denomination
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                            Quantity
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                            Subtotal
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                                    @foreach($this->getCashCount()->formatted_denominations as $denomination)
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                                {{ $denomination['denomination'] }} UZS
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                                {{ $denomination['qty'] }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                                {{ $denomination['subtotal'] }} UZS
                                            </td>
                                        </tr>
                                    @endforeach
                                    <tr class="bg-gray-50 dark:bg-gray-800 font-bold">
                                        <td colspan="2" class="px-6 py-4 text-right text-sm text-gray-900 dark:text-gray-100">
                                            Total:
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                            {{ number_format($this->getCashCount()->total, 2) }} UZS
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>