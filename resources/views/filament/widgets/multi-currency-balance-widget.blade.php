<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Multi-Currency Cash Drawer Balances
        </x-slot>
        
        <x-slot name="description">
            Current balances across all currencies for each cash drawer
        </x-slot>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            @foreach($this->getViewData()['drawers'] as $drawerData)
                <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                            {{ $drawerData['drawer']->name }}
                        </h3>
                        <div class="flex items-center space-x-2">
                            @if($drawerData['hasOpenShifts'])
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-800 dark:text-green-100">
                                    <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                    </svg>
                                    Active
                                </span>
                            @else
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300">
                                    <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                                    </svg>
                                    Inactive
                                </span>
                            @endif
                        </div>
                    </div>
                    
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                        {{ $drawerData['drawer']->location }}
                    </p>
                    
                    @if($drawerData['hasOpenShifts'])
                        <div class="space-y-3">
                            @foreach($drawerData['balances'] as $currencyData)
                                <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                    <div class="flex items-center space-x-3">
                                        <div class="flex items-center space-x-2">
                                            <span class="text-lg">{{ $currencyData['currency']->getSymbol() }}</span>
                                            <span class="font-medium text-gray-900 dark:text-white">{{ $currencyData['currency']->value }}</span>
                                        </div>
                                        <div class="text-sm text-gray-500 dark:text-gray-400">
                                            {{ $currencyData['shifts'] }} shift{{ $currencyData['shifts'] > 1 ? 's' : '' }}
                                            • {{ $currencyData['transactions'] }} transaction{{ $currencyData['transactions'] > 1 ? 's' : '' }}
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-lg font-semibold text-gray-900 dark:text-white">
                                            {{ $currencyData['currency']->formatAmount($currencyData['balance']) }}
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="text-center py-8">
                            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                            </svg>
                            <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-white">No active shifts</h3>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Start a shift to see currency balances.</p>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
        
        @if(empty($this->getViewData()['drawers']))
            <div class="text-center py-12">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                </svg>
                <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-white">No cash drawers</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Create cash drawers to start managing multi-currency balances.</p>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
