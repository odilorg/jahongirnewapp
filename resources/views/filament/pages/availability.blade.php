<x-filament::page>
    <div class="space-y-6">
        {{-- Search Form --}}
        <form wire:submit.prevent="submit" class="space-y-4">
            {{ $this->form }}
            <div class="flex justify-end">
                <x-filament::button type="submit">
                    Search
                </x-filament::button>
            </div>
        </form>

        {{-- Table Form for Post Submission --}}
        @if (count($available_rooms) > 0)
            <form wire:submit.prevent="saveBookings" class="space-y-4">
                <div class="overflow-x-auto bg-gray-900 shadow rounded-lg">
                    <table class="min-w-full divide-y divide-gray-700">
                        <thead>
                            <tr class="bg-gray-800">
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                    Room Name
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                    Available Units
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                    Price
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                    Switching Required
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-gray-900 divide-y divide-gray-700">
                            @foreach ($available_rooms as $index => $room)
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-100">
                                        {{ $room['name'] }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-100">
                                        <select 
                                            wire:model.defer="selectedUnits.{{ $index }}" 
                                            class="rounded p-2 focus:outline-none focus:ring focus:ring-blue-500"
                                            style="background-color: #000000; color: #ffffff; border: 1px solid #444444;"
                                        >
                                            <option 
                                                value="" 
                                                style="background-color: #000000; color: #ffffff;"
                                            >
                                                Select units
                                            </option>
                                            @for ($i = 1; $i <= $room['available_qty']; $i++)
                                                <option 
                                                    value="{{ $i }}" 
                                                    style="background-color: #000000; color: #ffffff;"
                                                    onmouseover="this.style.backgroundColor='#444'; this.style.color='#ffffff';"
                                                    onmouseout="this.style.backgroundColor='#000000'; this.style.color='#ffffff';"
                                                >
                                                    {{ $i }}
                                                </option>
                                            @endfor
                                        </select>
                                    </td>
                                    
                                    
                                    
                                    
                                    
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-100">
                                        ${{ number_format($room['price'], 2) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-100">
                                        {{ $room['switching_required'] ? 'Yes' : 'No' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="flex justify-end mt-4">
                    <x-filament::button type="submit">
                        Save Bookings
                    </x-filament::button>
                </div>
            </form>
        @else
            <div class="p-4 bg-gray-900 rounded-lg shadow">
                <p class="text-gray-100 text-sm">No available rooms for the selected dates.</p>
            </div>
        @endif
    </div>
</x-filament::page>
