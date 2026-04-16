<x-filament-panels::page>
    {{-- Toolbar: navigation + filters --}}
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div class="flex items-center gap-2">
            <button type="button" wire:click="previousWeek"
                class="rounded-md bg-white dark:bg-gray-800 px-3 py-1.5 text-sm font-medium ring-1 ring-gray-200 dark:ring-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700">
                ← Prev
            </button>
            <button type="button" wire:click="thisWeek"
                class="rounded-md bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-500">
                This week
            </button>
            <button type="button" wire:click="nextWeek"
                class="rounded-md bg-white dark:bg-gray-800 px-3 py-1.5 text-sm font-medium ring-1 ring-gray-200 dark:ring-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700">
                Next →
            </button>
            <span class="ml-3 text-sm text-gray-500 dark:text-gray-400">
                {{ $data['from']->format('M j') }} – {{ $data['to']->format('M j, Y') }}
            </span>
        </div>
        <div class="flex items-center gap-3 text-sm text-gray-700 dark:text-gray-300">
            <label class="inline-flex items-center gap-2 cursor-pointer">
                <input type="checkbox" wire:model.live="includeAwaitingPayment"
                    class="rounded border-gray-300 dark:border-gray-600 text-primary-600 focus:ring-primary-500">
                Include awaiting payment
            </label>
        </div>
    </div>

    {{-- Calendar grid --}}
    <div class="overflow-x-auto rounded-lg ring-1 ring-gray-200 dark:ring-gray-700 bg-white dark:bg-gray-900">
        <div class="grid min-w-[1000px]" style="grid-template-columns: 240px repeat(7, minmax(120px, 1fr));">

            {{-- Header row --}}
            <div class="bg-gray-50 dark:bg-gray-800 px-3 py-2 text-xs font-semibold uppercase text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                Tour
            </div>
            @foreach ($data['days'] as $day)
                <div @class([
                    'bg-gray-50 dark:bg-gray-800 px-2 py-2 text-center text-xs font-semibold border-b border-l-2 border-gray-200 dark:border-gray-700 border-l-gray-300 dark:border-l-gray-600',
                    'bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300' => $day->isToday(),
                    'text-gray-500 dark:text-gray-400' => ! $day->isToday(),
                ])>
                    {{ $day->format('D') }}<br>
                    <span class="text-base">{{ $day->format('j') }}</span>
                </div>
            @endforeach

            {{-- Tour rows --}}
            @forelse ($data['rows'] as $row)
                <div class="px-3 py-2 text-sm font-medium text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 flex items-center">
                    {{ $row['name'] }}
                </div>
                @for ($i = 0; $i < 7; $i++)
                    <div class="border-b border-l-2 border-gray-200 dark:border-gray-700 border-l-gray-300 dark:border-l-gray-600 p-1.5 min-h-[88px] space-y-1.5">
                        @foreach ($row['chips'] as $chip)
                            @if ($chip['day_index'] === $i)
                                @php
                                    $bgClass = match ($chip['status']) {
                                        'confirmed'        => 'bg-success-50 dark:bg-success-900/30 border-success-300 dark:border-success-700 hover:bg-success-100 dark:hover:bg-success-900/50',
                                        'awaiting_payment' => 'bg-warning-50 dark:bg-warning-900/30 border-warning-300 dark:border-warning-700 hover:bg-warning-100 dark:hover:bg-warning-900/50',
                                        default            => 'bg-gray-50 dark:bg-gray-800 border-gray-300 dark:border-gray-700',
                                    };

                                    $tooltip = collect([
                                        $chip['reference'] . ' · ' . $chip['customer_name']
                                            . ($chip['customer_country'] ? ' (' . $chip['customer_country'] . ')' : ''),
                                        '📅 ' . $chip['travel_date'] . ' · ' . $chip['duration'] . ' day(s)',
                                        '👥 ' . $chip['pax_label'] . ' pax',
                                        $chip['pickup_time'] ? '🕐 ' . $chip['pickup_time'] : null,
                                        $chip['pickup_point'] ? '📍 ' . $chip['pickup_point'] : null,
                                        $chip['driver_name'] ? '🚐 Driver: ' . $chip['driver_name'] : null,
                                        $chip['guide_name'] ? '🧑‍✈️ Guide: ' . $chip['guide_name'] : null,
                                        count($chip['accommodations']) ? '🏕 ' . implode(', ', $chip['accommodations']) : null,
                                        $chip['paid_at'] ? '💰 Paid ' . $chip['paid_at'] : null,
                                    ])->filter()->implode("\n");
                                @endphp
                                <a href="{{ $chip['detail_url'] }}" target="_blank" rel="noopener"
                                    title="{{ $tooltip }}"
                                    class="block rounded-md border px-2 py-1.5 text-xs cursor-pointer transition relative {{ $bgClass }}">
                                    {{-- Row 1: Name + pax + source --}}
                                    <div class="flex items-center justify-between gap-1">
                                        <span class="font-semibold text-gray-900 dark:text-gray-100 truncate">
                                            {{ $chip['customer_name'] }}
                                        </span>
                                        <span class="shrink-0 text-[9px] font-bold px-1 py-0.5 rounded
                                            {{ $chip['source_badge'] === 'GYG' ? 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300' : 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300' }}">
                                            {{ $chip['source_badge'] }}
                                        </span>
                                    </div>

                                    {{-- Row 2: Time + pax + payment --}}
                                    <div class="flex items-center gap-1.5 text-[11px] text-gray-800 dark:text-gray-100 mt-0.5">
                                        <span>⏰ {{ $chip['pickup_time'] ?? '—' }}</span>
                                        <span>·</span>
                                        <span>{{ $chip['pax_label'] }} pax</span>
                                        <span>
                                            @if ($chip['paid_at'])
                                                💰
                                            @elseif ($chip['status'] === 'awaiting_payment')
                                                ⏳
                                            @elseif ($chip['payment_method'] === 'cash')
                                                💵
                                            @endif
                                        </span>
                                    </div>

                                    {{-- Row 3: Driver + guide --}}
                                    <div class="text-[11px] text-gray-800 dark:text-gray-200 mt-0.5 truncate">
                                        @if ($chip['driver_name'])
                                            🚗 {{ $chip['driver_name'] }}
                                        @else
                                            <span class="text-danger-500">🚗 —</span>
                                        @endif
                                        @if ($chip['guide_name'])
                                            · 🧭 {{ $chip['guide_name'] }}
                                        @endif
                                    </div>

                                    {{-- Warning dot: top-right corner --}}
                                    @if (! empty($chip['warnings']))
                                        <span class="absolute -top-1 -right-1 w-3 h-3 rounded-full
                                            {{ in_array('no driver', $chip['warnings']) ? 'bg-danger-500' : 'bg-warning-500' }}"
                                            title="{{ implode(', ', $chip['warnings']) }}">
                                        </span>
                                    @endif
                                </a>
                            @endif
                        @endforeach
                    </div>
                @endfor
            @empty
                <div class="col-span-8 py-16 text-center text-sm text-gray-500 dark:text-gray-400">
                    <p>No bookings in this week.</p>
                    <p class="mt-1 text-xs">Try a different week or enable "Include awaiting payment" above.</p>
                </div>
            @endforelse
        </div>
    </div>

    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
        Click any chip to open the inquiry detail in a new tab. Hover for full tour info.
    </p>
</x-filament-panels::page>
