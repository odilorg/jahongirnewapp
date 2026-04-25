<?php

declare(strict_types=1);

namespace App\Mail;

use App\Actions\Payment\Support\ReceiptContext;
use App\Models\BookingInquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingPaymentReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public BookingInquiry $inquiry,
        public ReceiptContext $ctx,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your booking is confirmed – Jahongir Travel',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.booking-payment-receipt',
            with: [
                'inquiry' => $this->inquiry,
                'ctx'     => $this->ctx,
            ],
        );
    }
}
