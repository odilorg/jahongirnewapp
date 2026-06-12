<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\WhatsAppMarkupToHtml;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Pre-tour guest reminder, email channel.
 *
 * Used as the fallback when a confirmed booking has no usable WhatsApp
 * number but a valid email (OTA bookings). The body is the SAME content
 * the WhatsApp reminder would carry — built once by TourReminderDispatcher
 * and passed in pre-rendered — so the two channels never drift.
 *
 * `$reference` and `$bodyText` are scalars (not the model) to keep the
 * queued payload small and avoid SerializesModels reloading a stale graph.
 */
class TourGuestReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $reference,
        public string $bodyText,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your tour tomorrow — {$this->reference}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.tour-guest-reminder',
            with: [
                'reference' => $this->reference,
                'bodyHtml'  => WhatsAppMarkupToHtml::convert($this->bodyText),
            ],
        );
    }
}
