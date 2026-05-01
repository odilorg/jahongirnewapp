@php
    $presenter = \App\Support\FeedbackPresenter::make($feedback);
@endphp

<x-feedback.layout title="How was your trip?">
    <div class="text-center mb-5">
        <div class="text-3xl mb-2">⭐</div>
        <h2 class="text-xl font-semibold text-gray-900">How was your trip{{ $presenter->firstName ? ", {$presenter->firstName}" : '' }}?</h2>
        @if ($presenter->tourTitle)
            <p class="text-sm text-gray-500 mt-1">{{ $presenter->tourTitle }}</p>
        @endif
        <p class="text-xs text-gray-400 mt-1">Takes 30 seconds · Your honest feedback helps us improve.</p>
    </div>

    <form method="POST" action="{{ route('feedback.store', $feedback->token) }}"
          x-data="{
              ratings: { driver: 0, guide: 0, accommodation: 0, overall: 0 },
              setRating(role, n) { this.ratings[role] = n; },
              showChips(role) { return this.ratings[role] > 0 && this.ratings[role] <= 3; }
          }"
          class="space-y-7">
        @csrf

        {{-- Driver --}}
        @if ($feedback->driver_id)
            <x-feedback.rating-row
                :role="'driver'"
                :label="'How was your driver?'"
                :emoji="'🚗'"
                :supplier-name="$presenter->driverName"
                :issue-tags="$issueTags['driver']"
            />
        @endif

        {{-- Guide --}}
        @if ($feedback->guide_id)
            <x-feedback.rating-row
                :role="'guide'"
                :label="'How was your guide?'"
                :emoji="'🧭'"
                :supplier-name="$presenter->guideName"
                :issue-tags="$issueTags['guide']"
            />
        @endif

        {{-- Accommodation --}}
        @if ($feedback->accommodation_id)
            <x-feedback.rating-row
                :role="'accommodation'"
                :label="'How was your stay?'"
                :emoji="'🏕'"
                :supplier-name="$presenter->accommodationName"
                :issue-tags="$issueTags['accommodation']"
            />
        @endif

        {{-- Overall — always shown --}}
        <x-feedback.rating-row
            :role="'overall'"
            :label="'How was the overall experience?'"
            :emoji="'⭐'"
            :supplier-name="null"
            :issue-tags="null"
        />

        <div class="border-t border-gray-100 pt-5">
            <label for="comments" class="block text-sm font-medium text-gray-700 mb-2">
                What went well, or what could we improve?
                <span class="text-gray-400 font-normal block text-xs mt-0.5">Optional — your words help us most.</span>
            </label>
            <textarea name="comments" id="comments" rows="3" maxlength="2000"
                      class="w-full rounded-lg border-gray-200 focus:border-amber-400 focus:ring-amber-400 text-sm py-2.5"
                      placeholder="Anything that stood out, good or bad…"></textarea>
        </div>

        <button type="submit"
                class="w-full bg-amber-500 hover:bg-amber-600 text-white font-medium py-3 rounded-xl transition-colors">
            Submit feedback
        </button>

        <p class="text-center text-[11px] text-gray-400">
            Your feedback is private and only used to improve our service.
        </p>
    </form>
</x-feedback.layout>
