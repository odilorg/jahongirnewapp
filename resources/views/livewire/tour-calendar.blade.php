{{-- resources/views/livewire/tour-calendar.blade.php --}}
@php
    $cellWidth = 80;  $rowHeight = 28;
@endphp

<style>
    #tour-calendar{--w:{{ $cellWidth }}px;--h:{{ $rowHeight }}px}
    #tour-calendar .hdr,
    #tour-calendar .row{
        display:grid;
        grid-template-columns:200px repeat({{ $days->count() }},var(--w));
        align-items:center;
    }
    #tour-calendar .bar{
        height:20px;border-radius:4px;font-size:11px;
        display:flex;align-items:center;justify-content:center;
        overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
        box-shadow:0 0 2px rgb(0 0 0 / .25);color:#fff;cursor:pointer;
    }
</style>

<div id="tour-calendar">
    <h2 class="text-xl font-bold mb-4">
        Tour Booking Calendar ({{ $startDate->format('F Y') }})
    </h2>

    <div class="overflow-x-auto border rounded shadow bg-white dark:bg-gray-900">
      <div style="min-width:calc(200px + {{ $days->count() }} * {{ $cellWidth }}px)">

        {{-- header --}}
        <div class="hdr border-b">
            <div class="p-2 font-semibold text-sm">Tour</div>
            @foreach($days as $d)
                <div class="h-[var(--h)] text-center text-[11px]">
                    {{ $d->format('D d') }}
                </div>
            @endforeach
        </div>

        {{-- rows --}}
        @foreach($rows as $title => $items)
            <div class="row border-b" style="min-height:var(--h)">
                <div class="p-2 text-sm bg-gray-50 dark:bg-gray-800">{{ $title }}</div>

                @foreach($items as $b)
                    <div class="bar"
                         wire:click.stop="showBooking({{ $b->id }})"
                         style="grid-column:{{ 2+$b->start_index }}/span {{ $b->span_days }};
                                background-color:{{ $b->colour }};">
                        {{ $b->bar_label }}
                    </div>
                @endforeach
            </div>
        @endforeach
      </div>
    </div>

    {{-- modal once --}}
    @include('livewire.partials.booking-modal')
</div>
