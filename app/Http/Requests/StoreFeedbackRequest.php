<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for POST /feedback/{token}.
 *
 * All ratings are optional — a guest who only types a comment is still a
 * useful submission. Issue-tag arrays are validated against the role's
 * config keys so a client can't smuggle arbitrary tags through.
 */
class StoreFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $driverTagKeys        = array_keys((array) config('feedback_issue_tags.driver', []));
        $guideTagKeys         = array_keys((array) config('feedback_issue_tags.guide', []));
        $accommodationTagKeys = array_keys((array) config('feedback_issue_tags.accommodation', []));

        return [
            'driver_rating'        => ['nullable', 'integer', 'min:1', 'max:5'],
            'guide_rating'         => ['nullable', 'integer', 'min:1', 'max:5'],
            'accommodation_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'overall_rating'       => ['nullable', 'integer', 'min:1', 'max:5'],

            'driver_issue_tags'        => ['nullable', 'array', 'max:10'],
            'driver_issue_tags.*'      => ['string', Rule::in($driverTagKeys)],
            'guide_issue_tags'         => ['nullable', 'array', 'max:10'],
            'guide_issue_tags.*'       => ['string', Rule::in($guideTagKeys)],
            'accommodation_issue_tags' => ['nullable', 'array', 'max:10'],
            'accommodation_issue_tags.*' => ['string', Rule::in($accommodationTagKeys)],

            'comments' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** Map validated input into the columns persisted on tour_feedbacks. */
    public function toFeedbackData(): array
    {
        $v = $this->validated();

        return [
            'driver_rating'             => $v['driver_rating']        ?? null,
            'guide_rating'              => $v['guide_rating']         ?? null,
            'accommodation_rating'      => $v['accommodation_rating'] ?? null,
            'overall_rating'            => $v['overall_rating']       ?? null,
            // Only persist tag arrays for roles that actually got a low rating
            // (≤ 3). Tags entered against a 5★ rating are ignored silently —
            // most likely a stale Alpine state from a downgrade-then-upgrade.
            'driver_issue_tags'        => $this->keepTagsIfLow($v['driver_rating']        ?? null, $v['driver_issue_tags']        ?? null),
            'guide_issue_tags'         => $this->keepTagsIfLow($v['guide_rating']         ?? null, $v['guide_issue_tags']         ?? null),
            'accommodation_issue_tags' => $this->keepTagsIfLow($v['accommodation_rating'] ?? null, $v['accommodation_issue_tags'] ?? null),
            'comments' => filled($v['comments'] ?? null) ? trim((string) $v['comments']) : null,
        ];
    }

    private function keepTagsIfLow(?int $rating, ?array $tags): ?array
    {
        if ($rating !== null && $rating <= 3 && filled($tags)) {
            return array_values(array_unique($tags));
        }

        return null;
    }
}
