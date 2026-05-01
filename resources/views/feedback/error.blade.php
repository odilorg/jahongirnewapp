<x-feedback.layout title="Something went wrong · Jahongir Travel">
    <div class="text-center py-4">
        <div class="text-5xl mb-3">😕</div>
        <h2 class="text-xl font-semibold text-gray-900">Something went wrong</h2>
        <p class="text-sm text-gray-600 mt-2">
            We couldn't save your feedback right now. Please try again in a moment.
        </p>
        <a href="{{ url()->previous() }}"
           class="inline-block mt-5 text-amber-600 underline text-sm">Try again</a>
    </div>
</x-feedback.layout>
