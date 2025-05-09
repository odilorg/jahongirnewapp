<?php
namespace App\Http\Controllers;

use App\Models\Guest;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function handleTourBooking(Request $request)
    {
        Log::info('Incoming API key: ' . $request->header('X-API-KEY'));

        // 🔒 API key protection
        if ($request->header('x-api-key') !== env('N8N_SECRET_KEY')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
    
        // Get data
        $data = $request->all();
    
        // Split full name
        $nameParts = explode(' ', $data['Name'], 2);
        $firstName = $nameParts[0];
        $lastName = $nameParts[1] ?? '';
    
        DB::beginTransaction();
        try {
            // Insert into guests table
            $guest = Guest::create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $data['Email'],
                'phone' => $data['Phone'],
                'country' => $data['Country'],
                'number_of_people' => $data['Number of people'],
                'full_name' => $data['Name'], // optional if you need to store full
            ]);
    
            // Insert into bookings table
            $booking = Booking::create([
                'guest_id' => $guest->id,
                'booking_start_date_time' => $data['Departure Date Time'],
                'pickup_location' => $data['Meeting Point'],
                'special_requests' => $data['Mesage'] ?? null,
            ]);
    
            DB::commit();
            return response()->json(['status' => 'success'], 200);
    
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    
}
