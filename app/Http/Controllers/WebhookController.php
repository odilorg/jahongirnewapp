<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Guest;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;

class WebhookController extends Controller
{
    public function handleTourBooking(Request $request)
    {
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
                'full_name' => $data['Name'], // optional if you need to store full
            ]);

            // Insert into bookings table
            $booking = Booking::create([
                'guest_id' => $guest->id,
               
                'departure_date' => $data['Departure Date Time'],
                'number_of_people' => $data['Number of people'],
                'pickup_location' => $data['Meeting Point '],
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
