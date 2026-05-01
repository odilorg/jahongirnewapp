@props([
    'role',           // 'driver' | 'guide' | 'accommodation' | 'overall'
    'label',          // display label
    'emoji',          // role emoji
    'supplierName' => null,  // shown under label if known
    'issueTags' => null,     // array of key=>label, null for overall
])

<div class="border-t border-gray-100 pt-4 first:border-t-0 first:pt-0">
    <div class="flex items-center justify-between gap-3 mb-2">
        <div>
            <div class="text-sm font-medium text-gray-800">{{ $emoji }} {{ $label }}</div>
            @if ($supplierName)
                <div class="text-xs text-gray-500 mt-0.5">{{ $supplierName }}</div>
            @endif
        </div>
        <div class="flex items-center gap-1" x-data>
            @for ($i = 1; $i <= 5; $i++)
                <button type="button"
                        @click="setRating('{{ $role }}', {{ $i }})"
                        class="star-btn text-2xl leading-none transition-transform active:scale-90"
                        :class="ratings.{{ $role }} >= {{ $i }} ? 'text-amber-400' : 'text-gray-300'"
                        aria-label="{{ $i }} stars">★</button>
            @endfor
        </div>
    </div>

    {{-- Hidden input mirrors Alpine state --}}
    <input type="hidden" name="{{ $role }}_rating" :value="ratings.{{ $role }} || ''">

    @if ($issueTags)
        <div x-show="showChips('{{ $role }}')" x-cloak x-transition
             class="mt-3 p-3 bg-amber-50 rounded-lg border border-amber-100">
            <div class="text-xs text-amber-900 mb-2">What went wrong? <span class="text-amber-700/70">(tap any)</span></div>
            <div class="flex flex-wrap gap-1.5"
                 x-data="{ selected: [] }">
                @foreach ($issueTags as $key => $tagLabel)
                    <label class="cursor-pointer">
                        <input type="checkbox" name="{{ $role }}_issue_tags[]" value="{{ $key }}"
                               class="peer sr-only"
                               x-model="selected">
                        <span class="inline-block px-3 py-1.5 text-xs rounded-full bg-white border border-amber-200 text-amber-900 peer-checked:bg-amber-500 peer-checked:text-white peer-checked:border-amber-500 transition-colors select-none">
                            {{ $tagLabel }}
                        </span>
                    </label>
                @endforeach
            </div>
        </div>
    @endif
</div>
