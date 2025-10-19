<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Livekit\AccessToken;
use Livekit\VideoGrant;

class LiveKitTokenController extends Controller
{
    public function generateToken(Request $request)
    {
        $roomName = $request->input('room', 'jahongir-hotel-voice-agent');
        $participantName = $request->input('participant', 'guest-' . uniqid());

        try {
            // Get LiveKit credentials from environment
            $apiKey = env('LIVEKIT_API_KEY');
            $apiSecret = env('LIVEKIT_API_SECRET');

            if (!$apiKey || !$apiSecret) {
                return response()->json([
                    'success' => false,
                    'error' => 'LiveKit credentials not configured'
                ], 500);
            }

            // Create access token
            $token = new AccessToken($apiKey, $apiSecret, [
                'identity' => $participantName,
                'ttl' => '1h',
            ]);

            // Add video grant
            $grant = new VideoGrant();
            $grant->setRoomJoin(true);
            $grant->setRoom($roomName);
            $token->addGrant($grant);

            $jwt = $token->toJwt();

            return response()->json([
                'success' => true,
                'token' => $jwt,
                'room' => $roomName,
                'participant' => $participantName,
                'url' => env('LIVEKIT_URL', 'ws://localhost:7880')
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
