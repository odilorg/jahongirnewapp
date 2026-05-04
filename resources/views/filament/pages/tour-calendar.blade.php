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
                    'bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300' => $day['is_today'],
                    'text-gray-500 dark:text-gray-400' => ! $day['is_today'],
                ])>
                    {{ $day['carbon']->format('D') }}<br>
                    <span class="text-base">{{ $day['carbon']->format('j') }}</span>
                    <div style="font-size: 9px; font-weight: 500; color: #6b7280; text-transform: uppercase; margin-top: 2px; letter-spacing: 0.3px;">
                        {{ $day['carbon']->format('M') }}
                    </div>
                </div>
            @endforeach

            {{-- Tour rows --}}
            @forelse ($data['rows'] as $row)
                @php
                    // Chip body 42px (2 lines: name + ops status), gap 6px.
                    // Restoring readiness writings ("no driver", driver/guide
                    // names, pickup time) inside the bar — operators need
                    // them visible at scan time, not buried in the tooltip.
                    $laneH    = 48;
                    $chipH    = 42;
                    $laneCount = max(1, (int) ($row['lane_count'] ?? 1));
                    $rowMinH   = $laneCount * $laneH + 14;
                @endphp

                <div class="px-3 py-2 text-sm font-medium text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 flex items-center"
                     style="min-height: {{ $rowMinH }}px;">
                    {{ $row['name'] }}
                </div>

                {{-- Spanning-chip lane container: replaces the old
                     7-individual-cell layout. Day cell backgrounds (border
                     + today tint) render as a 7-col sub-grid; chips
                     overlay as absolute-positioned bars whose left/width
                     are computed in % of the 7-col strip. --}}
                <div class="relative border-b border-gray-200 dark:border-gray-700"
                     style="grid-column: 2 / span 7; min-height: {{ $rowMinH }}px;">

                    {{-- Day backgrounds (no chips here — purely visual) --}}
                    <div class="absolute inset-0 grid pointer-events-none" style="grid-template-columns: repeat(7, 1fr);">
                        @foreach ($data['days'] as $day)
                            <div style="border-left: 3px solid rgba(156, 163, 175, 0.5); {{ $day['is_today'] ? 'background: rgba(249, 115, 22, 0.06);' : '' }}"></div>
                        @endforeach
                    </div>

                    {{-- Spanning chip bars --}}
                    @foreach ($row['chips'] as $chip)
                        @php
                            $vStart = (int) $chip['visible_start_col'];
                            $vSpan  = (int) $chip['visible_span'];
                            $lane   = (int) ($chip['lane_index'] ?? 0);
                            $left   = ($vStart / 7) * 100;
                            $width  = ($vSpan  / 7) * 100;
                            $top    = $lane * $laneH + 6;
                            $clipL  = ! empty($chip['clip_left']);
                            $clipR  = ! empty($chip['clip_right']);
                            $hasWarn = ! empty($chip['warnings']);
                        @endphp
                        <div wire:click="openInquiry({{ $chip['id'] }})"
                             title="{{ $chip['view']['tooltip'] }}"
                             style="position: absolute;
                                    left: calc({{ $left }}% + 4px);
                                    width: calc({{ $width }}% - 8px);
                                    top: {{ $top }}px;
                                    height: {{ $chipH }}px;
                                    {{ $chip['view']['style'] }}
                                    {{ $clipL ? 'border-top-left-radius:0; border-bottom-left-radius:0; border-left: 3px dashed #f97316;' : 'border-left: 4px solid #16a34a;' }}
                                    {{ $clipR ? 'border-top-right-radius:0; border-bottom-right-radius:0; border-right: 3px dashed #f97316;' : 'border-right: 4px solid #7c3aed;' }}"
                             class="rounded border cursor-pointer overflow-hidden flex flex-col justify-center px-2 {{ $chip['view']['bg_class'] }}">

                            {{-- Line 1: name + identity badges --}}
                            <div class="flex items-center text-xs font-semibold leading-tight">
                                @if ($clipL)
                                    <span class="shrink-0 mr-1 text-orange-600">◂</span>
                                @endif
                                <span class="shrink-0 inline-block"
                                      title="Source: {{ $chip['view']['source_label'] }}"
                                      style="width:8px; height:8px; border-radius:50%; background:{{ $chip['view']['source_color'] }}; box-shadow: 0 0 0 1px rgba(0,0,0,0.08); margin-right:8px;"></span>
                                @if ($chip['view']['tour_type_icon'])
                                    <span class="shrink-0"
                                          title="{{ $chip['tour_type'] === 'private' ? 'Private tour' : 'Group tour' }}"
                                          style="font-size:11px; line-height:1; margin-right:8px;">
                                        {{ $chip['view']['tour_type_icon'] }}
                                    </span>
                                @endif
                                <span class="truncate flex-1">{{ $chip['customer_name'] }}</span>
                                @if (! empty($chip['flag_icon']))
                                    <span class="shrink-0 ml-1"
                                          title="{{ $chip['flag_tooltip'] ?? 'Guest context flag' }}"
                                          style="font-size:11px; line-height:1;">
                                        {{ $chip['flag_icon'] }}
                                    </span>
                                @endif
                                @if ($chip['view']['payment_icon'])
                                    <span class="shrink-0 ml-1" style="font-size:11px;">{{ $chip['view']['payment_icon'] }}</span>
                                @endif
                                @if ($chip['assigned_initials'])
                                    <span class="shrink-0 ml-1" title="Assigned: {{ $chip['assigned_to'] }}"
                                          style="font-size:9px; font-weight:700; background:#e0e7ff; color:#3730a3; padding:1px 4px; border-radius:3px;">
                                        {{ $chip['assigned_initials'] }}
                                    </span>
                                @endif
                                @if ($clipR)
                                    <span class="shrink-0 ml-1 text-orange-600">▸</span>
                                @endif
                            </div>

                            {{-- Line 2: readiness signals.
                                 Warning-first when present (red, screams);
                                 otherwise a compact positive-state line so
                                 operators see WHO is staffed at scan time. --}}
                            <div class="flex items-center mt-0.5 truncate" style="font-size:10px; line-height:1.1;">
                                @if ($hasWarn)
                                    <span class="truncate" style="color:#dc2626; font-weight:600;">
                                        ⚠ {{ implode(' · ', $chip['warnings']) }}
                                    </span>
                                @else
                                    <span class="truncate text-gray-700 dark:text-gray-200">
                                        @if (! empty($chip['pickup_time']))
                                            ⏰ {{ $chip['pickup_time'] }}
                                        @endif
                                        @if (! empty($chip['pax_label']))
                                            @if (! empty($chip['pickup_time'])) · @endif {{ $chip['pax_label'] }} pax
                                        @endif
                                        @if (! empty($chip['driver_name']))
                                            · 🚗 {{ $chip['driver_name'] }}
                                        @endif
                                        @if (! empty($chip['guide_name']))
                                            · 🧭 {{ $chip['guide_name'] }}
                                        @endif
                                    </span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
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

    {{--
        FIX: Livewire morph inserts modal children but Alpine doesn't bind
        x-show reactively to their scope on first render, so x-show="isOpen"
        stays at display:none even though isOpen=true.
        Workaround: after open-modal fires, manually evaluate each x-show
        expression inside the matched modal (using Alpine.evaluate) and fix
        the display style. Same for close-modal — re-hide if expression went
        falsy. No re-init, no transition disruption.
        Also re-apply on livewire:update so close-then-reopen keeps working.
    --}}
    <script>
        (function () {
            function syncXShow(modal) {
                if (!window.Alpine) return;
                modal.querySelectorAll('[x-show]').forEach(function (el) {
                    const expr = el.getAttribute('x-show');
                    if (!expr) return;
                    let value;
                    try { value = window.Alpine.evaluate(el, expr); } catch (_e) { return; }
                    if (value) {
                        if (el.style.display === 'none') el.style.removeProperty('display');
                    } else {
                        el.style.display = 'none';
                    }
                });
            }

            function handle(e) {
                const id = e.detail?.id;
                if (!id) return;
                requestAnimationFrame(function () {
                    requestAnimationFrame(function () {
                        document.querySelectorAll('.fi-modal').forEach(function (modal) {
                            const onAttr = modal.getAttribute('x-on:open-modal.window') || '';
                            if (!onAttr.includes(id)) return;
                            syncXShow(modal);
                        });
                    });
                });
            }

            window.addEventListener('open-modal', handle);
            window.addEventListener('close-modal', handle);
        })();
    </script>
</x-filament-panels::page>
