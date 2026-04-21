<x-filament-panels::page>
    @if($existingShift)
        <x-filament::section>
            <x-slot name="heading">
                You Already Have an Open Shift
            </x-slot>

            <div class="space-y-4">
                <div class="flex items-center gap-2 text-warning-600 dark:text-warning-400">
                    <x-heroicon-o-exclamation-triangle class="w-6 h-6"/>
                    <p class="font-semibold">Cannot start a new shift</p>
                </div>

                <p class="text-sm text-gray-600 dark:text-gray-400">
                    You already have an open shift on drawer <strong>{{ $existingShift->cashDrawer->name }}</strong>.
                </p>

                <div class="flex gap-3">
                    <x-filament::button
                        :href="route('filament.admin.resources.cashier-shifts.view', ['record' => $existingShift->id])"
                        color="primary"
                    >
                        View Current Shift
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>
    @elseif(isset($autoSelectedInfo['error']))
        <x-filament::section>
            <x-slot name="heading">
                Cannot Start Shift
            </x-slot>

            <div class="space-y-4">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ $autoSelectedInfo['error'] }}
                </p>
            </div>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">
                Ready to Start Your Shift
            </x-slot>

            <div class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="bg-gray-50 dark:bg-gray-800 p-4 rounded-lg">
                        <h3 class="font-semibold text-sm mb-2">Location</h3>
                        <p class="text-sm">{{ $autoSelectedInfo['location'] ?? 'Auto-selected' }}</p>
                    </div>

                    <div class="bg-gray-50 dark:bg-gray-800 p-4 rounded-lg">
                        <h3 class="font-semibold text-sm mb-2">Cash Drawer</h3>
                        <p class="text-sm">{{ $autoSelectedInfo['drawer'] ?? 'Auto-selected' }}</p>
                    </div>
                </div>

                @if(!empty($autoSelectedInfo['balances']))
                    <div class="bg-success-50 dark:bg-success-900/20 p-4 rounded-lg">
                        <h3 class="font-semibold text-sm mb-3">Starting Balances (Carried Over)</h3>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                            @foreach($autoSelectedInfo['balances'] as $currency => $amount)
                                <div class="bg-white dark:bg-gray-800 p-3 rounded">
                                    <p class="text-xs text-gray-600 dark:text-gray-400">{{ $currency }}</p>
                                    <p class="text-lg font-bold">{{ $amount }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @else
                    <div class="bg-info-50 dark:bg-info-900/20 p-4 rounded-lg">
                        <p class="text-sm">No previous shift. Starting with zero balances.</p>
                    </div>
                @endif

                <div class="bg-primary-50 dark:bg-primary-900/20 p-4 rounded-lg">
                    <p class="font-semibold">Zero Manual Input Required</p>
                    <p class="text-sm mt-2">Everything is automatic! Just click "Start Shift" above.</p>
                </div>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
