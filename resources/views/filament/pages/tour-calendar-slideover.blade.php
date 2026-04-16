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

    {{-- Driver + Guide --}}
    <div class="grid grid-cols-2 gap-3">
        <div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Driver</div>
            @if ($inquiry->driver)
                <div class="text-gray-900 dark:text-gray-100">🚗 {{ $inquiry->driver->full_name }}</div>
                @if ($inquiry->driver->phone01)
                    <div class="text-xs text-gray-800 dark:text-gray-100">
                        <x-copyable-field
                            :value="\App\Support\PhoneFormatter::normalizeForCopy($inquiry->driver->phone01)"
                            :display="\App\Support\PhoneFormatter::format($inquiry->driver->phone01)" />
                    </div>
                @endif
            @else
                <div class="text-danger-500">Not assigned</div>
            @endif
        </div>
        <div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Guide</div>
            @if ($inquiry->guide)
                <div class="text-gray-900 dark:text-gray-100">🧭 {{ $inquiry->guide->full_name }}</div>
                @if ($inquiry->guide->phone01)
                    <div class="text-xs text-gray-800 dark:text-gray-100">
                        <x-copyable-field
                            :value="\App\Support\PhoneFormatter::normalizeForCopy($inquiry->guide->phone01)"
                            :display="\App\Support\PhoneFormatter::format($inquiry->guide->phone01)" />
                    </div>
                @endif
            @else
                <div class="text-gray-500 dark:text-gray-400">—</div>
            @endif
        </div>
    </div>

    {{-- Accommodations --}}
    @if ($inquiry->stays->isNotEmpty())
        <div>
            <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Accommodations</div>
            @foreach ($inquiry->stays as $stay)
                <div class="text-gray-900 dark:text-gray-100">
                    🏕 {{ $stay->accommodation?->name ?? '—' }}
                    · {{ $stay->stay_date?->format('M j') }}
                    · {{ $stay->nights }}N
                </div>
            @endforeach
        </div>
    @endif

    {{-- Price --}}
    @if ($inquiry->price_quoted)
        <div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Price quoted</div>
            <div class="font-semibold text-gray-900 dark:text-gray-100">${{ number_format((float) $inquiry->price_quoted, 2) }} {{ $inquiry->currency }}</div>
        </div>
    @endif

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
