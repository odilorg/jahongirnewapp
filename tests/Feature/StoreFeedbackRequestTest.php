<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Requests\StoreFeedbackRequest;
use Illuminate\Container\Container;
use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Regression for the production 500 reported by guest Ruyi 2026-05-04:
 * StoreFeedbackRequest::keepTagsIfLow(?int $rating) tripped a strict-types
 * TypeError because Laravel's `integer` rule validates but does not cast,
 * so submit-with-stars produced "5" (string) end-to-end.
 *
 * The fix is prepareForValidation() coercion. These tests pin that
 * contract and the toFeedbackData() output shape so the regression
 * cannot recur silently.
 */
class StoreFeedbackRequestTest extends TestCase
{
    /** @test */
    public function string_ratings_are_coerced_to_int_before_validation(): void
    {
        $request = $this->makeFeedbackRequest([
            'driver_rating'        => '5',
            'guide_rating'         => '4',
            'accommodation_rating' => '3',
            'overall_rating'       => '5',
            'comments'             => 'great trip',
        ]);

        $data = $request->toFeedbackData();

        $this->assertSame(5, $data['driver_rating']);
        $this->assertSame(4, $data['guide_rating']);
        $this->assertSame(3, $data['accommodation_rating']);
        $this->assertSame(5, $data['overall_rating']);
        $this->assertSame('great trip', $data['comments']);
    }

    /** @test */
    public function low_rating_with_string_input_keeps_issue_tags(): void
    {
        config()->set('feedback_issue_tags.driver', ['rude' => 'Was rude', 'late' => 'Late pickup']);

        $request = $this->makeFeedbackRequest([
            'driver_rating'      => '2',
            'driver_issue_tags'  => ['rude', 'late'],
        ]);

        $data = $request->toFeedbackData();

        $this->assertSame(2, $data['driver_rating']);
        $this->assertSame(['rude', 'late'], $data['driver_issue_tags']);
    }

    /** @test */
    public function high_rating_silently_drops_issue_tags(): void
    {
        config()->set('feedback_issue_tags.driver', ['rude' => 'Was rude']);

        $request = $this->makeFeedbackRequest([
            'driver_rating'     => '5',
            'driver_issue_tags' => ['rude'],
        ]);

        $data = $request->toFeedbackData();

        $this->assertSame(5, $data['driver_rating']);
        $this->assertNull($data['driver_issue_tags']);
    }

    /** @test */
    public function comments_only_submission_yields_null_ratings(): void
    {
        $request = $this->makeFeedbackRequest([
            'comments' => 'no stars, just words',
        ]);

        $data = $request->toFeedbackData();

        $this->assertNull($data['driver_rating']);
        $this->assertNull($data['overall_rating']);
        $this->assertSame('no stars, just words', $data['comments']);
    }

    /** @test */
    public function empty_string_rating_becomes_null_not_zero(): void
    {
        $request = $this->makeFeedbackRequest([
            'overall_rating' => '',
            'comments'       => 'x',
        ]);

        $data = $request->toFeedbackData();

        $this->assertNull($data['overall_rating']);
    }

    /**
     * Build a fully-resolved StoreFeedbackRequest the way the framework
     * would when handling an incoming POST — including prepareForValidation,
     * rule resolution, and validated() population. Anything less leaves
     * the coercion path untested.
     */
    private function makeFeedbackRequest(array $payload): StoreFeedbackRequest
    {
        $request = StoreFeedbackRequest::create('/feedback/test', 'POST', $payload);
        $request->setContainer(Container::getInstance())
            ->setRedirector(app('redirect'))
            ->validateResolved();

        return $request;
    }
}
