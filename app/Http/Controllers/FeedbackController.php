<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Feedback\SubmitFeedbackAction;
use App\Http\Requests\StoreFeedbackRequest;
use App\Models\TourFeedback;
use Illuminate\View\View;

/**
 * Public, token-gated post-tour feedback flow.
 *
 *   GET  /feedback/{token}     show form (or "already submitted" page)
 *   POST /feedback/{token}     persist ratings, branch to thank-you page
 *
 * The token (32-char URL-safe random) is the auth: knowing it is sufficient
 * to submit. Single-use — re-visiting after submit shows a read-only page.
 *
 * The form is dynamic: only renders rating rows for roles that were
 * actually assigned to this inquiry at send-time. Overall is always shown.
 * Issue-tag chips reveal client-side via Alpine when a rating ≤ 3.
 */
class FeedbackController extends Controller
{
    public function show(string $token): View
    {
        $feedback = TourFeedback::where('token', $token)
            ->with([
                'driver:id,first_name,last_name',
                'guide:id,first_name,last_name',
                'accommodation:id,name',
                'inquiry:id,reference,customer_name,tour_name_snapshot,travel_date',
            ])
            ->firstOrFail();

        if ($feedback->submitted_at !== null) {
            return view('feedback.already-submitted', ['feedback' => $feedback]);
        }

        return view('feedback.show', [
            'feedback'  => $feedback,
            'issueTags' => config('feedback_issue_tags'),
        ]);
    }

    public function store(StoreFeedbackRequest $request, string $token, SubmitFeedbackAction $submit): View
    {
        $feedback = TourFeedback::where('token', $token)->firstOrFail();

        $ok = $submit->execute($feedback, $request->toFeedbackData(), (string) $request->ip());

        if (! $ok) {
            return view('feedback.error', ['feedback' => $feedback]);
        }

        return view('feedback.thanks', [
            'feedback'         => $feedback->fresh(),
            'showPublicReview' => ! $feedback->fresh()->isLowRated(),
        ]);
    }
}
