<x-filament-panels::page>

    {{-- Phase 20 — Summary strip (always visible) --}}
    <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 16px;">
        <div style="flex: 1; min-width: 160px; background: #eff6ff; border-radius: 8px; padding: 10px 12px;">
            <div style="font-size: 10px; color: #1e40af; text-transform: uppercase; letter-spacing: 0.5px;">Today</div>
            <div style="font-size: 18px; font-weight: 700; color: #1e40af;">{{ $action['today_count'] }} tours · ${{ number_format($action['today_revenue'], 0) }}</div>
        </div>
        <div style="flex: 1; min-width: 160px; background: {{ $action['needs_action_count'] > 0 ? '#fee2e2' : '#dcfce7' }}; border-radius: 8px; padding: 10px 12px;">
            <div style="font-size: 10px; color: {{ $action['needs_action_count'] > 0 ? '#991b1b' : '#166534' }}; text-transform: uppercase; letter-spacing: 0.5px;">Needs Action</div>
            <div style="font-size: 18px; font-weight: 700; color: {{ $action['needs_action_count'] > 0 ? '#dc2626' : '#16a34a' }};">
                {{ $action['needs_action_count'] > 0 ? $action['needs_action_count'].' urgent' : 'All clear ✅' }}
            </div>
        </div>
        <div style="flex: 1; min-width: 160px; background: #fef3c7; border-radius: 8px; padding: 10px 12px;">
            <div style="font-size: 10px; color: #92400e; text-transform: uppercase; letter-spacing: 0.5px;">Tomorrow</div>
            <div style="font-size: 18px; font-weight: 700; color: #92400e;">{{ $action['tomorrow_count'] }} tours</div>
        </div>
        <div style="flex: 1; min-width: 160px; background: {{ $action['unclaimed_count'] > 0 ? '#fef3c7' : '#f3f4f6' }}; border-radius: 8px; padding: 10px 12px;">
            <div style="font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px;">Active Leads</div>
            <div style="font-size: 18px; font-weight: 700; color: {{ $action['unclaimed_count'] > 0 ? '#d97706' : '#6b7280' }};">{{ $action['unclaimed_count'] }}</div>
        </div>
        <div style="flex: 1; min-width: 160px; background: {{ ($action['reminders_due'] ?? 0) > 0 ? '#ede9fe' : '#f3f4f6' }}; border-radius: 8px; padding: 10px 12px;">
            <div style="font-size: 10px; color: #5b21b6; text-transform: uppercase; letter-spacing: 0.5px;">⏰ Reminders Due</div>
            <div style="font-size: 18px; font-weight: 700; color: {{ ($action['reminders_due'] ?? 0) > 0 ? '#7c3aed' : '#6b7280' }};">{{ $action['reminders_due'] ?? 0 }}</div>
        </div>
        <a href="{{ url('/admin/group-matches') }}" style="flex: 1; min-width: 160px; background: {{ ($action['group_matches'] ?? 0) > 0 ? '#faf5ff' : '#f3f4f6' }}; border-radius: 8px; padding: 10px 12px; text-decoration: none; border: {{ ($action['group_matches'] ?? 0) > 0 ? '1px solid #7c3aed' : '1px solid transparent' }};">
            <div style="font-size: 10px; color: #5b21b6; text-transform: uppercase; letter-spacing: 0.5px;">🎯 Group Matches</div>
            <div style="font-size: 18px; font-weight: 700; color: {{ ($action['group_matches'] ?? 0) > 0 ? '#7c3aed' : '#6b7280' }};">{{ $action['group_matches'] ?? 0 }}</div>
        </a>
    </div>

    {{-- Phase 20 — Action view (default) with priority zones --}}
    @if ($viewMode === 'action')
        @include('filament.pages.tour-calendar-action')

        <div style="text-align: center; margin-top: 16px;">
            <button type="button" wire:click="toggleViewMode"
                class="text-xs font-medium px-3 py-1.5 rounded-md"
                style="background: #e5e7eb; color: #374151;">
                📅 Switch to Week Grid View
            </button>
        </div>
    @else

    <div style="text-align: center; margin-bottom: 8px;">
        <button type="button" wire:click="toggleViewMode"
            class="text-xs font-medium px-3 py-1.5 rounded-md"
            style="background: #3b82f6; color: white;">
            🎯 Back to Dispatch Board
        </button>
    </div>

    {{-- Toolbar: navigation + filters --}}
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div class="flex items-center gap-2">
            <button type="button" wire:click="previousWeek"
                class="rounded-md bg-white dark:bg-gray-800 px-3 py-1.5 text-sm font-medium ring-1 ring-gray-200 dark:ring-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700">
                ← Prev
            </button>
            <button type="button" wire:click="thisWeek"
                class="rounded-md bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-500">
                Today
            </button>
            <button type="button" wire:click="nextWeek"
                class="rounded-md bg-white dark:bg-gray-800 px-3 py-1.5 text-sm font-medium ring-1 ring-gray-200 dark:ring-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700">
                Next →
            </button>
            <span class="ml-3 text-sm text-gray-500 dark:text-gray-400">
                {{ $data['from']->format('M j') }} – {{ $data['to']->format('M j, Y') }}
            </span>
        </div>
        <div class="flex items-center gap-4 text-xs text-gray-600 dark:text-gray-300 flex-wrap">
            <span class="inline-flex items-center gap-1"><span class="inline-block w-3 h-3 rounded" style="background:#dcfce7;border:1px solid #4ade80;"></span> Paid & ready</span>
            <span class="inline-flex items-center gap-1"><span class="inline-block w-3 h-3 rounded" style="background:#dcfce7;border:1px solid #4ade80;border-left:3px solid #ef4444;"></span> Paid, needs attention</span>
            <span class="inline-flex items-center gap-1"><span class="inline-block w-3 h-3 rounded" style="background:#fef3c7;border:1px solid #f59e0b;"></span> Awaiting payment</span>
            <span class="inline-flex items-center gap-1"><span class="inline-block w-3 h-3 rounded" style="background:#dbeafe;border:1px solid #60a5fa;"></span> Confirmed (pay offline)</span>
            <span class="inline-flex items-center gap-1"><span class="inline-block w-3 h-3 rounded" style="background:#f3f4f6;border:1px dashed #9ca3af;"></span> Lead</span>
            <span class="inline-flex items-center gap-1">👤 Private · 👥 Group</span>
            <span class="inline-flex items-center gap-1">🟠 GYG · 🔵 Web · 🟢 WA</span>
            <label class="inline-flex items-center gap-1.5 cursor-pointer ml-2">
                <input type="checkbox" wire:model.live="showLeads"
                    class="rounded border-gray-300 dark:border-gray-600 text-primary-600 focus:ring-primary-500">
                Show leads
            </label>
            <label class="inline-flex items-center gap-1.5 cursor-pointer">
                <input type="checkbox" wire:model.live="mineOnly"
                    class="rounded border-gray-300 dark:border-gray-600 text-primary-600 focus:ring-primary-500">
                My leads only
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
                <div style="border-left: 3px solid rgba(156, 163, 175, 0.5);" @class([
                    'bg-gray-50 dark:bg-gray-800 px-2 py-2 text-center text-xs font-semibold border-b border-gray-200 dark:border-gray-700',
                    'bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300' => $day->isToday(),
                    'text-gray-500 dark:text-gray-400' => ! $day->isToday(),
                ])>
                    {{ $day->format('D') }}<br>
                    <span class="text-base">{{ $day->format('j') }}</span>
                    <div style="font-size: 9px; font-weight: 500; color: #6b7280; text-transform: uppercase; margin-top: 2px; letter-spacing: 0.3px;">
                        {{ $day->format('M') }}
                    </div>
                </div>
            @endforeach

            {{-- Tour rows --}}
            @forelse ($data['rows'] as $row)
                <div class="px-3 py-2 text-sm font-medium text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 flex items-center">
                    {{ $row['name'] }}
                </div>
                @for ($i = 0; $i < 7; $i++)
                    @php $isToday = $data['days'][$i]->isToday(); @endphp
                    <div class="border-b border-gray-200 dark:border-gray-700 p-1.5 min-h-[88px] space-y-1.5"
                         style="border-left: 3px solid rgba(156, 163, 175, 0.5); {{ $isToday ? 'background: rgba(249, 115, 22, 0.06);' : '' }}">
                        @foreach ($row['chips'] as $chip)
                            @if ($chip['day_index'] === $i)
                                @php
                                    // Inline styles bypass Tailwind purge — Filament's build
                                    // strips standard Tailwind color classes not in safelist.
                                    $chipStyle = match ($chip['display_state']) {
                                        'ready'                => 'background:#dcfce7;border-color:#4ade80;',              // green
                                        'paid_needs_attention' => 'background:#dcfce7;border-color:#4ade80;border-left:4px solid #ef4444;', // green + red accent
                                        'awaiting_payment'     => 'background:#fef3c7;border-color:#f59e0b;',              // amber
                                        'confirmed_offline'    => 'background:#dbeafe;border-color:#60a5fa;',              // blue
                                        'lead'                 => 'background:#f3f4f6;border-color:#9ca3af;border-style:dashed;', // gray dashed
                                        default                => 'background:#f3f4f6;border-color:#d1d5db;',
                                    };
$bgClass = 'hover:opacity-90 transition-opacity';

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
                                <div wire:click="openInquiry({{ $chip['id'] }})"
                                    style="{{ $chipStyle }}"
                                    title="{{ $tooltip }}"
                                    class="block rounded-md border px-2 py-1.5 text-xs cursor-pointer relative {{ $bgClass }}">
                                    {{-- Operator badge — corner overlay, no layout impact --}}
                                    @if ($chip['assigned_initials'])
                                        <span title="Assigned: {{ $chip['assigned_to'] }}"
                                            style="position: absolute; top: 2px; right: 2px; font-size: 8px; font-weight: 700; background: #e0e7ff; color: #3730a3; padding: 1px 4px; border-radius: 3px; line-height: 1; z-index: 1;">
                                            {{ $chip['assigned_initials'] }}
                                        </span>
                                    @endif

                                    {{-- Row 1: Name + type/source icons --}}
                                    <div class="flex items-center justify-between gap-1" style="padding-right: {{ $chip['assigned_initials'] ? '24px' : '0' }};">
                                        <span class="font-semibold text-gray-900 dark:text-gray-100 truncate">
                                            {{ $chip['customer_name'] }}
                                        </span>
                                        <span class="shrink-0" style="display: flex; align-items: center; gap: 3px; font-size: 11px; line-height: 1;">
                                            @if ($chip['tour_type'])
                                                <span title="{{ $chip['tour_type'] === 'private' ? 'Private tour' : 'Group tour' }}">
                                                    {{ $chip['tour_type'] === 'private' ? '👤' : '👥' }}
                                                </span>
                                            @endif
                                            <span title="Source: {{ $chip['source_badge'] }}" style="font-size: 8px;">
                                                @if ($chip['source_badge'] === 'GYG')🟠@elseif ($chip['source_badge'] === 'WEB')🔵@elseif ($chip['source_badge'] === 'WA')🟢@else⚪@endif
                                            </span>
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
                                        <div class="text-[10px] mt-0.5" style="color: #dc2626;">
                                            ⚠ {{ implode(' · ', $chip['warnings']) }}
                                        </div>
                                    @endif
                                </div>
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
        Click any chip to inspect details and take action. Hover for quick info.
    </p>

    @endif

    {{-- Filament action modals (slide-over) render here --}}
    <x-filament-actions::modals />
</x-filament-panels::page>
