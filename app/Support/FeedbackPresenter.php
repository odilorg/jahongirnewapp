<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\TourFeedback;

/**
 * Cheap value object for the public feedback Blade views — keeps the
 * @php blocks at the top of the templates short (arch-lint rule 1) and
 * concentrates the trivial display-formatting in one place.
 */
final class FeedbackPresenter
{
    public string $googleReviewUrl     = 'https://g.page/r/CYoiUJW5aowWEAE/review';
    public string $tripadvisorReviewUrl = 'https://www.tripadvisor.com/UserReviewEdit-g298068-d17464942-Jahongir_Travel-Samarkand_Samarqand_Province.html';

    public ?string $firstName          = null;
    public ?string $tourTitle          = null;
    public ?string $driverName         = null;
    public ?string $guideName          = null;
    public ?string $accommodationName  = null;

    private function __construct() {}

    public static function make(TourFeedback $feedback): self
    {
        $self = new self();
        $inq  = $feedback->inquiry;

        $first = trim(strtok((string) ($inq->customer_name ?? ''), ' ')) ?: null;
        $self->firstName = $first;

        $title = preg_replace('/\s*\|\s*Jahongir\s+Travel\s*$/iu', '', (string) ($inq->tour_name_snapshot ?? ''));
        $self->tourTitle = $title !== '' ? $title : null;

        $self->driverName        = $feedback->driver?->full_name;
        $self->guideName         = $feedback->guide?->full_name;
        $self->accommodationName = $feedback->accommodation?->name;

        return $self;
    }
}
