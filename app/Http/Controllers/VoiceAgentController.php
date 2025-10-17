<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\Beds24BookingService;
use App\Models\RoomUnitMapping;

class VoiceAgentController extends Controller
{
    public function checkAvailability(Request )
    {
        // Reuse existing availability logic from Telegram bot
         = ->validate([
            'arrival_date' => 'required|date',
            'departure_date' => 'required|date|after:arrival_date',
            'number_of_guests' => 'integer|min:1',
        ]);

         = app(Beds24BookingService::class);

        // Get all room IDs
         = RoomUnitMapping::pluck('room_id')->toArray();

        // Check availability using existing method
         = ->checkAvailability(
            ['arrival_date'],
            ['departure_date'],
            
        );

        // Get available rooms
         = ['bookedRoomIds'] ?? [];
         = RoomUnitMapping::whereNotIn('room_id', )->get();

        return response()->json([
            'success' => true,
            'available_rooms' => ,
            'total_available' => ->count(),
            'total_rooms' => count(),
        ]);
    }

    public function createBooking(Request )
    {
        // Reuse existing booking creation logic
         = ->validate([
            'check_in_date' => 'required|date',
            'check_out_date' => 'required|date|after:check_in_date',
            'hotel_name' => 'required|string',
            'room_type' => 'required|string',
            'guest_name' => 'required|string',
            'guest_phone' => 'required|string',
            'guest_email' => 'required|email',
            'number_of_guests' => 'integer|min:1',
            'special_requests' => 'nullable|string',
        ]);

         = app(Beds24BookingService::class);

        // Use existing createBooking method
         = ->createBooking();

        return response()->json();
    }

    public function getGuestByPhone()
    {
        // Reuse existing guest lookup logic
        // This should match your Telegram bot's guest lookup

        return response()->json([
            'found' => false,
            'message' => 'Guest lookup not implemented yet',
        ]);
    }
}
