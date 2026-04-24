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
        @if ($inquiry->external_reference)
            <span class="text-xs" style="background: #fed7aa; color: #9a3412; padding: 2px 6px; border-radius: 4px; font-family: monospace; font-weight: 600;"
                title="{{ strtoupper($inquiry->source) }} reference">
                <x-copyable-field :value="$inquiry->external_reference" :display="$inquiry->external_reference" />
            </span>
        @endif
        @if ($inquiry->paid_at)
            <span class="text-xs text-success-600 dark:text-success-400">💰 Paid {{ $inquiry->paid_at->format('M j') }}</span>
        @elseif ($inquiry->payment_link)
            <span class="text-xs text-warning-600 dark:text-warning-400">⏳ Link sent {{ $inquiry->payment_link_sent_at?->diffForHumans() }}</span>
        @endif
    </div>

    {{-- Operator attribution --}}
    <div class="text-xs text-gray-500 dark:text-gray-400" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
        <span>
            👤 Created:
            <span class="text-gray-800 dark:text-gray-200">
                {{ $inquiry->createdByUser?->name ?? 'System' }}
            </span>
        </span>
        <span>
            🎯 Assigned:
            @if ($inquiry->assignedToUser)
                <span class="text-gray-800 dark:text-gray-200">{{ $inquiry->assignedToUser->name }}</span>
            @else
                <span class="text-danger-500">— unassigned</span>
            @endif
        </span>
        @if ($inquiry->closedByUser)
            <span>
                ✅ Closed: <span class="text-gray-800 dark:text-gray-200">{{ $inquiry->closedByUser->name }}</span>
            </span>
        @endif

        @if (! $inquiry->assigned_to_user_id)
            <button type="button" wire:click="claimInquiry"
                class="text-[10px] font-medium rounded px-2 py-0.5 text-white"
                style="background: #16a34a;">🤝 Claim</button>
        @else
            <div x-data="{ open: false }" style="position: relative;">
                <button @click="open = !open" type="button"
                    class="text-[10px] rounded px-2 py-0.5"
                    style="background: #e5e7eb; color: #374151;">↔ Reassign</button>
                <div x-show="open" x-cloak @click.outside="open = false"
                    style="position: absolute; right: 0; top: 100%; margin-top: 4px; z-index: 20; background: white; border: 1px solid #e5e7eb; border-radius: 6px; padding: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); min-width: 160px;">
                    <select wire:model="reassignUserId"
                        class="text-xs rounded-md border-gray-300 w-full" style="padding: 4px 6px; margin-bottom: 4px;">
                        <option value="">— Unassign —</option>
                        @foreach ($users as $u)
                            <option value="{{ $u->id }}">{{ $u->name }}</option>
                        @endforeach
                    </select>
                    <button type="button" wire:click="reassignInquiry" @click="open = false"
                        class="text-[10px] font-medium rounded px-2 py-1 text-white w-full"
                        style="background: #3b82f6;">Confirm</button>
                </div>
            </div>
        @endif
    </div>

    {{-- Tour --}}
    <div>
        <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Tour</div>
        <div class="font-medium text-gray-900 dark:text-gray-100">
            {{ $inquiry->tourProduct?->title ?? $inquiry->tour_name_snapshot }}
        </div>
    </div>

    {{-- Direction + Tour Type. $directions prepared by builder. --}}
    @if ($inquiry->tour_product_id && $directions->isNotEmpty())
        <div style="display: flex; gap: 8px; align-items: flex-end;">
                <div style="flex: 1;">
                    <label class="text-[10px] text-gray-500 dark:text-gray-400">Direction</label>
                    <select wire:model.live="editDirectionId"
                        class="w-full text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                        <option value="">— not set —</option>
                        @foreach ($directions as $dir)
                            <option value="{{ $dir->id }}">{{ $dir->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <button type="button" wire:click="quickSaveDirection"
                        class="text-xs font-medium rounded-md px-3 py-1.5 text-white"
                        style="background: #3b82f6;"
                        onmouseover="this.style.background='#2563eb'" onmouseout="this.style.background='#3b82f6'">
                        Save
                    </button>
                </div>
            </div>
    @endif

    @if ($inquiry->tour_type)
        <div>
            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold"
                style="{{ $inquiry->tour_type === 'private' ? 'background: #dbeafe; color: #1e40af;' : 'background: #fef3c7; color: #92400e;' }}">
                {{ ucfirst($inquiry->tour_type) }} tour
            </span>
        </div>
    @endif

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
            <div class="flex items-center gap-2 text-gray-800 dark:text-gray-100">
                📱 <x-copyable-field :value="$customerPhone['raw']" :display="$customerPhone['formatted']" />
                @if ($customerPhone['wa'])
                    <a href="https://wa.me/{{ $customerPhone['wa'] }}" target="_blank"
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

    {{-- Pickup (editable) + Dropoff --}}
    <div class="rounded-lg p-3 space-y-2" style="background: rgba(0,0,0,0.03);">
        <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">Pickup</div>
        <div class="grid grid-cols-2 gap-2">
            <div>
                <label class="text-[10px] text-gray-500 dark:text-gray-400">Time</label>
                <input type="time" wire:model="editPickupTime"
                    class="w-full text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 px-2 py-1.5">
            </div>
            <div>
                <label class="text-[10px] text-gray-500 dark:text-gray-400">Location</label>
                <input type="text" wire:model="editPickupPoint"
                    placeholder="Hotel name or landmark"
                    class="w-full text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 px-2 py-1.5">
            </div>
        </div>
        <button type="button" wire:click="quickSavePickup"
            class="w-full text-xs font-medium rounded-md px-3 py-1.5 text-white transition"
            style="background: #3b82f6;"
            onmouseover="this.style.background='#2563eb'" onmouseout="this.style.background='#3b82f6'">
            Save pickup
        </button>

        <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider pt-2">Drop-off</div>
        <div>
            <label class="text-[10px] text-gray-500 dark:text-gray-400">Location</label>
            <input type="text" wire:model="editDropoffPoint"
                placeholder="Hotel name or landmark"
                class="w-full text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 px-2 py-1.5">
        </div>
        <button type="button" wire:click="quickSaveDropoff"
            class="w-full text-xs font-medium rounded-md px-3 py-1.5 text-white transition"
            style="background: #3b82f6;"
            onmouseover="this.style.background='#2563eb'" onmouseout="this.style.background='#3b82f6'">
            Save drop-off
        </button>

        @if ($inquiry->dropoff_point)
            <a href="https://maps.google.com/?q={{ urlencode($inquiry->dropoff_point) }}"
               target="_blank" rel="noopener"
               class="block text-[10px] text-blue-600 dark:text-blue-400 hover:underline">
                📍 Open current drop-off in map
            </a>
        @endif
    </div>

    {{-- Driver + Guide: current assignment + quick-assign --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-2.5">
            <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">Driver</div>
            @if ($inquiry->driver)
                <div class="text-gray-900 dark:text-gray-100">🚗 {{ $inquiry->driver->full_name }}</div>
                @include('filament.pages.partials.dispatch-chip', [
                    'dispatchedAt' => $inquiry->driver_dispatched_at,
                    'inquiryUpdatedAt' => $inquiry->updated_at,
                ])
                @if ($inquiry->driver->phone01)
                    <div class="text-xs text-gray-800 dark:text-gray-100">
                        <x-copyable-field
                            :value="\App\Support\PhoneFormatter::normalizeForCopy($inquiry->driver->phone01)"
                            :display="\App\Support\PhoneFormatter::format($inquiry->driver->phone01)" />
                    </div>
                @endif
                @if ($inquiry->driver_cost)
                    <div class="text-xs" style="margin-top: 2px; color: {{ $payments['driver']['color'] }};">
                        ${{ number_format((float) $inquiry->driver_cost, 2) }}
                        @if ($payments['driver']['paid'] > 0)
                            · paid ${{ number_format($payments['driver']['paid'], 2) }}
                        @endif
                        @if ($payments['driver']['remaining'] > 0)
                            · <strong>${{ number_format($payments['driver']['remaining'], 2) }} due</strong>
                        @else
                            · ✅ settled
                        @endif
                    </div>
                    @if ($payments['driver']['remaining'] > 0)
                        <div style="margin-top: 4px;" x-data="{ open: false }">
                            <button @click="open = !open" type="button"
                                class="text-[10px] font-medium rounded px-2 py-1 text-white"
                                style="background: #7c3aed;">💰 Pay</button>
                            <div x-show="open" x-cloak style="margin-top: 6px; display: flex; gap: 4px; align-items: flex-end;"
                                x-data="{ amount: '{{ $payments['driver']['remaining'] }}' }">
                                <input type="number" step="0.01" x-model="amount"
                                    class="text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                                    style="width: 80px; padding: 4px 6px;">
                                <select wire:model="payMethod"
                                    class="text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                                    style="padding: 4px 6px;">
                                    <option value="cash">Cash</option>
                                    <option value="bank_transfer">Transfer</option>
                                    <option value="card">Card</option>
                                </select>
                                <button type="button"
                                    @click="$wire.call('quickPay', 'driver', {{ $inquiry->driver_id }}, amount); open = false;"
                                    class="text-[10px] font-medium rounded px-2 py-1 text-white"
                                    style="background: #16a34a;">Confirm</button>
                            </div>
                        </div>
                    @endif
                @endif
            @else
                <div class="text-danger-500 text-xs font-medium">⚠ Not assigned</div>
            @endif
        </div>
        <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-2.5">
            <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">Guide</div>
            @if ($inquiry->guide)
                <div class="text-gray-900 dark:text-gray-100">🧭 {{ $inquiry->guide->full_name }}</div>
                @include('filament.pages.partials.dispatch-chip', [
                    'dispatchedAt' => $inquiry->guide_dispatched_at,
                    'inquiryUpdatedAt' => $inquiry->updated_at,
                ])
                @if ($inquiry->guide->phone01)
                    <div class="text-xs text-gray-800 dark:text-gray-100">
                        <x-copyable-field
                            :value="\App\Support\PhoneFormatter::normalizeForCopy($inquiry->guide->phone01)"
                            :display="\App\Support\PhoneFormatter::format($inquiry->guide->phone01)" />
                    </div>
                @endif
                @if ($inquiry->guide_cost)
                    <div class="text-xs" style="margin-top: 2px; color: {{ $payments['guide']['color'] }};">
                        ${{ number_format((float) $inquiry->guide_cost, 2) }}
                        @if ($payments['guide']['paid'] > 0)
                            · paid ${{ number_format($payments['guide']['paid'], 2) }}
                        @endif
                        @if ($payments['guide']['remaining'] > 0)
                            · <strong>${{ number_format($payments['guide']['remaining'], 2) }} due</strong>
                        @else
                            · ✅ settled
                        @endif
                    </div>
                    @if ($payments['guide']['remaining'] > 0)
                        <div style="margin-top: 4px;" x-data="{ open: false }">
                            <button @click="open = !open" type="button"
                                class="text-[10px] font-medium rounded px-2 py-1 text-white"
                                style="background: #7c3aed;">💰 Pay</button>
                            <div x-show="open" x-cloak style="margin-top: 6px; display: flex; gap: 4px; align-items: flex-end;"
                                x-data="{ amount: '{{ $payments['guide']['remaining'] }}' }">
                                <input type="number" step="0.01" x-model="amount"
                                    class="text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                                    style="width: 80px; padding: 4px 6px;">
                                <select wire:model="payMethod"
                                    class="text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                                    style="padding: 4px 6px;">
                                    <option value="cash">Cash</option>
                                    <option value="bank_transfer">Transfer</option>
                                    <option value="card">Card</option>
                                </select>
                                <button type="button"
                                    @click="$wire.call('quickPay', 'guide', {{ $inquiry->guide_id }}, amount); open = false;"
                                    class="text-[10px] font-medium rounded px-2 py-1 text-white"
                                    style="background: #16a34a;">Confirm</button>
                            </div>
                        </div>
                    @endif
                @endif
            @else
                <div class="text-gray-500 dark:text-gray-400">—</div>
            @endif
        </div>
    </div>

    {{-- Quick Assign. $drivers / $guides / $driverRates / $guideRates
         prepared by TourCalendarBuilder. --}}
    @if ($inquiry->status === 'confirmed' || $inquiry->status === 'awaiting_payment')
        <div class="rounded-lg p-3" style="background: rgba(0,0,0,0.03); margin-top: 16px; margin-bottom: 16px;">
            <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider" style="margin-bottom: 12px;">Quick Assign</div>

            {{-- Driver assign --}}
            <div class="rounded-lg bg-white dark:bg-gray-800 p-2.5">
                <div class="text-xs font-medium text-gray-700 dark:text-gray-300" style="margin-bottom: 6px;">🚗 Driver</div>
                <select wire:model.live="assignDriverId" style="margin-bottom: 8px;"
                    class="w-full text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                    <option value="">Select driver...</option>
                    @foreach ($drivers as $d)
                        <option value="{{ $d->id }}">{{ $d->full_name }}</option>
                    @endforeach
                </select>

                @if ($assignDriverId)
                    @if ($driverRates->isNotEmpty())
                        <select wire:model.live="assignDriverRateId" style="margin-bottom: 8px;"
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
                        Assign driver{{ $assignDriverRateId ? '' : ' (no rate)' }}
                    </button>
                @endif
            </div>

            {{-- Separator --}}
            <hr style="margin: 16px 0; border: none; border-top: 1px solid rgba(156,163,175,0.3);">

            {{-- Guide assign --}}
            <div class="rounded-lg bg-white dark:bg-gray-800 p-2.5">
                <div class="text-xs font-medium text-gray-700 dark:text-gray-300" style="margin-bottom: 6px;">🧭 Guide</div>
                <select wire:model.live="assignGuideId" style="margin-bottom: 8px;"
                    class="w-full text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                    <option value="">Select guide...</option>
                    @foreach ($guides as $g)
                        <option value="{{ $g->id }}">{{ $g->full_name }}</option>
                    @endforeach
                </select>

                @if ($assignGuideId)
                    @if ($guideRates->isNotEmpty())
                        <select wire:model.live="assignGuideRateId" style="margin-bottom: 8px;"
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
                        Assign guide{{ $assignGuideRateId ? '' : ' (no rate)' }}
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
                @include('filament.pages.partials.dispatch-chip', [
                    'dispatchedAt' => $stay->dispatched_at,
                    'inquiryUpdatedAt' => $stay->updated_at,
                ])
                @if ($stay->total_accommodation_cost)
                    <div class="text-xs ml-5" style="color: {{ $payments['stays'][$stay->id]['color'] }};">
                        ${{ number_format((float) $stay->total_accommodation_cost, 2) }}
                        ({{ $stay->cost_per_unit_usd ? '$' . number_format((float) $stay->cost_per_unit_usd, 2) . '/person' : '' }})
                        @if ($payments['stays'][$stay->id]['paid'] > 0) · paid ${{ number_format($payments['stays'][$stay->id]['paid'], 2) }} @endif
                        @if ($payments['stays'][$stay->id]['remaining'] > 0) · <strong>${{ number_format($payments['stays'][$stay->id]['remaining'], 2) }} due</strong>
                        @else · ✅ settled @endif
                    </div>
                    @if ($payments['stays'][$stay->id]['remaining'] > 0 && $stay->accommodation_id)
                        <div class="ml-5" style="margin-top: 4px;" x-data="{ open: false }">
                            <button @click="open = !open" type="button"
                                class="text-[10px] font-medium rounded px-2 py-1 text-white"
                                style="background: #7c3aed;">💰 Pay</button>
                            <div x-show="open" x-cloak style="margin-top: 6px; display: flex; gap: 4px; align-items: flex-end;"
                                x-data="{ amount: '{{ $payments['stays'][$stay->id]['remaining'] }}' }">
                                <input type="number" step="0.01" x-model="amount"
                                    class="text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                                    style="width: 80px; padding: 4px 6px;">
                                <select wire:model="payMethod"
                                    class="text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                                    style="padding: 4px 6px;">
                                    <option value="cash">Cash</option>
                                    <option value="bank_transfer">Transfer</option>
                                    <option value="card">Card</option>
                                </select>
                                <button type="button"
                                    @click="$wire.call('quickPay', 'accommodation', {{ $stay->accommodation_id }}, amount); open = false;"
                                    class="text-[10px] font-medium rounded px-2 py-1 text-white"
                                    style="background: #16a34a;">Confirm</button>
                            </div>
                        </div>
                    @endif
                @endif
            @endforeach
            @if ($payments['totals']['acc_cost_multi'])
                <div class="text-xs font-semibold text-gray-900 dark:text-gray-100 mt-1 ml-5">
                    Total accommodation: ${{ number_format($payments['totals']['acc_cost'], 2) }}
                </div>
            @endif
        </div>
    @endif

    {{-- Quick Add Accommodation. $accommodations + $accommodationPreview
         prepared by builder. --}}
    @if ($inquiry->status === 'confirmed' || $inquiry->status === 'awaiting_payment')
        <div class="rounded-lg p-3" style="background: rgba(0,0,0,0.03); margin-top: 12px;">
            <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider" style="margin-bottom: 8px;">Add Accommodation</div>
            <div class="rounded-lg bg-white dark:bg-gray-800 p-2.5">
                <select wire:model.live="assignAccommodationId" style="margin-bottom: 8px;"
                    class="w-full text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                    <option value="">Select accommodation...</option>
                    @foreach ($accommodations as $acc)
                        <option value="{{ $acc->id }}">{{ $acc->full_name }}</option>
                    @endforeach
                </select>

                @if ($assignAccommodationId)
                    <div style="display: flex; gap: 6px; margin-bottom: 8px;">
                        <div style="flex: 1;">
                            <label class="text-[10px] text-gray-500 dark:text-gray-400">Guests</label>
                            <input type="number" wire:model.live="assignAccGuests" min="1"
                                class="w-full text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 px-2 py-1.5">
                        </div>
                        <div style="flex: 1;">
                            <label class="text-[10px] text-gray-500 dark:text-gray-400">Nights</label>
                            <input type="number" wire:model.live="assignAccNights" min="1"
                                class="w-full text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 px-2 py-1.5">
                        </div>
                        <div style="flex: 1;">
                            <label class="text-[10px] text-gray-500 dark:text-gray-400">Date</label>
                            <input type="date" wire:model="assignAccDate"
                                class="w-full text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 px-2 py-1.5">
                        </div>
                    </div>

                    @if ($accommodationPreview && $accommodationPreview['rate'])
                        <div class="text-xs" style="color: #16a34a; margin-bottom: 8px;">
                            → ${{ number_format((float) $accommodationPreview['rate']->cost_usd, 2) }}/person × {{ $accommodationPreview['guests'] }} × {{ $accommodationPreview['nights'] }}N = <strong>${{ number_format($accommodationPreview['total'], 2) }}</strong>
                        </div>
                        <button type="button" wire:click="quickAddStay"
                            class="w-full text-xs font-medium rounded-md px-3 py-1.5 text-white transition"
                            style="background: #16a34a;"
                            onmouseover="this.style.background='#15803d'" onmouseout="this.style.background='#16a34a'">
                            Add stay — ${{ number_format($accommodationPreview['total'], 2) }}
                        </button>
                    @elseif ($accommodationPreview && ! $accommodationPreview['accommodation']->isPerPersonPricing())
                        <div class="text-xs" style="color: #d97706; margin-bottom: 8px;">
                            Per-room pricing — use full edit page for hotel stays
                        </div>
                    @elseif ($accommodationPreview)
                        <div class="text-xs" style="color: #dc2626; margin-bottom: 8px;">
                            No active rate found for {{ $accommodationPreview['guests'] }} guests
                        </div>
                        <button type="button" wire:click="quickAddStay"
                            class="w-full text-xs font-medium rounded-md px-3 py-1.5 text-white transition"
                            style="background: #6b7280;"
                            onmouseover="this.style.background='#4b5563'" onmouseout="this.style.background='#6b7280'">
                            Add stay (no rate)
                        </button>
                    @endif
                @endif
            </div>
        </div>
    @endif

    {{-- Costs + Margin. All totals prepared by PaymentSummaryBuilder. --}}
    <div class="rounded-lg p-3 space-y-1" style="background: rgba(0,0,0,0.03);">
        <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">Financials</div>

        {{-- Inline price entry --}}
        <div style="display: flex; gap: 6px; align-items: flex-end; margin-bottom: 6px; padding-bottom: 8px; border-bottom: 1px solid rgba(0,0,0,0.08);">
            <div style="flex: 1;">
                <label class="text-[10px] text-gray-500 dark:text-gray-400">Price quoted</label>
                <input type="number" wire:model="editPriceQuoted" step="0.01" placeholder="220.00"
                    class="w-full text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                    style="padding: 4px 6px;">
            </div>
            <button type="button" wire:click="quickSavePrice"
                class="text-xs font-medium rounded-md px-3 py-1.5 text-white"
                style="background: #3b82f6;"
                onmouseover="this.style.background='#2563eb'" onmouseout="this.style.background='#3b82f6'">
                Save
            </button>
        </div>

        {{-- Split payment breakdown. Only shown when a split actually
             exists (amount_online_usd populated). Cash > 0 is flagged in
             orange so drivers/ops see "money still to collect at pickup"
             without digging into the inquiry. --}}
        @if ($inquiry->amount_online_usd !== null)
            <div class="flex justify-between text-xs text-gray-800 dark:text-gray-200">
                <span>Online paid</span>
                <span>${{ number_format((float) $inquiry->amount_online_usd, 2) }}</span>
            </div>
            @if ((float) $inquiry->amount_cash_usd > 0)
                <div class="flex justify-between text-xs font-medium" style="color: #d97706;">
                    <span>Cash due at pickup</span>
                    <span>${{ number_format((float) $inquiry->amount_cash_usd, 2) }}</span>
                </div>
            @endif
        @endif

        @if ($payments['totals']['gross'] > 0)
            <div class="flex justify-between text-sm text-gray-900 dark:text-gray-100">
                <span>{{ $payments['totals']['has_commission'] ? 'Gross (guest paid)' : 'Revenue' }}</span>
                <span class="font-semibold">${{ number_format($payments['totals']['gross'], 2) }}</span>
            </div>
        @endif

        @if ($payments['totals']['has_commission'])
            <div class="flex justify-between text-xs" style="color: #dc2626;">
                <span>{{ $inquiry->source === 'gyg' ? 'GYG' : 'OTA' }} commission ({{ (int) $inquiry->commission_rate }}%)</span>
                <span>−${{ number_format($payments['totals']['commission'], 2) }}</span>
            </div>
            <div class="flex justify-between text-sm text-gray-900 dark:text-gray-100 font-semibold">
                <span>Net revenue</span>
                <span>${{ number_format($payments['totals']['net_revenue'], 2) }}</span>
            </div>
        @endif

        @if ($payments['totals']['acc_cost'] > 0)
            <div class="flex justify-between text-xs text-gray-800 dark:text-gray-200">
                <span>Accommodation</span>
                <span>${{ number_format($payments['totals']['acc_cost'], 2) }}</span>
            </div>
        @endif

        @if ($payments['totals']['driver_cost'] > 0)
            <div class="flex justify-between text-xs text-gray-800 dark:text-gray-200">
                <span>Driver{{ $inquiry->driver_cost_override ? ' (override)' : '' }}</span>
                <span>${{ number_format($payments['totals']['driver_cost'], 2) }}</span>
            </div>
        @endif

        @if ($payments['totals']['guide_cost'] > 0)
            <div class="flex justify-between text-xs text-gray-800 dark:text-gray-200">
                <span>Guide</span>
                <span>${{ number_format($payments['totals']['guide_cost'], 2) }}</span>
            </div>
        @endif

        @if ($payments['totals']['other_costs'] > 0)
            <div class="flex justify-between text-xs text-gray-800 dark:text-gray-200">
                <span>Other</span>
                <span>${{ number_format($payments['totals']['other_costs'], 2) }}</span>
            </div>
        @endif

        @if ($payments['totals']['total_cost'] > 0 && $payments['totals']['net_revenue'] > 0)
            <div class="border-t border-gray-200 dark:border-gray-700 pt-1 mt-1 flex justify-between text-sm font-semibold" style="color: {{ $payments['totals']['margin_pct'] >= 40 ? '#16a34a' : ($payments['totals']['margin_pct'] >= 20 ? '#d97706' : '#dc2626') }};">
                <span>Margin</span>
                <span>${{ number_format($payments['totals']['margin'], 2) }} ({{ $payments['totals']['margin_pct'] }}%)</span>
            </div>
        @elseif ($payments['totals']['net_revenue'] > 0)
            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">No costs entered yet</div>
        @endif
    </div>

    {{-- Guest Payments --}}
    {{-- Guest payment stats from PaymentSummaryBuilder --}}
    <div class="rounded-lg p-3" style="background: rgba(0,0,0,0.03); margin-top: 12px;">
        <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider" style="margin-bottom: 8px;">Guest Payments</div>

        <div class="flex justify-between text-xs text-gray-700 dark:text-gray-200" style="margin-bottom: 2px;">
            <span>Quoted</span>
            <span class="font-semibold">${{ number_format($payments['guest']['quoted'], 2) }}</span>
        </div>
        <div class="flex justify-between text-xs" style="color: #16a34a; margin-bottom: 2px;">
            <span>Received</span>
            <span class="font-semibold">${{ number_format($payments['guest']['received'], 2) }}</span>
        </div>
        <div class="flex justify-between text-xs font-bold pt-1" style="color: {{ $payments['guest']['outstanding'] > 0 ? '#dc2626' : '#16a34a' }}; border-top: 1px solid rgba(0,0,0,0.08); margin-top: 4px;">
            <span>Outstanding</span>
            <span>${{ number_format($payments['guest']['outstanding'], 2) }} {{ $payments['guest']['outstanding'] > 0 ? '🔴' : '✅' }}</span>
        </div>

        @if ($payments['guest']['recent_payments']->isNotEmpty())
            <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid rgba(0,0,0,0.08);">
                @foreach ($payments['guest']['recent_payments'] as $p)
                    <div class="text-[11px] text-gray-700 dark:text-gray-300" style="margin-bottom: 2px;">
                        {{ $p->payment_date?->format('M j') }} · {{ $p->amount < 0 ? '' : '+' }}${{ number_format((float) $p->amount, 2) }} · {{ \App\Models\GuestPayment::METHODS[$p->payment_method] ?? $p->payment_method }} · {{ $p->recordedByUser?->name ?? 'System' }}
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Quick pay --}}
        @if ($payments['guest']['outstanding'] > 0 || $payments['guest']['outstanding'] < 0)
            <div x-data="{ open: false, amount: '{{ $payments['guest']['outstanding'] }}', method: 'cash' }" style="margin-top: 10px;">
                <button @click="open = !open" type="button"
                    class="w-full text-xs font-medium rounded-md px-3 py-1.5 text-white"
                    style="background: #16a34a;">💰 Record Payment</button>
                <div x-show="open" x-cloak style="margin-top: 8px; display: flex; gap: 4px; align-items: flex-end;">
                    <input type="number" step="0.01" x-model="amount"
                        class="text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                        style="flex: 1; padding: 4px 6px;">
                    <select x-model="method"
                        class="text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                        style="padding: 4px 6px;">
                        <option value="cash">Cash</option>
                        <option value="octo">Octo</option>
                        <option value="card_office">Card</option>
                        <option value="bank_transfer">Transfer</option>
                        <option value="paypal">PayPal</option>
                        <option value="other">Other</option>
                    </select>
                    <button type="button"
                        @click="$wire.call('quickGuestPay', amount, method); open = false;"
                        class="text-[10px] font-medium rounded px-2 py-1 text-white"
                        style="background: #3b82f6;">Confirm</button>
                </div>
            </div>
        @endif
    </div>

    {{-- Phase 21 — Reminders. R2: $pendingReminders is prepared by
         TourCalendarBuilder::buildSlideOverViewData with border color
         pre-resolved per entry; Blade does no logic. --}}
    <div class="rounded-lg p-3" style="background: rgba(0,0,0,0.03); margin-top: 12px;">
        <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider" style="margin-bottom: 8px;">⏰ Reminders</div>

        @if (! empty($pendingReminders))
            @foreach ($pendingReminders as $reminder)
                @php $r = $reminder['model']; @endphp
                <div style="background: white; border-left: 3px solid {{ $reminder['border'] }}; border-radius: 4px; padding: 8px 10px; margin-bottom: 6px;">
                    <div style="font-size: 11px; color: #6b7280;">
                        {{ $r->remind_at->format('M j, H:i') }}
                        @if ($reminder['is_overdue'])
                            <span style="color: #dc2626; font-weight: 600;">· OVERDUE</span>
                        @elseif ($reminder['is_due_soon'])
                            <span style="color: #d97706; font-weight: 600;">· DUE SOON</span>
                        @endif
                    </div>
                    <div style="font-size: 12px; color: #111827; margin-top: 2px;">{{ $r->message }}</div>
                    <div style="display: flex; gap: 6px; margin-top: 6px;">
                        <button type="button" wire:click="markReminderDone({{ $r->id }})"
                            class="text-[10px] font-medium rounded px-2 py-1 text-white"
                            style="background: #16a34a;">✅ Done</button>
                        <button type="button" wire:click="snoozeReminder({{ $r->id }}, 1)"
                            class="text-[10px] font-medium rounded px-2 py-1"
                            style="background: #e5e7eb; color: #374151;">⏰ +1 day</button>
                    </div>
                </div>
            @endforeach
        @endif

        {{-- Add new reminder --}}
        <div x-data="{ open: false }" style="margin-top: 8px;">
            <button @click="open = !open" type="button"
                class="w-full text-xs font-medium rounded-md px-3 py-1.5"
                style="background: #3b82f6; color: white;">
                ⏰ + Set Reminder
            </button>

            <div x-show="open" x-cloak style="margin-top: 8px; padding: 10px; background: white; border-radius: 6px;">
                <label class="text-[10px] text-gray-500 uppercase tracking-wider">When</label>
                <input type="datetime-local" wire:model="reminderRemindAt"
                    class="w-full text-xs rounded-md border-gray-300 mt-1"
                    style="padding: 4px 6px;">

                <div style="display: flex; gap: 4px; margin-top: 6px; flex-wrap: wrap;">
                    <button type="button" wire:click="reminderPreset('1d')"
                        class="text-[10px] rounded px-2 py-1"
                        style="background: #e5e7eb; color: #374151;">+1 day</button>
                    <button type="button" wire:click="reminderPreset('3d')"
                        class="text-[10px] rounded px-2 py-1"
                        style="background: #e5e7eb; color: #374151;">+3 days</button>
                    <button type="button" wire:click="reminderPreset('1w')"
                        class="text-[10px] rounded px-2 py-1"
                        style="background: #e5e7eb; color: #374151;">+1 week</button>
                    @if ($inquiry->travel_date)
                        <button type="button" wire:click="reminderPreset('pre')"
                            class="text-[10px] rounded px-2 py-1"
                            style="background: #fef3c7; color: #92400e;">2 days before travel</button>
                    @endif
                </div>

                <label class="text-[10px] text-gray-500 uppercase tracking-wider" style="margin-top: 10px; display: block;">Message</label>
                <textarea wire:model="reminderMessage" rows="2"
                    placeholder="e.g. Reconfirm {{ $inquiry->customer_name }} before travel"
                    class="w-full text-xs rounded-md border-gray-300 mt-1"
                    style="padding: 6px;"></textarea>

                <button type="button" wire:click="createReminder" @click="open = false"
                    class="w-full text-xs font-medium rounded-md px-3 py-1.5 text-white"
                    style="background: #16a34a; margin-top: 8px;">
                    Save Reminder
                </button>
            </div>
        </div>
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
