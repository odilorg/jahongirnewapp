@props([
    'value' => '',
    'display' => null,
    'icon' => '📋',
])

@php
    $displayText = $display ?? $value;
    $uniqueId = 'copy-' . md5($value . microtime());
@endphp

@if ($value)
<span class="inline-flex items-center gap-1.5 group" id="{{ $uniqueId }}">
    <span>{{ $displayText }}</span>
    <button type="button"
        onclick="
            navigator.clipboard.writeText('{{ e($value) }}').then(() => {
                const btn = document.querySelector('#{{ $uniqueId }} .copy-btn');
                btn.innerHTML = '✓';
                setTimeout(() => { btn.innerHTML = '📋'; }, 1200);
            });
        "
        class="copy-btn cursor-pointer opacity-60 hover:opacity-100 transition-opacity text-[11px] leading-none"
        title="Copy">📋</button>
</span>
@endif
