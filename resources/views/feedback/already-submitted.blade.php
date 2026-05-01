<x-feedback.layout title="Already received · Jahongir Travel">
    <div class="text-center py-4">
        <div class="text-5xl mb-3">🙏</div>
        <h2 class="text-xl font-semibold text-gray-900">Thank you — already received</h2>
        <p class="text-sm text-gray-600 mt-3">
            We've already got your feedback for this trip — and we genuinely appreciate it.
        </p>
        <p class="text-sm text-gray-600 mt-2">
            Wishing you safe travels onwards. ✨
        </p>
        <p class="text-xs text-gray-400 mt-5">
            Submitted {{ $feedback->submitted_at?->diffForHumans() }}
        </p>
    </div>
</x-feedback.layout>
