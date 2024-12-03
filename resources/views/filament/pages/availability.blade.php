<x-filament::page>
    <div class="space-y-6">
        <form wire:submit.prevent="submit" class="space-y-4">
            {{ $this->form }}
            <div class="flex justify-end">
                <x-filament::button type="submit">
                    Search
                </x-filament::button>
            </div>
        </form>

        @if (count($available_rooms) > 0)
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
                                Total Units
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
                        @foreach ($available_rooms as $room)
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-100">
                                    {{ $room['name'] }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-100">
                                    {{ $room['available_qty'] }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-100">
                                    <select class="bg-gray-800 text-gray-100 rounded p-2 focus:outline-none focus:ring">
                                        @for ($i = 0; $i <= $room['total_qty']; $i++)
                                            <option value="{{ $i }}">{{ $i }}</option>
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
        @else
            <div class="p-4 bg-gray-900 rounded-lg shadow">
                <p class="text-gray-100 text-sm">No available rooms for the selected dates.</p>
            </div>
        @endif
    </div>
</x-filament::page>
