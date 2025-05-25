{{-- resources/views/filament/pages/tour-calendar.blade.php --}}
<x-filament::page>
    <x-filament::card class="p-0 overflow-hidden">
        <div class="p-6 bg-white dark:bg-gray-900 dark:text-gray-100">

            @php
                /* currently-selected month (falls back to this month) */
                $currentMonth = request('month', now()->format('Y-m'));
            @endphp

            {{-- ───────────────── Month selector + Today button ───────────────── --}}
            <div class="mb-6 flex items-center gap-6">

                {{-- ① Month → Go --}}
                <form method="GET" class="flex items-center gap-4">
                    <label for="month" class="text-sm font-semibold">Month:</label>

                    {{-- Filament input = auto-themed --}}
                    <x-filament::input
                        id="month"
                        name="month"
                        type="month"
                        :value="$currentMonth"
                        class="w-auto"
                    />

                    <x-filament::button type="submit" color="primary">
                        Go
                    </x-filament::button>
                </form>

                {{-- ② Today (reloads page with ?month=YYYY-MM for today) --}}
                <form method="GET">
                    <input type="hidden" name="month" value="{{ now()->format('Y-m') }}">
                    <x-filament::button type="submit" color="secondary">
                        Today
                    </x-filament::button>
                </form>
            </div>

            {{-- ───────────────── Calendar component ───────────────── --}}
            <livewire:tour-calendar :month="$currentMonth" />

            {{-- optional test component --}}
            <livewire:counter />

        </div>
    </x-filament::card>
</x-filament::page>
