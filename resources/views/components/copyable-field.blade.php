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
                btn.textContent = '✓ Copied';
                btn.classList.add('text-success-400');
                setTimeout(() => { btn.textContent = 'Copy'; btn.classList.remove('text-success-400'); }, 1500);
            });
        "
        class="copy-btn text-[10px] cursor-pointer px-1.5 py-0.5 rounded bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-100 hover:bg-primary-100 hover:text-primary-700 dark:hover:bg-primary-800 dark:hover:text-primary-200 transition-colors"
        title="Copy to clipboard">
        Copy
    </button>
</span>
@endif
