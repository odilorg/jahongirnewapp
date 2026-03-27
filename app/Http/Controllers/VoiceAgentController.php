<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\Beds24BookingService;
use App\Models\RoomUnitMapping;

class VoiceAgentController extends Controller
{
    public function checkAvailability(Request $request)
    {
        $data = $request->validate([
            'arrival_date'    => 'required|date',
            'departure_date'  => 'required|date|after:arrival_date',
            'number_of_guests' => 'integer|min:1',
        ]);

        $service = app(Beds24BookingService::class);
        $roomIds = RoomUnitMapping::pluck('room_id')->toArray();

        $availability = $service->checkAvailability(
            $data['arrival_date'],
            $data['departure_date'],
            $roomIds
        );

        $bookedRoomIds   = $availability['bookedRoomIds'] ?? [];
        $availableRooms  = RoomUnitMapping::whereNotIn('room_id', $bookedRoomIds)->get();

        return response()->json([
            'success'         => true,
            'available_rooms' => $availableRooms,
            'total_available' => $availableRooms->count(),
            'total_rooms'     => count($roomIds),
        ]);
    }

    public function createBooking(Request $request)
    {
        $data = $request->validate([
            'check_in_date'    => 'required|date',
            'check_out_date'   => 'required|date|after:check_in_date',
            'hotel_name'       => 'required|string',
            'room_type'        => 'required|string',
            'guest_name'       => 'required|string',
            'guest_phone'      => 'required|string',
            'guest_email'      => 'required|email',
            'number_of_guests' => 'integer|min:1',
            'special_requests' => 'nullable|string',
        ]);

        $service = app(Beds24BookingService::class);
        $result  = $service->createBooking($data);

        return response()->json($result);
    }

    public function getGuestByPhone(Request $request)
    {
        return response()->json([
            'found'   => false,
            'message' => 'Guest lookup not implemented yet',
        ]);
    }
}
