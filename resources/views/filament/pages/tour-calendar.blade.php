{{-- resources/views/filament/pages/tour-calendar.blade.php --}}
<x-filament::page>
    <x-filament::card class="p-0 overflow-hidden">
        <div class="p-6 bg-white dark:bg-gray-900 dark:text-gray-100">

            @php
                $currentMonth = request('month', now()->format('Y-m'));
            @endphp

            {{-- Month selector --}}
            <form method="GET" class="mb-6 flex items-center gap-4">
                <label for="month" class="text-sm font-semibold">Month:</label>

                {{-- Filament input = auto-themed --}}
                <x-filament::input
                    id="month"
                    name="month"
                    type="month"
                    :value="$currentMonth"
                    class="w-auto"
                />

                <x-filament::button type="submit" color="primary">Go</x-filament::button>
            </form>

            {{-- Calendar --}}
            <livewire:tour-calendar :month="$currentMonth" />
        </div>
    </x-filament::card>
</x-filament::page>
