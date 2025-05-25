<x-filament::modal
    id="bookingModal"
    :visible="$showModal"
    width="2xl"
    wire:key="booking-modal"
    @close.window="$set('showModal', false)"
>
    @isset($selected)
        <x-slot name="heading">
            Booking #{{ $selected->id }}
        </x-slot>

        <x-slot name="content">
            <dl class="divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <div class="py-2 flex justify-between">
                    <dt class="font-medium">Tour</dt>
                    <dd>{{ $selected->tour?->title }}</dd>
                </div>
                <div class="py-2 flex justify-between">
                    <dt class="font-medium">Guest</dt>
                    <dd>{{ $selected->guest?->full_name }}</dd>
                </div>
                <div class="py-2 flex justify-between">
                    <dt class="font-medium">E-mail</dt>
                    <dd>{{ $selected->guest?->email }}</dd>
                </div>
                <div class="py-2 flex justify-between">
                    <dt class="font-medium">Phone</dt>
                    <dd>{{ $selected->guest?->phone }}</dd>
                </div>
                <div class="py-2 flex justify-between">
                    <dt class="font-medium">People</dt>
                    <dd>{{ $selected->people_count ?? '—' }}</dd>
                </div>
                <div class="py-2 flex justify-between">
                    <dt class="font-medium">Pickup&nbsp;/ Drop-off</dt>
                    <dd>{{ $selected->pickup_location ?? '—' }} /
                        {{ $selected->dropoff_location ?? '—' }}</dd>
                </div>
                <div class="py-2 flex justify-between">
                    <dt class="font-medium">Requests</dt>
                    <dd>{{ $selected->special_requests ?: '—' }}</dd>
                </div>
                <div class="py-2 flex justify-between">
                    <dt class="font-medium">Source</dt>
                    <dd class="uppercase">{{ $selected->booking_source }}</dd>
                </div>
                <div class="py-2 flex justify-between">
                    <dt class="font-medium">Driver</dt>
                    <dd>{{ $selected->driver?->full_name ?? '—' }}</dd>
                </div>
                <div class="py-2 flex justify-between">
                    <dt class="font-medium">Guide</dt>
                    <dd>{{ $selected->guide?->full_name ?? '—' }}</dd>
                </div>
            </dl>
        </x-slot>
    @endisset
</x-filament::modal>
