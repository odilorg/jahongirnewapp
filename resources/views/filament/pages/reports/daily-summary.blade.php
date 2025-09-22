<!-- Daily Summary Report -->
<div class="space-y-6">
    <!-- Report Header -->
    <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4">
        <div class="flex items-center justify-between">
            <div>
                <h3 class="text-lg font-medium text-gray-900 dark:text-white">
                    Daily Cash Summary - {{ $reportData['date'] }}
                </h3>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Overview of daily cash operations and balances
                </p>
            </div>
            <div class="text-right">
                <p class="text-sm text-gray-600 dark:text-gray-400">Total Shifts</p>
                <p class="text-2xl font-bold text-gray-900 dark:text-white">
                    {{ $reportData['today_summary']['total_shifts'] }}
                </p>
            </div>
        </div>
    </div>

    <!-- Currency Summary -->
    @if(!empty($reportData['today_summary']['currencies']))
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h4 class="text-lg font-medium text-gray-900 dark:text-white">
                    Currency Summary
                </h4>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    @foreach($reportData['today_summary']['currencies'] as $currencyCode => $data)
                        <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4">
                            <div class="flex items-center justify-between mb-2">
                                <h5 class="font-medium text-gray-900 dark:text-white">
                                    {{ $data['currency']->getLabel() }}
                                </h5>
                                <span class="text-sm text-gray-600 dark:text-gray-400">
                                    {{ $data['shifts_count'] }} shifts
                                </span>
                            </div>
                            <div class="space-y-2">
                                <div class="flex justify-between">
                                    <span class="text-sm text-gray-600 dark:text-gray-400">Cash In:</span>
                                    <span class="text-sm font-medium text-green-600 dark:text-green-400">
                                        {{ $data['currency']->formatAmount($data['cash_in']) }}
                                    </span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-sm text-gray-600 dark:text-gray-400">Cash Out:</span>
                                    <span class="text-sm font-medium text-red-600 dark:text-red-400">
                                        {{ $data['currency']->formatAmount($data['cash_out']) }}
                                    </span>
                                </div>
                                <div class="flex justify-between border-t border-gray-200 dark:border-gray-600 pt-2">
                                    <span class="text-sm font-medium text-gray-900 dark:text-white">Net Balance:</span>
                                    <span class="text-sm font-bold {{ $data['net_balance'] >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                        {{ $data['currency']->formatAmount($data['net_balance']) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    <!-- Discrepancies -->
    @if(!empty($reportData['today_summary']['discrepancies']))
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h4 class="text-lg font-medium text-red-600 dark:text-red-400">
                    Discrepancies Found
                </h4>
            </div>
            <div class="p-6">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                    Shift ID
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                    Cashier
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                    Currency
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                    Discrepancy
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                    Reason
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($reportData['today_summary']['discrepancies'] as $discrepancy)
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                                        #{{ $discrepancy['shift_id'] }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                        {{ $discrepancy['cashier'] }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                        {{ $discrepancy['currency'] }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium {{ $discrepancy['discrepancy'] >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                        {{ number_format($discrepancy['discrepancy'], 2) }}
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-900 dark:text-white">
                                        {{ $discrepancy['reason'] ?? 'No reason provided' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @else
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
            <div class="text-center">
                <x-heroicon-o-check-circle class="mx-auto h-12 w-12 text-green-400" />
                <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-white">No Discrepancies</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    All shifts balanced correctly today.
                </p>
            </div>
        </div>
    @endif

    <!-- Yesterday Comparison -->
    @if(!empty($reportData['comparison']['currencies_change']))
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h4 class="text-lg font-medium text-gray-900 dark:text-white">
                    Yesterday Comparison
                </h4>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    @foreach($reportData['comparison']['currencies_change'] as $currencyCode => $changes)
                        <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4">
                            <h5 class="font-medium text-gray-900 dark:text-white mb-3">
                                {{ $currencyCode }}
                            </h5>
                            <div class="space-y-2">
                                <div class="flex justify-between">
                                    <span class="text-sm text-gray-600 dark:text-gray-400">Cash In Change:</span>
                                    <span class="text-sm font-medium {{ $changes['cash_in_change'] >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                        {{ $changes['cash_in_change'] >= 0 ? '+' : '' }}{{ number_format($changes['cash_in_change'], 2) }}
                                    </span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-sm text-gray-600 dark:text-gray-400">Cash Out Change:</span>
                                    <span class="text-sm font-medium {{ $changes['cash_out_change'] >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                        {{ $changes['cash_out_change'] >= 0 ? '+' : '' }}{{ number_format($changes['cash_out_change'], 2) }}
                                    </span>
                                </div>
                                <div class="flex justify-between border-t border-gray-200 dark:border-gray-600 pt-2">
                                    <span class="text-sm font-medium text-gray-900 dark:text-white">Net Change:</span>
                                    <span class="text-sm font-bold {{ $changes['net_balance_change'] >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                        {{ $changes['net_balance_change'] >= 0 ? '+' : '' }}{{ number_format($changes['net_balance_change'], 2) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
