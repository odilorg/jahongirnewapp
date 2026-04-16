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
                const orig = btn.textContent;
                btn.textContent = '✓';
                btn.classList.add('text-success-500');
                setTimeout(() => { btn.textContent = orig; btn.classList.remove('text-success-500'); }, 1500);
            });
        "
        class="copy-btn opacity-0 group-hover:opacity-100 transition-opacity text-xs cursor-pointer hover:scale-110"
        title="Copy to clipboard">
        {{ $icon }}
    </button>
</span>
@endif
