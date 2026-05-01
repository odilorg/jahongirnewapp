@props([
    'role',           // 'driver' | 'guide' | 'accommodation' | 'overall'
    'label',          // primary question label (e.g. "How was your driver?")
    'emoji',          // role emoji
    'supplierName' => null,  // shown under label if known (real supplier name)
    'issueTags' => null,     // array of key=>label, null for overall
])

<div class="border-t border-gray-100 pt-5 first:border-t-0 first:pt-0">
    <div class="mb-3">
        <div class="text-base font-medium text-gray-800">{{ $emoji }} {{ $label }}</div>
        @if ($supplierName)
            <div class="text-xs text-gray-500 mt-0.5">{{ $supplierName }}</div>
        @endif
    </div>

    <div class="flex items-center justify-center gap-3 sm:gap-4" x-data>
        @for ($i = 1; $i <= 5; $i++)
            {{-- Stars are the core interaction. Inactive bumped from gray-300
                 to gray-400 for stronger contrast outdoors / on bright screens.
                 Selected stars get a subtle "pop" via scale-125 — feels more
                 satisfying without being childish. --}}
            <button type="button"
                    @click="setRating('{{ $role }}', {{ $i }})"
                    class="star-btn text-[2.6rem] sm:text-5xl leading-none p-0.5 transition-all duration-200 ease-out active:scale-90"
                    :class="ratings.{{ $role }} >= {{ $i }} ? 'text-amber-400 drop-shadow scale-125' : 'text-gray-400 hover:text-amber-200'"
                    aria-label="{{ $i }} stars">★</button>
        @endfor
    </div>

    {{-- Hidden input mirrors Alpine state --}}
    <input type="hidden" name="{{ $role }}_rating" :value="ratings.{{ $role }} || ''">

    @if ($issueTags)
        <div x-show="showChips('{{ $role }}')" x-cloak x-transition
             class="mt-4 p-3 bg-amber-50 rounded-lg border border-amber-100">
            <div class="text-xs text-amber-900 mb-2">What went wrong? <span class="text-amber-700/70">(tap any)</span></div>
            <div class="flex flex-wrap gap-1.5">
                @foreach ($issueTags as $key => $tagLabel)
                    <label class="cursor-pointer">
                        <input type="checkbox" name="{{ $role }}_issue_tags[]" value="{{ $key }}" class="peer sr-only">
                        <span class="inline-block px-3 py-1.5 text-xs rounded-full bg-white border border-amber-200 text-amber-900 peer-checked:bg-amber-500 peer-checked:text-white peer-checked:border-amber-500 transition-colors select-none">
                            {{ $tagLabel }}
                        </span>
                    </label>
                @endforeach
            </div>
        </div>
    @endif
</div>
