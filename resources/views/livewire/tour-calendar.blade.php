{{-- resources/views/livewire/tour-calendar.blade.php --}}
@php
    $cellWidth  = 80;   // px per day column
    $rowHeight  = 28;   // px body rows
    $leftWidth  = 200;  // px “Tour” column
@endphp

<style>
    /* ───── variables ───── */
    #tour-calendar{
        --w:{{ $cellWidth  }}px;   /* width of a single day cell  */
        --h:{{ $rowHeight  }}px;   /* row height                  */
        --l:{{ $leftWidth  }}px;   /* width of first (title) col  */
    }

    /* ───── shared grid styles ───── */
    #tour-calendar .hdr,
    #tour-calendar .row{
        display:grid;
        grid-template-columns:var(--l) repeat({{ $days->count() }},var(--w));
        align-items:center;

        /* background grid: 1-px line every “var(--w)” */
        background-image:repeating-linear-gradient(
            to right,
            rgba(0,0,0,.08) 0 1px,
            transparent     1px var(--w)
        );
        /* don’t draw lines inside the Tour-title column */
        background-position:var(--l) 0;
    }

    /* darker grid for dark-mode */
    @media (prefers-color-scheme: dark){
        #tour-calendar .hdr,
        #tour-calendar .row{
            background-image:repeating-linear-gradient(
                to right,
                rgba(155, 152, 152, 0.973) 0 1px,
                transparent            1px var(--w)
            );
        }
    }

    /* ───── bar style remains the same ───── */
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
      <div style="min-width:calc(var(--l) + {{ $days->count() }} * var(--w))">

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
                    <div  class="bar"
                          wire:click.stop="showBooking({{ $b->id }})"
                          style="grid-column:{{ 2 + $b->start_index }}/span {{ $b->span_days }};
                                 background-color:{{ $b->colour }};">
                        {{ $b->bar_label }}
                    </div>
                @endforeach
            </div>
        @endforeach
      </div>
    </div>

    {{-- once-per-page modal --}}
    @include('livewire.partials.booking-modal')
</div>
