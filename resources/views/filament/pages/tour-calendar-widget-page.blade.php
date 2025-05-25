<x-filament::page>

    <x-filament::card class="p-0 overflow-hidden">
        <div class="p-6 bg-white dark:bg-gray-900 dark:text-gray-100">
            @php
                $currentMonth = request('month', now()->format('Y-m'));
            @endphp

            {{-- Month selector + Today --}}
            <div class="mb-6 flex items-center gap-6">
                {{-- Go form... --}}
                <form method="GET" class="flex items-center gap-4">
                    <label for="month" class="text-sm font-semibold">Month:</label>
                    <x-filament::input id="month" name="month" type="month"
                        :value="$currentMonth" class="w-auto" />
                    <x-filament::button type="submit" color="primary">Go</x-filament::button>
                </form>
                <form method="GET">
                    <input type="hidden" name="month" value="{{ now()->format('Y-m') }}">
                    <x-filament::button type="submit" color="secondary">Today</x-filament::button>
                </form>
            </div>

            {{-- Embed your Livewire widget here --}}
            @livewire(\App\Livewire\TourCalendarWidget::class, ['month' => $currentMonth])

        </div>
    </x-filament::card>

</x-filament::page>
