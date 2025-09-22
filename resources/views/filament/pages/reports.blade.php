<x-filament-panels::page>
    <div class="space-y-6">
        <!-- Header -->
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
                        Cash Management Reports
                    </h1>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        Comprehensive reports for cash operations, performance analysis, and multi-currency tracking
                    </p>
                </div>
                <div class="flex space-x-3">
                    <x-filament::button
                        color="primary"
                        icon="heroicon-o-document-arrow-down"
                        wire:click="exportAllReports"
                    >
                        Export All Reports
                    </x-filament::button>
                </div>
            </div>
        </div>

        <!-- Reports Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <!-- Daily Summary Report -->
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6 hover:shadow-lg transition-shadow cursor-pointer"
                 wire:click="viewReport('daily_summary')">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                            <x-heroicon-o-calendar-days class="w-5 h-5 text-blue-600 dark:text-blue-400" />
                        </div>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white">
                            Daily Cash Summary
                        </h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            Daily operations overview
                        </p>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600 dark:text-gray-400">Last updated</span>
                        <span class="text-sm font-medium text-gray-900 dark:text-white">
                            {{ now()->format('M j, Y') }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Shift Performance Report -->
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6 hover:shadow-lg transition-shadow cursor-pointer"
                 wire:click="viewReport('shift_performance')">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                            <x-heroicon-o-user-group class="w-5 h-5 text-green-600 dark:text-green-400" />
                        </div>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white">
                            Shift Performance
                        </h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            Cashier performance metrics
                        </p>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600 dark:text-gray-400">Last updated</span>
                        <span class="text-sm font-medium text-gray-900 dark:text-white">
                            {{ now()->format('M j, Y') }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Multi-Currency Report -->
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6 hover:shadow-lg transition-shadow cursor-pointer"
                 wire:click="viewReport('multi_currency')">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-yellow-100 dark:bg-yellow-900 rounded-lg flex items-center justify-center">
                            <x-heroicon-o-currency-dollar class="w-5 h-5 text-yellow-600 dark:text-yellow-400" />
                        </div>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white">
                            Multi-Currency Balance
                        </h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            Currency balances & rates
                        </p>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600 dark:text-gray-400">Last updated</span>
                        <span class="text-sm font-medium text-gray-900 dark:text-white">
                            {{ now()->format('M j, Y') }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Transaction Analysis Report -->
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6 hover:shadow-lg transition-shadow cursor-pointer"
                 wire:click="viewReport('transaction_analysis')">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-red-100 dark:bg-red-900 rounded-lg flex items-center justify-center">
                            <x-heroicon-o-chart-bar class="w-5 h-5 text-red-600 dark:text-red-400" />
                        </div>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white">
                            Transaction Analysis
                        </h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            Patterns & peak hours
                        </p>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600 dark:text-gray-400">Last updated</span>
                        <span class="text-sm font-medium text-gray-900 dark:text-white">
                            {{ now()->format('M j, Y') }}
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <!-- Active Shifts -->
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                            <x-heroicon-o-play class="w-5 h-5 text-blue-600 dark:text-blue-400" />
                        </div>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white">
                            Active Shifts
                        </h3>
                        <p class="text-2xl font-bold text-blue-600 dark:text-blue-400">
                            {{ \App\Models\CashierShift::where('status', 'open')->count() }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Total Drawers -->
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                            <x-heroicon-o-building-office class="w-5 h-5 text-green-600 dark:text-green-400" />
                        </div>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white">
                            Total Drawers
                        </h3>
                        <p class="text-2xl font-bold text-green-600 dark:text-green-400">
                            {{ \App\Models\CashDrawer::count() }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Today's Transactions -->
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-yellow-100 dark:bg-yellow-900 rounded-lg flex items-center justify-center">
                            <x-heroicon-o-arrow-path class="w-5 h-5 text-yellow-600 dark:text-yellow-400" />
                        </div>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white">
                            Today's Transactions
                        </h3>
                        <p class="text-2xl font-bold text-yellow-600 dark:text-yellow-400">
                            {{ \App\Models\CashTransaction::whereDate('created_at', today())->count() }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Report Modal -->
    <x-filament::modal id="report-modal" :width="'7xl'">
        <x-slot name="heading">
            {{ $reportData['title'] ?? 'Report' }}
        </x-slot>

        <div class="space-y-6">
            @if($reportData)
                @switch($reportData['type'])
                    @case('daily_summary')
                        @include('filament.pages.reports.daily-summary')
                    @break

                    @case('shift_performance')
                        @include('filament.pages.reports.shift-performance')
                    @break

                    @case('multi_currency')
                        @include('filament.pages.reports.multi-currency')
                    @break

                    @case('transaction_analysis')
                        @include('filament.pages.reports.transaction-analysis')
                    @break
                @endswitch
            @endif
        </div>

        <x-slot name="footerActions">
            <x-filament::button
                color="gray"
                x-on:click="close"
            >
                Close
            </x-filament::button>
            
            <x-filament::button
                color="primary"
                icon="heroicon-o-document-arrow-down"
                wire:click="exportReport"
            >
                Export Report
            </x-filament::button>
        </x-slot>
    </x-filament::modal>
</x-filament-panels::page>