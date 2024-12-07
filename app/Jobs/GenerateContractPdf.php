<?php
namespace App\Jobs;

use App\Models\Contract;
use Barryvdh\DomPDF\Facade\PDF;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateContractPdf implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $contract;

    /**
     * Create a new job instance.
     *
     * @param Contract $contract
     */
    public function __construct(Contract $contract)
    {
        $this->contract = $contract;
        Log::info('GenerateContractPdf Job initialized with Contract ID: ' . $contract->id);
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {

        Log::info('GenerateContractPdf Job handling started.');
        Log::info('Environment: ' . app()->environment());
        try {
            // Log the contract data
            Log::info('GenerateContractPdf Job started for Contract ID: ' . $this->contract->id);

            // Generate the PDF
            $pdf = PDF::loadView('contracts.contract', ['contract' => $this->contract]);
            Log::info('PDF generated successfully for Contract ID: ' . $this->contract->id);

            // Define file name and path
            $fileName = 'contract_' . $this->contract->id . '.pdf';
            $filePath = 'public/contracts/' . $fileName;

            // Save the PDF file to the storage
            Storage::put($filePath, $pdf->output());
            Log::info('PDF saved successfully to storage: ' . $filePath);

            // Update the contract's file_name in the database
            $this->contract->update(['file_name' => $fileName]);
            Log::info('Database updated with file_name: ' . $fileName . ' for Contract ID: ' . $this->contract->id);

        } catch (\Exception $e) {
            // Log any exceptions
            Log::error('Error in GenerateContractPdf Job: ' . $e->getMessage());
        }
    }
}
