<x-filament::page>

    {{-- ── Filter Form ──────────────────────────────────────────── --}}
    <form wire:submit.prevent="createReport">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            {{ $this->form }}
        </div>
        <div class="mt-4 flex items-center gap-3">
            <x-filament::button type="submit" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="createReport">Generate Report</span>
                <span wire:loading wire:target="createReport">Generating…</span>
            </x-filament::button>

            @if ($reportData)
                <x-filament::button
                    color="gray"
                    wire:click="exportCsv"
                    icon="heroicon-o-arrow-down-tray"
                >
                    Export CSV
                </x-filament::button>

                <label class="flex items-center gap-2 text-sm text-gray-400 cursor-pointer ml-2">
                    <input type="checkbox" wire:model.live="showZeroRows" class="rounded border-gray-600">
                    Show zero rows
                </label>
            @endif
        </div>
    </form>

    {{-- ── Report Table ─────────────────────────────────────────── --}}
    @if ($reportData)
        @php
            $rows     = $reportData['rows'];
            $totalUzs = $reportData['total_uzs'];
            $totalUsd = $reportData['total_usd'];
            $showAll  = $hotel_id === 'all';

            // Top-3 categories by amount for highlighting
            $sorted = collect($rows)->sortByDesc('Sum')->keys()->take(3)->toArray();
        @endphp

        {{-- Report header --}}
        <div class="mt-6 mb-3 flex items-center justify-between">
            <div>
                <h2 class="text-lg font-bold text-white">
                    {{ \Carbon\Carbon::parse($start_date)->format('M j, Y') }}
                    –
                    {{ \Carbon\Carbon::parse($end_date)->format('M j, Y') }}
                    ·
                    {{ $hotel_id === 'all' ? 'All Hotels' : ($hotels[$hotel_id] ?? '—') }}
                </h2>
                <p class="text-xs text-gray-400 mt-0.5">
                    USD rate: {{ number_format($usdRate, 0) }} UZS
                    (as of {{ \Carbon\Carbon::parse($end_date)->format('M j, Y') }})
                </p>
            </div>
            <div class="text-right text-sm text-gray-300">
                <span class="font-semibold text-white">{{ number_format($totalUzs, 0) }}</span> UZS
                &nbsp;/&nbsp;
                <span class="font-semibold text-white">{{ number_format($totalUsd, 0) }}</span> USD
            </div>
        </div>

        <div class="overflow-x-auto rounded-xl border border-gray-700">
            <table class="w-full text-sm text-left">
                <thead class="bg-gray-800 text-gray-300 uppercase text-xs">
                    <tr>
                        <th class="px-4 py-3 w-6"></th>
                        <th class="px-4 py-3 cursor-pointer select-none" wire:click="sortTable('category')">
                            Category
                            <span class="text-gray-500">{{ $sortBy === 'category' ? ($sortDir === 'desc' ? '▼' : '▲') : '↕' }}</span>
                        </th>
                        @if ($showAll)
                            @foreach ($hotels as $hId => $hName)
                                <th class="px-4 py-3 text-right">{{ $hName }}</th>
                            @endforeach
                        @endif
                        <th class="px-4 py-3 text-right cursor-pointer select-none" wire:click="sortTable('Sum')">
                            Total UZS
                            <span class="text-gray-500">{{ $sortBy === 'Sum' ? ($sortDir === 'desc' ? '▼' : '▲') : '↕' }}</span>
                        </th>
                        <th class="px-4 py-3 text-right">Total USD</th>
                        <th class="px-4 py-3 text-right">% Total</th>
                        <th class="px-4 py-3 text-right text-gray-500">#</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-700">
                    @foreach ($rows as $category => $data)
                        @if (!$showZeroRows && $data['Sum'] == 0)
                            @continue
                        @endif

                        @php $isTop3 = in_array($category, $sorted); @endphp
                        @php $isExpanded = $expandedCategory === $category; @endphp

                        {{-- Category summary row --}}
                        <tr
                            class="cursor-pointer transition-colors {{ $isExpanded ? 'bg-gray-700' : 'bg-gray-900 hover:bg-gray-800' }}"
                            wire:click="toggleCategory('{{ addslashes($category) }}')"
                            wire:loading.class="opacity-50"
                            wire:target="toggleCategory('{{ addslashes($category) }}')"
                        >
                            {{-- Expand icon --}}
                            <td class="px-4 py-3 text-gray-400 text-center">
                                {{ $isExpanded ? '▼' : '▶' }}
                            </td>

                            {{-- Category name --}}
                            <td class="px-4 py-3 font-medium {{ $isTop3 ? 'text-amber-400' : 'text-gray-100' }}">
                                {{ $category }}
                            </td>

                            {{-- Per-hotel columns --}}
                            @if ($showAll)
                                @foreach ($hotels as $hId => $hName)
                                    <td class="px-4 py-3 text-right text-gray-300">
                                        {{ ($data['hotels'][$hName] ?? 0) > 0 ? number_format($data['hotels'][$hName], 0) : '—' }}
                                    </td>
                                @endforeach
                            @endif

                            {{-- Total UZS --}}
                            <td class="px-4 py-3 text-right font-semibold {{ $isTop3 ? 'text-amber-300' : 'text-gray-100' }}">
                                {{ number_format($data['Sum'], 0) }}
                            </td>

                            {{-- Total USD --}}
                            <td class="px-4 py-3 text-right text-gray-400">
                                {{ number_format($data['Sum_USD'], 0) }}
                            </td>

                            {{-- % of total --}}
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <div class="h-1.5 rounded-full bg-gray-600 w-16 overflow-hidden">
                                        <div
                                            class="h-full rounded-full {{ $isTop3 ? 'bg-amber-400' : 'bg-blue-500' }}"
                                            style="width: {{ min($data['pct'], 100) }}%"
                                        ></div>
                                    </div>
                                    <span class="text-gray-300 text-xs w-10 text-right">{{ $data['pct'] }}%</span>
                                </div>
                            </td>

                            {{-- Row count --}}
                            <td class="px-4 py-3 text-right text-gray-500 text-xs">
                                {{ $data['row_count'] }}
                            </td>
                        </tr>

                        {{-- Drill-down rows --}}
                        @if ($isExpanded)
                            <tr class="bg-gray-800">
                                <td colspan="{{ $showAll ? (5 + count($hotels)) : 6 }}" class="px-0 py-0">
                                    <div wire:loading wire:target="toggleCategory('{{ addslashes($category) }}')" class="px-8 py-4 text-gray-400 text-sm">
                                        Loading…
                                    </div>
                                    @if (count($expandedRows))
                                        <table class="w-full text-xs">
                                            <thead>
                                                <tr class="text-gray-500 border-b border-gray-700">
                                                    <th class="px-8 py-2 text-left">Date</th>
                                                    <th class="px-4 py-2 text-left">Name</th>
                                                    <th class="px-4 py-2 text-left">Payment</th>
                                                    <th class="px-4 py-2 text-left">Hotel</th>
                                                    <th class="px-4 py-2 text-right">UZS</th>
                                                    <th class="px-4 py-2 text-right">USD</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-700">
                                                @foreach ($expandedRows as $row)
                                                    <tr class="hover:bg-gray-750 text-gray-300">
                                                        <td class="px-8 py-2 text-gray-500">{{ \Carbon\Carbon::parse($row['date'])->format('M j') }}</td>
                                                        <td class="px-4 py-2">{{ $row['name'] }}</td>
                                                        <td class="px-4 py-2">
                                                            <span class="rounded px-1.5 py-0.5 text-xs font-medium
                                                                {{ $row['payment_type'] === 'naqd'   ? 'bg-green-900 text-green-300'  : '' }}
                                                                {{ $row['payment_type'] === 'karta'  ? 'bg-blue-900 text-blue-300'   : '' }}
                                                                {{ $row['payment_type'] === 'perech' ? 'bg-purple-900 text-purple-300' : '' }}
                                                            ">
                                                                {{ $row['payment_type'] }}
                                                            </span>
                                                        </td>
                                                        <td class="px-4 py-2 text-gray-400">{{ $row['hotel'] }}</td>
                                                        <td class="px-4 py-2 text-right font-medium">{{ number_format($row['amount_uzs'], 0) }}</td>
                                                        <td class="px-4 py-2 text-right text-gray-500">{{ number_format($row['amount_usd'], 1) }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    @endif
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>

                {{-- ── Totals footer ──────────────────────────────── --}}
                <tfoot class="bg-gray-800 border-t-2 border-gray-600 font-bold text-gray-100">
                    <tr>
                        <td class="px-4 py-3"></td>
                        <td class="px-4 py-3">Total</td>
                        @if ($showAll)
                            @foreach ($hotels as $hId => $hName)
                                @php
                                    $hotelTotal = collect($rows)->sum(fn($r) => $r['hotels'][$hName] ?? 0);
                                @endphp
                                <td class="px-4 py-3 text-right">{{ number_format($hotelTotal, 0) }}</td>
                            @endforeach
                        @endif
                        <td class="px-4 py-3 text-right text-amber-300">{{ number_format($totalUzs, 0) }}</td>
                        <td class="px-4 py-3 text-right text-gray-300">{{ number_format($totalUsd, 0) }}</td>
                        <td class="px-4 py-3 text-right">100%</td>
                        <td class="px-4 py-3 text-right text-gray-500 text-xs">
                            {{ collect($rows)->sum('row_count') }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif

</x-filament::page>
