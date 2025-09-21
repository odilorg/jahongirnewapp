<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Current Shift Status
        </x-slot>

        @if($hasOpenShift)
            <div class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="bg-green-50 dark:bg-green-900/20 p-4 rounded-lg">
                        <div class="text-sm font-medium text-green-800 dark:text-green-200">
                            Cash In
                        </div>
                        <div class="text-2xl font-bold text-green-900 dark:text-green-100">
                            {{ number_format($totalCashIn, 2) }} UZS
                        </div>
                    </div>
                    
                    <div class="bg-red-50 dark:bg-red-900/20 p-4 rounded-lg">
                        <div class="text-sm font-medium text-red-800 dark:text-red-200">
                            Cash Out
                        </div>
                        <div class="text-2xl font-bold text-red-900 dark:text-red-100">
                            {{ number_format($totalCashOut, 2) }} UZS
                        </div>
                    </div>
                    
                    <div class="bg-blue-50 dark:bg-blue-900/20 p-4 rounded-lg">
                        <div class="text-sm font-medium text-blue-800 dark:text-blue-200">
                            Expected Balance
                        </div>
                        <div class="text-2xl font-bold text-blue-900 dark:text-blue-100">
                            {{ number_format($expectedBalance, 2) }} UZS
                        </div>
                    </div>
                </div>

                <div class="flex gap-2">
                    <x-filament::button
                        color="success"
                        icon="heroicon-o-plus"
                        href="{{ route('filament.admin.resources.cashier-shifts.start-shift') }}"
                    >
                        Add Transaction
                    </x-filament::button>
                    
                    <x-filament::button
                        color="danger"
                        icon="heroicon-o-stop"
                        href="{{ route('filament.admin.resources.cashier-shifts.close-shift', ['record' => $currentShift->id]) }}"
                    >
                        Close Shift
                    </x-filament::button>
                </div>
            </div>
        @else
            <div class="text-center py-8">
                <div class="text-gray-500 dark:text-gray-400 mb-4">
                    <x-heroicon-o-play class="w-12 h-12 mx-auto" />
                </div>
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">
                    No Open Shift
                </h3>
                <p class="text-gray-500 dark:text-gray-400 mb-4">
                    Start a new shift to begin recording cash transactions.
                </p>
                <x-filament::button
                    color="primary"
                    icon="heroicon-o-play"
                    href="{{ route('filament.admin.resources.cashier-shifts.start-shift') }}"
                >
                    Start New Shift
                </x-filament::button>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
