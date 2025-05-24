<x-filament::page>
    <x-filament::card class="p-0 overflow-hidden">
        <div class="bg-white text-gray-900 dark:bg-white dark:text-gray-900 p-6">

            {{-- figure out which month to show (YYYY-MM) --}}
            @php
                $currentMonth = request('month', now()->format('Y-m'));
            @endphp

            {{-- ───────────── Month picker ───────────── --}}
            <form method="GET" class="mb-6 flex items-center gap-4">
                <label for="month" class="text-sm font-semibold">Month:</label>

                <input  id="month"
                        name="month"
                        type="month"
                        value="{{ $currentMonth }}"
                        class="filament-input w-auto" />

                <x-filament::button type="submit" color="primary">
                    Go
                </x-filament::button>
            </form>

            {{-- ───────────── Calendar component ───────────── --}}
            <livewire:tour-calendar :month="$currentMonth" />

        </div>
    </x-filament::card>
</x-filament::page>