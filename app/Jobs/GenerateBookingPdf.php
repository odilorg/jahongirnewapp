<?php

namespace App\Jobs;

use App\Models\Booking;
use Barryvdh\DomPDF\Facade\Pdf as PDF;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Storage;


class GenerateBookingPdf implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    protected $booking;

    public function __construct(Booking $booking)
    {
        $this->booking = $booking;
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        $pdf = Pdf::loadView('confirmations.booking-confirmation', ['booking' => $this->booking])
        ->setPaper('a4', 'portrait')
        ->setWarnings(false);



       // $pdf = Pdf::loadView('confirmations.booking-confirmation', ['booking' => $this->booking]);

        $fileName = 'booking-confirmation-' . $this->booking->id . '.pdf';

        Storage::put("public/confirmations/{$fileName}", $pdf->output());

       // Storage::put($filePath, $pdf->output());


        $this->booking->updateQuietly([
            'file_name' => $fileName,
        ]);
    }
}
