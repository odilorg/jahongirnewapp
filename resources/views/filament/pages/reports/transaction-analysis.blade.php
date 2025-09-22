<!-- Transaction Analysis Report -->
<div class="space-y-6">
    <!-- Report Header -->
    <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4">
        <div class="flex items-center justify-between">
            <div>
                <h3 class="text-lg font-medium text-gray-900 dark:text-white">
                    Transaction Analysis Report
                </h3>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Analysis of transaction patterns and trends (Last 30 days)
                </p>
            </div>
            <div class="text-right">
                <p class="text-sm text-gray-600 dark:text-gray-400">Total Transactions</p>
                <p class="text-2xl font-bold text-gray-900 dark:text-white">
                    {{ $reportData['analysis']['total_transactions'] }}
                </p>
            </div>
        </div>
    </div>

    <!-- Summary Stats -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="w-8 h-8 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                        <x-heroicon-o-arrow-down class="w-5 h-5 text-blue-600 dark:text-blue-400" />
                    </div>
                </div>
                <div class="ml-4">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-white">
                        Cash In
                    </h3>
                    <p class="text-2xl font-bold text-blue-600 dark:text-blue-400">
                        {{ $reportData['analysis']['by_type']['in'] ?? 0 }}
                    </p>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="w-8 h-8 bg-red-100 dark:bg-red-900 rounded-lg flex items-center justify-center">
                        <x-heroicon-o-arrow-up class="w-5 h-5 text-red-600 dark:text-red-400" />
                    </div>
                </div>
                <div class="ml-4">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-white">
                        Cash Out
                    </h3>
                    <p class="text-2xl font-bold text-red-600 dark:text-red-400">
                        {{ $reportData['analysis']['by_type']['out'] ?? 0 }}
                    </p>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="w-8 h-8 bg-yellow-100 dark:bg-yellow-900 rounded-lg flex items-center justify-center">
                        <x-heroicon-o-arrow-path class="w-5 h-5 text-yellow-600 dark:text-yellow-400" />
                    </div>
                </div>
                <div class="ml-4">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-white">
                        Complex Transactions
                    </h3>
                    <p class="text-2xl font-bold text-yellow-600 dark:text-yellow-400">
                        {{ $reportData['analysis']['complex_transactions'] }}
                    </p>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="w-8 h-8 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                        <x-heroicon-o-chart-bar class="w-5 h-5 text-green-600 dark:text-green-400" />
                    </div>
                </div>
                <div class="ml-4">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-white">
                        Avg per Day
                    </h3>
                    <p class="text-2xl font-bold text-green-600 dark:text-green-400">
                        {{ number_format($reportData['analysis']['total_transactions'] / 30, 1) }}
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Transaction Types -->
    @if(!empty($reportData['analysis']['by_type']))
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h4 class="text-lg font-medium text-gray-900 dark:text-white">
                    Transaction Types Distribution
                </h4>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    @foreach($reportData['analysis']['by_type'] as $type => $count)
                        @php
                            $percentage = $reportData['analysis']['total_transactions'] > 0 ? ($count / $reportData['analysis']['total_transactions']) * 100 : 0;
                            $typeLabel = match($type) {
                                'in' => 'Cash In',
                                'out' => 'Cash Out',
                                'in_out' => 'Complex (In-Out)',
                                default => ucfirst($type)
                            };
                            $color = match($type) {
                                'in' => 'blue',
                                'out' => 'red',
                                'in_out' => 'yellow',
                                default => 'gray'
                            };
                        @endphp
                        <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4">
                            <div class="text-center">
                                <h5 class="font-medium text-gray-900 dark:text-white mb-2">
                                    {{ $typeLabel }}
                                </h5>
                                <p class="text-3xl font-bold text-{{ $color }}-600 dark:text-{{ $color }}-400">
                                    {{ $count }}
                                </p>
                                <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                                    {{ number_format($percentage, 1) }}% of total
                                </p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    <!-- Currency Distribution -->
    @if(!empty($reportData['analysis']['by_currency']))
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h4 class="text-lg font-medium text-gray-900 dark:text-white">
                    Currency Distribution
                </h4>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    @foreach($reportData['analysis']['by_currency'] as $currencyCode => $count)
                        @php
                            $percentage = $reportData['analysis']['total_transactions'] > 0 ? ($count / $reportData['analysis']['total_transactions']) * 100 : 0;
                            $currency = \App\Enums\Currency::from($currencyCode);
                        @endphp
                        <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4">
                            <div class="text-center">
                                <h5 class="font-medium text-gray-900 dark:text-white mb-2">
                                    {{ $currency->getLabel() }}
                                </h5>
                                <p class="text-3xl font-bold text-gray-900 dark:text-white">
                                    {{ $count }}
                                </p>
                                <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                                    {{ number_format($percentage, 1) }}% of total
                                </p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    <!-- Peak Hours -->
    @if(!empty($reportData['analysis']['peak_hours']))
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h4 class="text-lg font-medium text-gray-900 dark:text-white">
                    Peak Transaction Hours
                </h4>
            </div>
            <div class="p-6">
                <div class="space-y-4">
                    @foreach($reportData['analysis']['peak_hours'] as $hour => $count)
                        @php
                            $percentage = $reportData['analysis']['total_transactions'] > 0 ? ($count / $reportData['analysis']['total_transactions']) * 100 : 0;
                            $barWidth = min(100, ($percentage / max(array_values($reportData['analysis']['peak_hours']))) * 100);
                        @endphp
                        <div class="flex items-center">
                            <div class="w-16 text-sm font-medium text-gray-900 dark:text-white">
                                {{ sprintf('%02d:00', $hour) }}
                            </div>
                            <div class="flex-1 mx-4">
                                <div class="bg-gray-200 dark:bg-gray-700 rounded-full h-4">
                                    <div class="bg-blue-600 dark:bg-blue-400 h-4 rounded-full" style="width: {{ $barWidth }}%"></div>
                                </div>
                            </div>
                            <div class="w-20 text-sm text-gray-900 dark:text-white text-right">
                                {{ $count }} ({{ number_format($percentage, 1) }}%)
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    <!-- Recent Transactions -->
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <h4 class="text-lg font-medium text-gray-900 dark:text-white">
                Recent Transactions (Last 20)
            </h4>
        </div>
        <div class="p-6">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                ID
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Type
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Amount
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Currency
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Cashier
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Time
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($reportData['transactions']->take(20) as $transaction)
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                                    #{{ $transaction->id }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full {{ $transaction->type->value === 'in' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : ($transaction->type->value === 'out' ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' : 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200') }}">
                                        {{ $transaction->type->label() }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                                    {{ $transaction->currency->formatAmount($transaction->amount) }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    {{ $transaction->currency->value }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    {{ $transaction->shift->user->name }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    {{ $transaction->created_at->format('M j, g:i A') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
