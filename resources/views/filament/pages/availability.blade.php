<x-filament::page>
    <form wire:submit.prevent="submit" class="space-y-4">
        {{ $this->form }}

        <div class="flex justify-end">
            <button type="submit" class="px-4 py-2 bg-primary-500 text-white rounded shadow">
                Search
            </button>
        </div>
    </form>

    @if($available_rooms)
        <div class="mt-6">
            <h2 class="text-lg font-bold text-gray-100">Available Rooms:</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mt-4">
                @foreach($available_rooms as $room)
                    <div class="p-4 bg-gray-800 text-gray-100 rounded shadow hover:shadow-lg">
                        <h3 class="text-lg font-semibold">
                            {{ $room['name'] }} ({{ $room['available_qty'] }})
                        </h3>
                        @if($room['switching_required'])
                            <div class="text-sm text-yellow-400">Available with Switching</div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @else
        <div class="mt-6 text-red-500">No available rooms for the selected dates.</div>
    @endif
</x-filament::page>
