{{-- resources/views/livewire/tour-calendar.blade.php --}}
@php
    /* config */
    $cell = 80;     // px per day
    $row  = 28;     // px per row
@endphp

<style>
    #tour-calendar{--w:{{ $cell }}px;--h:{{ $row }}px}
    #tour-calendar .hdr,
    #tour-calendar .row{
        display:grid;
        grid-template-columns:200px repeat({{ $days->count() }},var(--w));
        align-items:center
    }
    #tour-calendar .hdr>div,
    #tour-calendar .row>div{border-right:1px solid #e5e7eb}
    .bar{
        height:20px;border-radius:4px;font-size:11px;color:#fff;
        display:flex;align-items:center;justify-content:center;
        white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
        box-shadow:0 0 2px rgba(0,0,0,.25)
    }
</style>

<div id="tour-calendar">

    <h2 class="text-xl font-bold mb-4">
        Tour Booking Calendar ({{ $startDate->format('F Y') }})
    </h2>

    <div class="overflow-x-auto border rounded shadow bg-white">
      <div style="min-width:calc(200px+{{ $days->count() }}*{{ $cell }}px)">

        {{-- header --}}
        <div class="hdr bg-gray-100 border-b">
          <div class="p-2 font-semibold text-sm">Tour</div>
          @foreach($days as $d)
            <div class="h-[var(--h)] text-center text-[11px]
                        {{ $d->isWeekend() ? 'bg-orange-50' : '' }}">
              {{ $d->format('D d') }}
            </div>
          @endforeach
        </div>

        {{-- rows --}}
        @foreach($rows as $title => $items)
          <div class="row border-b" style="min-height:var(--h)">

            <div class="p-2 text-sm bg-gray-50">{{ $title }}</div>

            @foreach($items as $b)
              <div class="bar"
                   style="
                     grid-column: {{ 2 + $b->start_index }} / span {{ $b->span_days }};
                     background-color: {{ $b->colour }};
                   "
                   title="{{ $b->bar_label }}">
                {{ $b->bar_label }}
              </div>
            @endforeach

          </div>
        @endforeach

      </div>
    </div>
</div>
