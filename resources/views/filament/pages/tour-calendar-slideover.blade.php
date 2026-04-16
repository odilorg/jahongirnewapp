@if ($inquiry)
<div class="space-y-4 text-sm">
    {{-- Status + Source --}}
    <div class="flex items-center gap-2">
        <span @class([
            'inline-flex items-center rounded-full px-2 py-1 text-xs font-medium',
            'bg-success-100 text-success-700 dark:bg-success-900/30 dark:text-success-400' => $inquiry->status === 'confirmed',
            'bg-warning-100 text-warning-700 dark:bg-warning-900/30 dark:text-warning-400' => $inquiry->status === 'awaiting_payment',
            'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' => ! in_array($inquiry->status, ['confirmed', 'awaiting_payment']),
        ])>
            {{ ucfirst(str_replace('_', ' ', $inquiry->status)) }}
        </span>
        <span class="text-xs text-gray-500 dark:text-gray-400">
            {{ \App\Models\BookingInquiry::SOURCE_LABELS[$inquiry->source] ?? $inquiry->source }}
        </span>
        @if ($inquiry->paid_at)
            <span class="text-xs text-success-600 dark:text-success-400">💰 Paid {{ $inquiry->paid_at->format('M j') }}</span>
        @elseif ($inquiry->payment_link)
            <span class="text-xs text-warning-600 dark:text-warning-400">⏳ Link sent {{ $inquiry->payment_link_sent_at?->diffForHumans() }}</span>
        @endif
    </div>

    {{-- Tour --}}
    <div>
        <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Tour</div>
        <div class="font-medium text-gray-900 dark:text-gray-100">
            {{ $inquiry->tourProduct?->title ?? $inquiry->tour_name_snapshot }}
        </div>
    </div>

    {{-- Date + Time + Pax --}}
    <div class="grid grid-cols-3 gap-3">
        <div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Date</div>
            <div class="font-medium text-gray-900 dark:text-gray-100">{{ $inquiry->travel_date?->format('M j, Y') }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Pickup</div>
            <div class="font-medium text-gray-900 dark:text-gray-100">{{ $inquiry->pickup_time ?? '—' }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Guests</div>
            <div class="font-medium text-gray-900 dark:text-gray-100">
                {{ $inquiry->people_adults }} adults
                @if ($inquiry->people_children > 0)
                    + {{ $inquiry->people_children }} children
                @endif
            </div>
        </div>
    </div>

    {{-- Customer --}}
    <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-3 space-y-1">
        <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">Guest</div>
        <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $inquiry->customer_name }}</div>
        @if ($inquiry->customer_phone)
            @php
                $waPhone = preg_replace('/[^0-9]/', '', $inquiry->customer_phone);
                $formattedPhone = \App\Support\PhoneFormatter::format($inquiry->customer_phone);
                $rawPhone = \App\Support\PhoneFormatter::normalizeForCopy($inquiry->customer_phone);
            @endphp
            <div class="flex items-center gap-2 text-gray-800 dark:text-gray-100">
                📱 <x-copyable-field :value="$rawPhone" :display="$formattedPhone" />
                @if ($waPhone)
                    <a href="https://wa.me/{{ $waPhone }}" target="_blank"
                        class="text-success-600 hover:text-success-500 text-xs font-medium">WhatsApp →</a>
                @endif
            </div>
        @endif
        @if ($inquiry->customer_email)
            <div class="flex items-center gap-1 text-gray-800 dark:text-gray-100">
                📧 <x-copyable-field :value="$inquiry->customer_email" />
            </div>
        @endif
        @if ($inquiry->customer_country)
            <div class="text-gray-800 dark:text-gray-100">🌍 {{ $inquiry->customer_country }}</div>
        @endif
    </div>

    {{-- Pickup + Dropoff --}}
    <div class="grid grid-cols-2 gap-3">
        <div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Pickup point</div>
            <div class="text-gray-900 dark:text-gray-100">{{ $inquiry->pickup_point ?: '—' }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Dropoff</div>
            <div class="text-gray-900 dark:text-gray-100">{{ $inquiry->dropoff_point ?: '—' }}</div>
        </div>
    </div>

    {{-- Driver + Guide: current assignment + quick-assign --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-2.5">
            <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">Driver</div>
            @if ($inquiry->driver)
                <div class="text-gray-900 dark:text-gray-100">🚗 {{ $inquiry->driver->full_name }}</div>
                @if ($inquiry->driver->phone01)
                    <div class="text-xs text-gray-800 dark:text-gray-100">
                        <x-copyable-field
                            :value="\App\Support\PhoneFormatter::normalizeForCopy($inquiry->driver->phone01)"
                            :display="\App\Support\PhoneFormatter::format($inquiry->driver->phone01)" />
                    </div>
                @endif
                @if ($inquiry->driver_cost)
                    <div class="text-xs mt-0.5" style="color: #16a34a;">${{ number_format((float) $inquiry->driver_cost, 2) }}</div>
                @endif
            @else
                <div class="text-danger-500 text-xs font-medium">⚠ Not assigned</div>
            @endif
        </div>
        <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-2.5">
            <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">Guide</div>
            @if ($inquiry->guide)
                <div class="text-gray-900 dark:text-gray-100">🧭 {{ $inquiry->guide->full_name }}</div>
                @if ($inquiry->guide->phone01)
                    <div class="text-xs text-gray-800 dark:text-gray-100">
                        <x-copyable-field
                            :value="\App\Support\PhoneFormatter::normalizeForCopy($inquiry->guide->phone01)"
                            :display="\App\Support\PhoneFormatter::format($inquiry->guide->phone01)" />
                    </div>
                @endif
                @if ($inquiry->guide_cost)
                    <div class="text-xs mt-0.5" style="color: #16a34a;">${{ number_format((float) $inquiry->guide_cost, 2) }}</div>
                @endif
            @else
                <div class="text-gray-500 dark:text-gray-400">—</div>
            @endif
        </div>
    </div>

    {{-- Quick Assign --}}
    @if ($inquiry->status === 'confirmed' || $inquiry->status === 'awaiting_payment')
        @php
            $allDrivers = \App\Models\Driver::where('is_active', true)->orderBy('first_name')->get();
            $allGuides  = \App\Models\Guide::where('is_active', true)->orderBy('first_name')->get();
        @endphp
        <div class="rounded-lg p-3 space-y-3" style="background: rgba(0,0,0,0.03);">
            <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">Quick Assign</div>

            {{-- Driver assign --}}
            <div class="space-y-1.5">
                <div class="text-xs font-medium text-gray-700 dark:text-gray-300">🚗 Driver</div>
                <select wire:model.live="assignDriverId"
                    class="w-full text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                    <option value="">Select driver...</option>
                    @foreach ($allDrivers as $d)
                        <option value="{{ $d->id }}">{{ $d->full_name }}</option>
                    @endforeach
                </select>

                @if ($this->assignDriverId)
                    @php
                        $driverRates = \App\Models\DriverRate::where('driver_id', $this->assignDriverId)
                            ->where('is_active', true)->orderBy('sort_order')->orderBy('label')->get();
                    @endphp
                    @if ($driverRates->isNotEmpty())
                        <select wire:model.live="assignDriverRateId"
                            class="w-full text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                            <option value="">Select rate...</option>
                            @foreach ($driverRates as $r)
                                <option value="{{ $r->id }}">{{ $r->label }} — ${{ $r->cost_usd }}</option>
                            @endforeach
                        </select>
                    @else
                        <div class="text-xs text-warning-600">No rates configured for this driver</div>
                    @endif
                    <button type="button" wire:click="quickAssignDriver"
                        class="w-full text-xs font-medium rounded-md px-3 py-1.5 text-white transition"
                        style="background: #16a34a;"
                        onmouseover="this.style.background='#15803d'" onmouseout="this.style.background='#16a34a'">
                        Assign driver{{ $this->assignDriverRateId ? '' : ' (no rate)' }}
                    </button>
                @endif
            </div>

            {{-- Guide assign --}}
            <div class="space-y-1.5">
                <div class="text-xs font-medium text-gray-700 dark:text-gray-300">🧭 Guide</div>
                <select wire:model.live="assignGuideId"
                    class="w-full text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                    <option value="">Select guide...</option>
                    @foreach ($allGuides as $g)
                        <option value="{{ $g->id }}">{{ $g->full_name }}</option>
                    @endforeach
                </select>

                @if ($this->assignGuideId)
                    @php
                        $guideRates = \App\Models\GuideRate::where('guide_id', $this->assignGuideId)
                            ->where('is_active', true)->orderBy('sort_order')->orderBy('label')->get();
                    @endphp
                    @if ($guideRates->isNotEmpty())
                        <select wire:model.live="assignGuideRateId"
                            class="w-full text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                            <option value="">Select rate...</option>
                            @foreach ($guideRates as $r)
                                <option value="{{ $r->id }}">{{ $r->label }} — ${{ $r->cost_usd }}</option>
                            @endforeach
                        </select>
                    @else
                        <div class="text-xs text-warning-600">No rates configured for this guide</div>
                    @endif
                    <button type="button" wire:click="quickAssignGuide"
                        class="w-full text-xs font-medium rounded-md px-3 py-1.5 text-white transition"
                        style="background: #16a34a;"
                        onmouseover="this.style.background='#15803d'" onmouseout="this.style.background='#16a34a'">
                        Assign guide{{ $this->assignGuideRateId ? '' : ' (no rate)' }}
                    </button>
                @endif
            </div>
        </div>
    @endif

    {{-- Accommodations --}}
    @if ($inquiry->stays->isNotEmpty())
        <div>
            <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Accommodations</div>
            @foreach ($inquiry->stays as $stay)
                <div class="text-gray-900 dark:text-gray-100">
                    🏕 {{ $stay->accommodation?->name ?? '—' }}
                    · {{ $stay->stay_date?->format('M j') }}
                    · {{ $stay->nights }}N
                    · {{ $stay->guest_count }} guests
                </div>
                @if ($stay->total_accommodation_cost)
                    <div class="text-xs ml-5" style="color: {{ $stay->cost_override ? '#d97706' : '#16a34a' }};">
                        💰 ${{ number_format((float) $stay->total_accommodation_cost, 2) }}
                        ({{ $stay->cost_per_unit_usd ? '$' . number_format((float) $stay->cost_per_unit_usd, 2) . '/person' : '' }})
                        @if ($stay->cost_override)
                            — override
                        @endif
                    </div>
                @endif
            @endforeach
            @php $totalAccCost = $inquiry->stays->sum('total_accommodation_cost'); @endphp
            @if ($totalAccCost > 0 && $inquiry->stays->count() > 1)
                <div class="text-xs font-semibold text-gray-900 dark:text-gray-100 mt-1 ml-5">
                    Total accommodation: ${{ number_format($totalAccCost, 2) }}
                </div>
            @endif
        </div>
    @endif

    {{-- Costs + Margin --}}
    @php
        $accCost       = (float) $inquiry->stays->sum('total_accommodation_cost');
        $driverCost    = (float) ($inquiry->driver_cost ?? 0);
        $guideCost     = (float) ($inquiry->guide_cost ?? 0);
        $otherCosts    = (float) ($inquiry->other_costs ?? 0);
        $totalCost     = $accCost + $driverCost + $guideCost + $otherCosts;
        $gross         = (float) ($inquiry->price_quoted ?? 0);
        $commission    = (float) ($inquiry->commission_amount ?? 0);
        $netRevenue    = $inquiry->effectiveRevenue();
        $margin        = $netRevenue - $totalCost;
        $marginPct     = $netRevenue > 0 ? round($margin / $netRevenue * 100) : 0;
        $hasCommission = $commission > 0;
    @endphp

    <div class="rounded-lg p-3 space-y-1" style="background: rgba(0,0,0,0.03);">
        <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">Financials</div>

        @if ($gross > 0)
            <div class="flex justify-between text-sm text-gray-900 dark:text-gray-100">
                <span>{{ $hasCommission ? 'Gross (guest paid)' : 'Revenue' }}</span>
                <span class="font-semibold">${{ number_format($gross, 2) }}</span>
            </div>
        @endif

        @if ($hasCommission)
            <div class="flex justify-between text-xs" style="color: #dc2626;">
                <span>{{ $inquiry->source === 'gyg' ? 'GYG' : 'OTA' }} commission ({{ (int) $inquiry->commission_rate }}%)</span>
                <span>−${{ number_format($commission, 2) }}</span>
            </div>
            <div class="flex justify-between text-sm text-gray-900 dark:text-gray-100 font-semibold">
                <span>Net revenue</span>
                <span>${{ number_format($netRevenue, 2) }}</span>
            </div>
        @endif

        @if ($accCost > 0)
            <div class="flex justify-between text-xs text-gray-800 dark:text-gray-200">
                <span>Accommodation</span>
                <span>${{ number_format($accCost, 2) }}</span>
            </div>
        @endif

        @if ($driverCost > 0)
            <div class="flex justify-between text-xs text-gray-800 dark:text-gray-200">
                <span>Driver{{ $inquiry->driver_cost_override ? ' (override)' : '' }}</span>
                <span>${{ number_format($driverCost, 2) }}</span>
            </div>
        @endif

        @if ($guideCost > 0)
            <div class="flex justify-between text-xs text-gray-800 dark:text-gray-200">
                <span>Guide</span>
                <span>${{ number_format($guideCost, 2) }}</span>
            </div>
        @endif

        @if ($otherCosts > 0)
            <div class="flex justify-between text-xs text-gray-800 dark:text-gray-200">
                <span>Other</span>
                <span>${{ number_format($otherCosts, 2) }}</span>
            </div>
        @endif

        @if ($totalCost > 0 && $netRevenue > 0)
            <div class="border-t border-gray-200 dark:border-gray-700 pt-1 mt-1 flex justify-between text-sm font-semibold" style="color: {{ $marginPct >= 40 ? '#16a34a' : ($marginPct >= 20 ? '#d97706' : '#dc2626') }};">
                <span>Margin</span>
                <span>${{ number_format($margin, 2) }} ({{ $marginPct }}%)</span>
            </div>
        @elseif ($netRevenue > 0)
            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">No costs entered yet</div>
        @endif
    </div>

    {{-- Notes (last 3 lines) --}}
    @if ($inquiry->internal_notes)
        <div>
            <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Notes</div>
            <div class="text-xs text-gray-900 dark:text-gray-100 whitespace-pre-line max-h-24 overflow-y-auto bg-gray-100 dark:bg-gray-800 rounded p-2">{{ $inquiry->internal_notes }}</div>
        </div>
    @endif
</div>
@else
    <p class="text-sm text-gray-500">Inquiry not found.</p>
@endif
