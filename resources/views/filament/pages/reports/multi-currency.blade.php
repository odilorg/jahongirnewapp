<!-- Multi-Currency Balance Report -->
<div class="space-y-6">
    <!-- Report Header -->
    <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4">
        <div class="flex items-center justify-between">
            <div>
                <h3 class="text-lg font-medium text-gray-900 dark:text-white">
                    Multi-Currency Balance Report
                </h3>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Current balances by drawer and currency
                </p>
            </div>
            <div class="text-right">
                <p class="text-sm text-gray-600 dark:text-gray-400">Total Drawers</p>
                <p class="text-2xl font-bold text-gray-900 dark:text-white">
                    {{ $reportData['summary']['total_drawers'] }}
                </p>
            </div>
        </div>
    </div>

    <!-- Currency Summary -->
    @if(!empty($reportData['summary']['currencies']))
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h4 class="text-lg font-medium text-gray-900 dark:text-white">
                    Currency Summary
                </h4>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    @foreach($reportData['summary']['currencies'] as $currencyCode => $data)
                        <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4">
                            <div class="flex items-center justify-between mb-2">
                                <h5 class="font-medium text-gray-900 dark:text-white">
                                    {{ $data['currency']->getLabel() }}
                                </h5>
                                <span class="text-sm text-gray-600 dark:text-gray-400">
                                    {{ $data['drawers_count'] }} drawers
                                </span>
                            </div>
                            <div class="text-center">
                                <p class="text-2xl font-bold {{ $data['total_balance'] >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                    {{ $data['currency']->formatAmount($data['total_balance']) }}
                                </p>
                                <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                                    Total Balance
                                </p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    <!-- Drawer Details -->
    @foreach($reportData['drawers'] as $drawer)
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <div class="flex items-center justify-between">
                    <h4 class="text-lg font-medium text-gray-900 dark:text-white">
                        {{ $drawer->name }}
                    </h4>
                    <div class="flex items-center space-x-4">
                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full {{ $drawer->is_active ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' }}">
                            {{ $drawer->is_active ? 'Active' : 'Inactive' }}
                        </span>
                        <span class="text-sm text-gray-600 dark:text-gray-400">
                            {{ $drawer->openShifts->count() }} open shifts
                        </span>
                    </div>
                </div>
            </div>
            <div class="p-6">
                @if($drawer->openShifts->isNotEmpty())
                    @foreach($drawer->openShifts as $shift)
                        <div class="mb-6 last:mb-0">
                            <div class="flex items-center justify-between mb-4">
                                <h5 class="text-md font-medium text-gray-900 dark:text-white">
                                    Shift #{{ $shift->id }} - {{ $shift->user->name }}
                                </h5>
                                <div class="flex items-center space-x-2">
                                    <span class="text-sm text-gray-600 dark:text-gray-400">
                                        Started: {{ $shift->opened_at->format('M j, Y g:i A') }}
                                    </span>
                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200">
                                        Open
                                    </span>
                                </div>
                            </div>

                            <!-- Beginning Saldos -->
                            @if($shift->beginningSaldos->isNotEmpty())
                                <div class="mb-4">
                                    <h6 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Beginning Saldos:
                                    </h6>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach($shift->beginningSaldos as $saldo)
                                            <span class="inline-flex px-3 py-1 text-sm bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200 rounded-full">
                                                {{ $saldo->formatted_amount }}
                                            </span>
                                        @endforeach
                                        @if($shift->beginning_saldo > 0)
                                            <span class="inline-flex px-3 py-1 text-sm bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200 rounded-full">
                                                {{ \App\Enums\Currency::UZS->formatAmount($shift->beginning_saldo) }}
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            @endif

                            <!-- Current Balances -->
                            @php
                                $usedCurrencies = $shift->getUsedCurrencies();
                                $beginningSaldoCurrencies = $shift->beginningSaldos->pluck('currency');
                                $allCurrencies = $usedCurrencies->merge($beginningSaldoCurrencies)->unique();
                                
                                // Include UZS if there's a legacy beginning_saldo
                                if ($shift->beginning_saldo > 0) {
                                    $allCurrencies = $allCurrencies->push(\App\Enums\Currency::UZS);
                                }
                                $allCurrencies = $allCurrencies->unique();
                            @endphp

                            @if($allCurrencies->isNotEmpty())
                                <div>
                                    <h6 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Current Balances:
                                    </h6>
                                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                        @foreach($allCurrencies as $currency)
                                            @php
                                                $balance = $shift->getNetBalanceForCurrency($currency);
                                            @endphp
                                            <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-3">
                                                <div class="text-center">
                                                    <p class="text-sm font-medium text-gray-600 dark:text-gray-400">
                                                        {{ $currency->getLabel() }}
                                                    </p>
                                                    <p class="text-lg font-bold {{ $balance >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                                        {{ $currency->formatAmount($balance) }}
                                                    </p>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @else
                                <div class="text-center py-4">
                                    <x-heroicon-o-exclamation-circle class="mx-auto h-8 w-8 text-gray-400" />
                                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                        No currency activity in this shift
                                    </p>
                                </div>
                            @endif
                        </div>
                    @endforeach
                @else
                    <div class="text-center py-8">
                        <x-heroicon-o-stop class="mx-auto h-12 w-12 text-gray-400" />
                        <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-white">No Open Shifts</h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            This drawer currently has no active shifts.
                        </p>
                    </div>
                @endif
            </div>
        </div>
    @endforeach

    <!-- Exchange Rates -->
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <h4 class="text-lg font-medium text-gray-900 dark:text-white">
                Current Exchange Rates (1 UZS =)
            </h4>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4">
                    <div class="text-center">
                        <h5 class="font-medium text-gray-900 dark:text-white mb-2">EUR</h5>
                        <p class="text-2xl font-bold text-blue-600 dark:text-blue-400">
                            {{ number_format(\App\Enums\Currency::EUR->getDefaultExchangeRate(), 6) }}
                        </p>
                    </div>
                </div>
                <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4">
                    <div class="text-center">
                        <h5 class="font-medium text-gray-900 dark:text-white mb-2">USD</h5>
                        <p class="text-2xl font-bold text-green-600 dark:text-green-400">
                            {{ number_format(\App\Enums\Currency::USD->getDefaultExchangeRate(), 6) }}
                        </p>
                    </div>
                </div>
                <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4">
                    <div class="text-center">
                        <h5 class="font-medium text-gray-900 dark:text-white mb-2">RUB</h5>
                        <p class="text-2xl font-bold text-red-600 dark:text-red-400">
                            {{ number_format(\App\Enums\Currency::RUB->getDefaultExchangeRate(), 6) }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
