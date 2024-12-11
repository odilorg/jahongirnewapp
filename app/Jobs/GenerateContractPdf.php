<?php
namespace App\Jobs;

use App\Models\Contract;
use App\Models\Room; // Import the Room model
use App\Models\RoomType;
use Barryvdh\DomPDF\Facade\Pdf as PDF;
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
         // Fetch the associated hotel
        // $hotel = $this->contract->hotel;
        // Check if the Barryvdh\DomPDF\Facade class exists
    //dd(class_exists(PDF::class));
        Log::info('GenerateContractPdf Job handling started.');
        Log::info('Environment: ' . app()->environment());
        try {

            // Define the contract_end_date based on the condition
        $contractDate = \Carbon\Carbon::parse($this->contract->date);
        $contractMonth = $contractDate->month;

        if ($contractMonth === 11 || $contractMonth === 12) { // November or December
            $contract_end_date = $contractDate->year + 1; // Next year
        } else {
            $contract_end_date = $contractDate->year; // Current year
        }

            
            // Define the hotels to process
            $hotels = [
                1 => 'Jahongir',
                2 => 'JahongirPr'
            ];




            $hotelData = collect();

            foreach ($hotels as $hotelId => $hotelName) {
                $roomData = RoomType::with('hotel')->where('hotel_id', $hotelId)->get();
                $totalBeds = $roomData->sum('number_of_beds');
                $totalNumber = $roomData->sum('quantity');

                $hotelData->put($hotelId, [ // Use hotelId as the key
                    'hotelName' => $hotelName, // Include the hotel name in the data
                    'rooms' => $roomData,
                    'totalBeds' => $totalBeds,
                    'totalNumber' => $totalNumber
                ]);

                // Logging for each hotel
                Log::info("Processed data for Hotel: $hotelName", [
                    'hotelId' => $hotelId,
                    'totalBeds' => $totalBeds,
                    'totalNumber' => $totalNumber,
                ]);
            }

            // Log the contract details
            Log::info('Contract details', ['contract' => $this->contract]);
            Log::info('Hotel Data being passed to the view:', ['hotelData' => $hotelData->toArray()]);

            // Generate the PDF
            $pdf = PDF::loadView('contracts.contract', [
                'contract' => $this->contract,
                'hotelData' => $hotelData,
                'contract_end_date' => $contract_end_date, // Pass the contract_end_date to the view
               
            ]);
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
