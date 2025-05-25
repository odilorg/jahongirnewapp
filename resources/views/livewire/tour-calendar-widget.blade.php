{{-- resources/views/livewire/tour-calendar.blade.php --}}
@php
    $cellWidth  = 80;   // px per day column
    $rowHeight  = 28;   // px body rows
    $leftWidth  = 200;  // px “Tour” label column
@endphp

<style>
    /* ───── CSS variables ───── */
    #tour-calendar{
        --w:{{ $cellWidth }}px;
        --h:{{ $rowHeight }}px;
        --l:{{ $leftWidth }}px;
    }

    /* ───── grid layout with vertical lines ───── */
    #tour-calendar .hdr,
    #tour-calendar .row{
        display:grid;
        grid-template-columns:var(--l) repeat({{ $days->count() }}, var(--w));
        align-items:center;

        background-image:repeating-linear-gradient(
            to right,
            rgba(0,0,0,1) 0 1px,
            transparent     1px var(--w)
        );
        background-position:var(--l) 0;
    }
    @media (prefers-color-scheme: dark){
        #tour-calendar .hdr,
        #tour-calendar .row{
            background-image:repeating-linear-gradient(
                to right,
                rgba(255, 255, 255, 0.5) 0 1px,
                transparent            1px var(--w)
            );
        }
    }

    /* Dark mode for Filament / Tailwind “dark” class */
.dark #tour-calendar .hdr,
.dark #tour-calendar .row{
    background-image:repeating-linear-gradient(
        to right,
        rgba(255,255,255,1.10) 0 1px,
        transparent              1px var(--w)
    );
}

    /* ───── booking bar style ───── */
    #tour-calendar .bar{
        height:20px;border-radius:4px;font-size:11px;
        display:flex;align-items:center;justify-content:center;
        overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
        box-shadow:0 0 2px rgb(0 0 0 / .25);color:#fff;cursor:pointer;
    }
</style>

<div id="tour-calendar"
     x-data="{
         /* Scroll the container so today is flush left, if visible */
         autoScroll() {
             const c = this.$refs.scroll, t = this.$refs.todayCell;
             if (c && t) {
                 c.scrollTo({ left: t.offsetLeft - {{ $leftWidth }}, behavior: 'smooth' });
             }
         },

         /* Manual scroll-only button inside calendar header */
         scrollToToday() { this.autoScroll(); },

         /* For older Livewire ‘Today’ button (still present) */
         goToToday() {
             $wire.goToToday().then(() => {
                 this.$nextTick(() => this.autoScroll());
             });
         }
     }"
     x-init="autoScroll()"
>
    {{-- Title row with scroll-only Today button --}}
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-xl font-bold">
            Tour Booking Calendar ({{ $startDate->format('F Y') }})
        </h2>

        <button type="button"
                class="px-3 py-1 rounded bg-blue-600 text-white text-sm hover:bg-blue-700 focus:outline-none"
                @click="scrollToToday()">
            Today
        </button>
    </div>

    <div x-ref="scroll"
         class="overflow-x-auto border rounded shadow bg-white dark:bg-gray-900">
        <div style="min-width:calc({{ $leftWidth }}px + {{ $days->count() }} * {{ $cellWidth }}px)">

            {{-- ───── header (days) ───── --}}
            <div class="hdr border-b">
                <div class="p-2 font-semibold text-sm">Tour</div>
                @foreach($days as $d)
                    <div class="h-[var(--h)] text-center text-[11px]
                                {{ $d->isToday() ? 'font-bold text-blue-600 dark:text-blue-400' : '' }}"
                         @if($d->isToday()) x-ref="todayCell" @endif>
                        {{ $d->format('D d') }}
                    </div>
                @endforeach
            </div>

            {{-- ───── body rows ───── --}}
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

    {{-- booking-details modal --}}
    @include('livewire.partials.booking-modal')
</div>
