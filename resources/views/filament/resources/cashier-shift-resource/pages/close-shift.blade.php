<x-filament-panels::page>
    <div class="max-w-4xl mx-auto space-y-6">
        <!-- Shift Summary -->
        <x-filament::section>
            <x-slot name="heading">
                Shift Summary
            </x-slot>
            
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Cash Drawer</label>
                    <p class="mt-1 text-sm text-gray-900">{{ $record->cashDrawer->name }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Currencies Used</label>
                    <p class="mt-1 text-sm text-gray-900">
                        @if($record->getUsedCurrencies()->isNotEmpty())
                            {{ $record->getUsedCurrencies()->map(fn($currency) => $currency->getLabel())->join(', ') }}
                        @else
                            No transactions yet
                        @endif
                    </p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Beginning Saldo</label>
                    <p class="mt-1 text-sm text-gray-900">{{ number_format($record->beginning_saldo, 2) }} UZS</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Multi-Currency Beginning Saldos</label>
                    <div class="mt-1 text-sm text-gray-900">
                        @if($record->beginningSaldos->isNotEmpty())
                            @foreach($record->beginningSaldos as $saldo)
                                <span class="inline-block bg-blue-100 text-blue-800 text-xs px-2 py-1 rounded mr-1 mb-1">
                                    {{ $saldo->formatted_amount }}
                                </span>
                            @endforeach
                        @else
                            <span class="text-gray-500">None set</span>
                        @endif
                    </div>
                </div>
                @if($record->status->value === 'closed')
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Expected End Saldo (UZS)</label>
                        <p class="mt-1 text-sm text-gray-900">{{ number_format($record->calculateExpectedEndSaldo(), 2) }} UZS</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Counted End Saldos</label>
                        <p class="mt-1 text-sm text-gray-900">
                            @if($record->endSaldos->isNotEmpty())
                                @foreach($record->endSaldos as $endSaldo)
                                    <span class="inline-block bg-green-100 text-green-800 text-xs px-2 py-1 rounded mr-1 mb-1">
                                        {{ $endSaldo->formatted_counted_end_saldo }}
                                    </span>
                                @endforeach
                            @else
                                <span class="text-gray-500">Not recorded (Debug: {{ $record->endSaldos->count() }} records)</span>
                            @endif
                        </p>
                    </div>
                    @if($record->endSaldos->isNotEmpty())
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Discrepancies</label>
                            <p class="mt-1 text-sm text-gray-900">
                                @foreach($record->endSaldos as $endSaldo)
                                    @if($endSaldo->hasDiscrepancy())
                                        <span class="inline-block bg-red-100 text-red-800 text-xs px-2 py-1 rounded mr-1 mb-1">
                                            {{ $endSaldo->formatted_discrepancy }} ({{ $endSaldo->currency->value }})
                                        </span>
                                    @else
                                        <span class="inline-block bg-green-100 text-green-800 text-xs px-2 py-1 rounded mr-1 mb-1">
                                            No discrepancy ({{ $endSaldo->currency->value }})
                                        </span>
                                    @endif
                                @endforeach
                            </p>
                        </div>
                    @endif
                @else
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Current Balance (UZS)</label>
                        <p class="mt-1 text-sm text-gray-900">{{ number_format($record->getNetBalanceForCurrency(\App\Enums\Currency::UZS), 2) }} UZS</p>
                    </div>
                @endif
            </div>
        </x-filament::section>

        @if($record->status->value === 'open')
            <!-- Open Shift Notice -->
            <x-filament::section>
                <x-slot name="heading">
                    Shift Status: Open
                </x-slot>
                
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-yellow-800">
                                This shift is currently open
                            </h3>
                            <div class="mt-2 text-sm text-yellow-700">
                                <p>Expected and counted end saldos will be calculated when you close this shift. Use the "Close Shift" button above to complete the shift.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </x-filament::section>
        @endif

        <!-- Transaction Summary -->
        <x-filament::section>
            <x-slot name="heading">
                Transaction Summary by Currency
            </x-slot>
            
            @if($record->getUsedCurrencies()->isNotEmpty())
                <div class="space-y-4">
                    @foreach($record->getUsedCurrencies() as $currency)
                        <div class="border rounded-lg p-4">
                            <h4 class="text-lg font-semibold mb-3">{{ $currency->getLabel() }} ({{ $currency->getSymbol() }})</h4>
                            <div class="grid grid-cols-3 gap-4">
                                <div class="text-center">
                                    <div class="text-2xl font-bold text-green-600">{{ $currency->formatAmount($record->getTotalCashInForCurrency($currency)) }}</div>
                                    <div class="text-sm text-gray-500">Total Cash In</div>
                                </div>
                                <div class="text-center">
                                    <div class="text-2xl font-bold text-red-600">{{ $currency->formatAmount($record->getTotalCashOutForCurrency($currency)) }}</div>
                                    <div class="text-sm text-gray-500">Total Cash Out</div>
                                </div>
                                <div class="text-center">
                                    <div class="text-2xl font-bold text-blue-600">{{ $currency->formatAmount($record->getNetBalanceForCurrency($currency)) }}</div>
                                    <div class="text-sm text-gray-500">Net Balance</div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-gray-500">No transactions recorded yet.</p>
            @endif
        </x-filament::section>

        <!-- Recent Transactions -->
        @if($record->transactions->count() > 0)
        <x-filament::section>
            <x-slot name="heading">
                Recent Transactions
            </x-slot>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Currency</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @foreach($record->transactions->take(10) as $transaction)
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $transaction->type->value === 'in' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                    {{ $transaction->type->value === 'in' ? 'Cash In' : 'Cash Out' }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                {{ $transaction->currency->formatAmount($transaction->amount) }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                    {{ $transaction->currency->value }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                {{ $transaction->category ?? 'N/A' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                {{ $transaction->occurred_at->format('M d, Y H:i') }}
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>