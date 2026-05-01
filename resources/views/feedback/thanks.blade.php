@php
    $presenter = \App\Support\FeedbackPresenter::make($feedback);
@endphp

<x-feedback.layout title="Thank you · Jahongir Travel">
    <div class="text-center py-4">
        @if ($showPublicReview)
            {{-- Happy guest path: standard thank-you + public review CTAs --}}
            <div class="text-5xl mb-3">🙏</div>
            <h2 class="text-xl font-semibold text-gray-900">
                Thank you{{ $presenter->firstName ? ", {$presenter->firstName}" : '' }}!
            </h2>
            <p class="text-sm text-gray-600 mt-2">
                We're so glad you enjoyed your trip. Your feedback genuinely helps our team.
            </p>

            <div class="mt-6 pt-5 border-t border-gray-100">
                <p class="text-sm text-gray-700 mb-3">
                    If you have a moment, please share your experience with future travellers:
                </p>
                <div class="space-y-2">
                    <a href="{{ $presenter->googleReviewUrl }}" target="_blank" rel="noopener"
                       class="block w-full bg-white hover:bg-gray-50 border border-gray-200 text-gray-800 font-medium py-3 rounded-xl transition-colors">
                        🌟 Review us on Google
                    </a>
                    <a href="{{ $presenter->tripadvisorReviewUrl }}" target="_blank" rel="noopener"
                       class="block w-full bg-white hover:bg-gray-50 border border-gray-200 text-gray-800 font-medium py-3 rounded-xl transition-colors">
                        🌟 Review us on TripAdvisor
                    </a>
                </div>
            </div>
        @else
            {{-- Low-rating path: empathy, NO public review CTAs --}}
            <div class="text-5xl mb-3">🙇</div>
            <h2 class="text-xl font-semibold text-gray-900">
                Thank you for telling us{{ $presenter->firstName ? ", {$presenter->firstName}" : '' }}.
            </h2>
            <p class="text-sm text-gray-600 mt-3">
                We're sorry your experience didn't meet expectations. Our team will review your feedback and reach out if appropriate.
            </p>
            <p class="text-sm text-gray-600 mt-2">
                Thank you for giving us the chance to improve.
            </p>
        @endif
    </div>
</x-feedback.layout>
