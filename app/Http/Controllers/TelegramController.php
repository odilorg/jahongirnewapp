<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Guide;
use App\Models\Tour;
use App\Models\Driver;
use App\Models\TelegramConversation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class TelegramController extends Controller
{
    protected $authorizedChatId = '38738713';
    protected $botToken;
    protected $conversationTimeout = 15; // in minutes

    public function __construct()
    {
        $this->botToken = config('services.telegram_bot.token');
    }

    public function handleWebhook(Request $request)
    {
        if ($callback = $request->input('callback_query')) {
            return $this->handleCallbackQuery($callback);
        }
        return $this->processMessage($request);
    }

    protected function processMessage(Request $request)
    {
        $message = $request->input('message');
        if (!$message) {
            Log::error("No 'message' in webhook payload.");
            return response('OK');
        }

        $chatId = $message['chat']['id'] ?? null;
        $text   = trim($message['text'] ?? '');

        if ($chatId != $this->authorizedChatId) {
            Log::warning("Unauthorized chat ID: {$chatId}");
            return response('OK');
        }

        // Decide if /create or /update or if user is in a conversation
        if (Str::startsWith($text, '/create')) {
            return $this->startCreateBookingFlow($chatId);
        }
        if (Str::startsWith($text, '/update')) {
            return $this->startUpdateBookingFlow($chatId);
        }

        // If we’re in a conversation flow, handle typed input
        $conversation = $this->getActiveConversation($chatId);
        if ($conversation) {
            if (($conversation->data['flow_type'] ?? '') === 'create') {
                return $this->handleCreateFlow($conversation, $text, false);
            } elseif (($conversation->data['flow_type'] ?? '') === 'update') {
                return $this->handleUpdateFlow($conversation, $text, false);
            }
        }

        $this->sendTelegramMessage($chatId, "Command not recognized. Try /create or /update.");
        return response('OK');
    }

    protected function handleCallbackQuery(array $callback)
    {
        $chatId       = $callback['message']['chat']['id'] ?? null;
        $callbackData = $callback['data'] ?? '';
        $callbackId   = $callback['id'];

        // Acknowledge callback
        try {
            $this->answerCallbackQuery($callbackId);
        } catch (\Exception $e) {
            Log::warning("Failed to answerCallbackQuery: " . $e->getMessage());
        }

        if ($chatId != $this->authorizedChatId) {
            Log::warning("Unauthorized callback from chat ID: {$chatId}");
            return response('OK');
        }

        // Find active conversation
        $conversation = $this->getActiveConversation($chatId);
        if (!$conversation) {
            $this->sendTelegramMessage($chatId, "No active conversation. Type /create or /update.");
            return response('OK');
        }

        // Check flow type
        $flow = $conversation->data['flow_type'] ?? '';

        if ($flow === 'create') {
            return $this->handleCreateFlow($conversation, $callbackData, true);
        } elseif ($flow === 'update') {
            return $this->handleUpdateFlow($conversation, $callbackData, true);
        } else {
            // Otherwise unknown callback
            $this->sendTelegramMessage($chatId, "Callback not recognized: {$callbackData}");
        }
        return response('OK');
    }

    /* ================================================================
     * CREATE FLOW (same as you had, adding 'flow_type' => 'create'
     * ================================================================ */
    protected function startCreateBookingFlow($chatId)
    {
        $conversation = $this->getActiveConversation($chatId);

        if ($conversation && ($conversation->data['flow_type'] ?? '') === 'create' && $conversation->step > 0) {
            // Already in a create flow
            $keyboard = [
                [
                    ['text' => 'Resume', 'callback_data' => 'resume_create'],
                    ['text' => 'Cancel', 'callback_data' => 'cancel_create'],
                ],
            ];
            $payload = [
                'chat_id'      => $chatId,
                'text'         => "You already have an active create flow. Resume or cancel?",
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
            ];
            $this->sendRawTelegramRequest('sendMessage', $payload);
            return response('OK');
        }

        // Otherwise start new
        // If there's an old conversation with update flow, override it
        $conversation = TelegramConversation::updateOrCreate(
            ['chat_id' => $chatId],
            [
                'step'      => 1,
                'data'      => [
                    'flow_type' => 'create',
                ],
                'updated_at'=> now(),
            ]
        );

        // Step 1
        return $this->askForGuest($chatId);
    }

    protected function handleCreateFlow(TelegramConversation $conversation, string $input, bool $isCallback)
    {
        // ... same as your existing handleCreateFlow ...
        // just be sure to set `$conversation->data['flow_type'] = 'create'` at creation
        // (We skip the full code to keep this snippet shorter.)
        
        // Example snippet for step 1:
        if ($conversation->step === 1) {
            if ($isCallback && Str::startsWith($input, 'guest_id:')) {
                $guestId = (int) Str::after($input, 'guest_id:');
                $guest   = Guest::find($guestId);

                // Save guest_id and group_name
                $this->saveConversationData($conversation, [
                    'guest_id'   => $guestId,
                    'group_name' => $guest ? $guest->full_name : 'Unknown Group',
                ]);

                $conversation->step = 2;
                $conversation->updated_at = now();
                $conversation->save();

                return $this->askForStartDateTime($conversation);
            }
            $this->sendTelegramMessage($conversation->chat_id, "Please pick a guest by tapping a button.");
            return response('OK');
        }

        // ... etc. ...
    }

    protected function createBookingRecord(TelegramConversation $conversation)
    {
        $data = $conversation->data ?? [];

        try {
            $booking = Booking::create([
                'guest_id'                => $data['guest_id'] ?? null,
                'group_name'              => $data['group_name'] ?? '',
                'booking_start_date_time' => $data['booking_start_date_time'] ?? null,
                'tour_id'                 => $data['tour_id'] ?? null,
                'guide_id'                => $data['guide_id'] ?? null,
                'driver_id'               => $data['driver_id'] ?? null,
                'pickup_location'         => $data['pickup_location'] ?? '',
                'dropoff_location'        => $data['dropoff_location'] ?? '',
                'booking_status'          => $data['booking_status'] ?? 'pending',
                'booking_source'          => $data['booking_source'] ?? 'other',
                'special_requests'        => $data['special_requests'] ?? '',
            ]);

            $this->endConversation($conversation);

            $this->sendTelegramMessage($conversation->chat_id, 
                "Booking created successfully! ID: {$booking->id}"
            );
        } catch (\Exception $e) {
            Log::error("Error creating booking: " . $e->getMessage());
            $this->sendTelegramMessage($conversation->chat_id, 
                "Error creating booking. Please try again later."
            );
        }

        return response('OK');
    }

    /* ================================================================
     * UPDATE FLOW
     * ================================================================ */

    /**
     * Start the "update booking" flow.
     */
    protected function startUpdateBookingFlow($chatId)
    {
        // Check if there's an active conversation
        $conversation = $this->getActiveConversation($chatId);

        // If there's an existing "update" conversation in progress:
        if ($conversation && ($conversation->data['flow_type'] ?? '') === 'update' && $conversation->step > 0) {
            $keyboard = [
                [
                    ['text' => 'Resume', 'callback_data' => 'resume_update'],
                    ['text' => 'Cancel', 'callback_data' => 'cancel_update'],
                ],
            ];
            $payload = [
                'chat_id'      => $chatId,
                'text'         => "You already have an active update flow. Resume or cancel?",
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
            ];
            $this->sendRawTelegramRequest('sendMessage', $payload);
            return response('OK');
        }

        // Otherwise, create or reset the conversation
        $conversation = TelegramConversation::updateOrCreate(
            ['chat_id' => $chatId],
            [
                'step'      => 1,
                'data'      => [
                    'flow_type' => 'update',
                ],
                'updated_at'=> now(),
            ]
        );

        // Step 1: Ask for the booking ID
        $this->sendTelegramMessage($chatId, 
            "Which booking ID do you want to update? (Type the ID or pick from list)"
        );

        // (Optional) You could also display an inline keyboard of the last 5 bookings
        // Or do that in a separate method
        return response('OK');
    }

    /**
     * Handle steps for updating a booking.
     */
    protected function handleUpdateFlow(TelegramConversation $conversation, string $input, bool $isCallback)
    {
        $step = $conversation->step;

        // If user tapped "resume_update" or "cancel_update"
        if ($input === 'resume_update') {
            return $this->reAskCurrentStep($conversation);
        }
        if ($input === 'cancel_update') {
            $this->endConversation($conversation);
            $this->sendTelegramMessage($conversation->chat_id, "Update canceled.");
            return response('OK');
        }

        // Refresh updated_at to avoid timeouts
        $conversation->updated_at = now();
        $conversation->save();

        switch ($step) {
            case 1:
                // We expect them to type a booking ID or maybe choose inline
                if (!$isCallback) {
                    // Check if the typed input is a valid ID
                    $bookingId = (int) $input;
                    $booking   = Booking::find($bookingId);
                    if (!$booking) {
                        $this->sendTelegramMessage($conversation->chat_id,
                            "Booking ID {$bookingId} not found. Try again."
                        );
                        return response('OK');
                    }
                    // Store the booking ID in data
                    $this->saveConversationData($conversation, [
                        'booking_id' => $booking->id,
                    ]);
                    // Next step
                    $conversation->step = 2;
                    $conversation->save();

                    // Show a menu of fields to update
                    return $this->showUpdateFieldMenu($conversation, $booking);
                } else {
                    $this->sendTelegramMessage($conversation->chat_id,
                        "Please type the booking ID or pick from list."
                    );
                }
                break;

            case 2:
                // Step 2 means user is picking which field to update 
                // or typed "done" to finish.
                if ($isCallback) {
                    // e.g. "update_field:date", "update_field:guide", etc.
                    if (Str::startsWith($input, 'update_field:')) {
                        $field = Str::after($input, 'update_field:');
                        // Save which field we want to update
                        $this->saveConversationData($conversation, [
                            'update_field' => $field,
                        ]);
                        $conversation->step = 3;
                        $conversation->save();

                        // Ask user for the new value
                        return $this->askFieldValue($conversation, $field);
                    }
                    if ($input === 'finish_update') {
                        // Summarize changes, confirm
                        $conversation->step = 4;
                        $conversation->save();
                        return $this->askUpdateConfirmation($conversation);
                    }
                } else {
                    $this->sendTelegramMessage($conversation->chat_id,
                        "Please pick a field or tap Finish."
                    );
                }
                break;

            case 3:
                // Step 3 means user is providing a new value for that field
                if (!$isCallback) {
                    // They typed something (the new value)
                    $field = $conversation->data['update_field'] ?? null;
                    if (!$field) {
                        $this->sendTelegramMessage($conversation->chat_id,
                            "No field selected. Please pick from the menu."
                        );
                        return response('OK');
                    }

                    // Store it in conversation->data['changes'][field] = ...
                    $changes = $conversation->data['changes'] ?? [];
                    $newValue = $input;
                    
                    if ($field === 'booking_start_date_time') {
                        // parse date/time
                        try {
                            $dt = Carbon::parse($newValue);
                            $newValue = $dt->toDateTimeString();
                        } catch (\Exception $e) {
                            $this->sendTelegramMessage($conversation->chat_id,
                                "Invalid date/time. Try again (e.g. 2025-03-15 09:00)."
                            );
                            return response('OK');
                        }
                    }
                    
                    $changes[$field] = $newValue;
                    $this->saveConversationData($conversation, ['changes' => $changes]);

                    // Move back to step 2 (field selection)
                    $conversation->step = 2;
                    $conversation->save();

                    // Show the field menu again so user can pick another field or finish
                    $bookingId = $conversation->data['booking_id'] ?? null;
                    $booking   = Booking::find($bookingId);
                    return $this->showUpdateFieldMenu($conversation, $booking,
                        "Updated {$field} → {$newValue}. Pick another field or Finish."
                    );
                } else {
                    // user tapped something
                    $this->sendTelegramMessage($conversation->chat_id,
                        "Please type the new value for this field."
                    );
                }
                break;

            case 4:
                // Step 4 means we are confirming changes
                if ($input === 'confirm_update') {
                    return $this->applyBookingUpdates($conversation);
                }
                $this->sendTelegramMessage($conversation->chat_id,
                    "Please confirm or cancel."
                );
                break;
        }

        return response('OK');
    }

    /**
     * Show a menu of possible fields to update. (Step 2)
     */
    protected function showUpdateFieldMenu(TelegramConversation $conversation, Booking $booking, $headerText = null)
    {
        $headerText = $headerText ?: "Which field do you want to update for Booking ID {$booking->id}?";

        // We define possible fields
        $keyboard = [
            [
                ['text' => 'Date/Time',     'callback_data' => 'update_field:booking_start_date_time'],
                ['text' => 'Guest',         'callback_data' => 'update_field:guest_id'],
            ],
            [
                ['text' => 'Tour',          'callback_data' => 'update_field:tour_id'],
                ['text' => 'Guide',         'callback_data' => 'update_field:guide_id'],
            ],
            [
                ['text' => 'Driver',        'callback_data' => 'update_field:driver_id'],
                ['text' => 'Pickup',        'callback_data' => 'update_field:pickup_location'],
            ],
            [
                ['text' => 'Dropoff',       'callback_data' => 'update_field:dropoff_location'],
                ['text' => 'Status',        'callback_data' => 'update_field:booking_status'],
            ],
            [
                ['text' => 'Source',        'callback_data' => 'update_field:booking_source'],
                ['text' => 'Requests',      'callback_data' => 'update_field:special_requests'],
            ],
            [
                ['text' => 'Finish',        'callback_data' => 'finish_update'],
            ]
        ];

        $payload = [
            'chat_id'      => $conversation->chat_id,
            'text'         => $headerText,
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ];
        $this->sendRawTelegramRequest('sendMessage', $payload);
        return response('OK');
    }

    /**
     * Step 3: Ask for the new value of the chosen field.
     */
    protected function askFieldValue(TelegramConversation $conversation, $field)
    {
        switch ($field) {
            case 'guest_id':
                // Let them pick from a guest list or type an ID
                $guests = Guest::take(5)->get();
                $keyboard = [];
                foreach ($guests as $guest) {
                    $keyboard[] = [[
                        'text'          => $guest->full_name,
                        'callback_data' => "guestpick:{$guest->id}",
                    ]];
                }
                $keyboard[] = [[ 'text' => 'Cancel', 'callback_data' => 'cancel_update' ]];

                $payload = [
                    'chat_id'      => $conversation->chat_id,
                    'text'         => "Pick a new guest or type an ID if not listed.",
                    'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
                ];
                $this->sendRawTelegramRequest('sendMessage', $payload);
                return response('OK');

            case 'guide_id':
                // Similarly show a small set of guides
                $guides = Guide::take(5)->get();
                $keyboard = [[['text'=>'No guide','callback_data'=>'guidepick:0']]];
                foreach ($guides as $guide) {
                    $keyboard[] = [[
                        'text' => $guide->full_name,
                        'callback_data' => "guidepick:{$guide->id}"
                    ]];
                }
                $keyboard[] = [[ 'text' => 'Cancel', 'callback_data' => 'cancel_update' ]];

                $payload = [
                    'chat_id'      => $conversation->chat_id,
                    'text'         => "Pick a new guide or 'No guide':",
                    'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
                ];
                $this->sendRawTelegramRequest('sendMessage', $payload);
                return response('OK');

            case 'driver_id':
                // same pattern for drivers
                $drivers = Driver::take(5)->get();
                $keyboard = [[['text'=>'No driver','callback_data'=>'driverpick:0']]];
                foreach ($drivers as $driver) {
                    $keyboard[] = [[
                        'text' => $driver->full_name,
                        'callback_data' => "driverpick:{$driver->id}"
                    ]];
                }
                $keyboard[] = [[ 'text' => 'Cancel', 'callback_data' => 'cancel_update' ]];

                $payload = [
                    'chat_id'      => $conversation->chat_id,
                    'text'         => "Pick a new driver or 'No driver':",
                    'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
                ];
                $this->sendRawTelegramRequest('sendMessage', $payload);
                return response('OK');

            case 'booking_status':
                $keyboard = [
                    [['text'=>'Pending',     'callback_data'=>'statuspick:pending']],
                    [['text'=>'In Progress','callback_data'=>'statuspick:in_progress']],
                    [['text'=>'Finished',   'callback_data'=>'statuspick:finished']],
                    [['text'=>'Cancel',     'callback_data'=>'cancel_update']],
                ];
                $payload = [
                    'chat_id'      => $conversation->chat_id,
                    'text'         => "Pick new status:",
                    'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
                ];
                $this->sendRawTelegramRequest('sendMessage', $payload);
                return response('OK');

            case 'booking_source':
                $keyboard = [
                    [['text' => 'Viatour',    'callback_data' => 'sourcepick:viatour']],
                    [['text' => 'GetUrGuide','callback_data' => 'sourcepick:geturguide']],
                    [['text' => 'Website',   'callback_data' => 'sourcepick:website']],
                    [['text' => 'Walk In',   'callback_data' => 'sourcepick:walkin']],
                    [['text' => 'Phone',     'callback_data' => 'sourcepick:phone']],
                    [['text' => 'Email',     'callback_data' => 'sourcepick:email']],
                    [['text' => 'Other',     'callback_data' => 'sourcepick:other']],
                    [['text' => 'Cancel',    'callback_data' => 'cancel_update']],
                ];
                $payload = [
                    'chat_id'      => $conversation->chat_id,
                    'text'         => "Pick new booking source:",
                    'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
                ];
                $this->sendRawTelegramRequest('sendMessage', $payload);
                return response('OK');

            default:
                // booking_start_date_time, pickup_location, dropoff_location, special_requests => typed
                $this->sendTelegramMessage($conversation->chat_id,
                    "Type the new value for {$field}:"
                );
                return response('OK');
        }
    }

    /**
     * Summarize changes, ask for confirmation.
     */
    protected function askUpdateConfirmation(TelegramConversation $conversation)
    {
        $changes = $conversation->data['changes'] ?? [];
        if (empty($changes)) {
            $this->sendTelegramMessage($conversation->chat_id,
                "No changes to apply. Update canceled."
            );
            $this->endConversation($conversation);
            return response('OK');
        }

        $summary = "You made these changes:\n";
        foreach ($changes as $field => $val) {
            $summary .= "- {$field} => {$val}\n";
        }

        $keyboard = [
            [['text'=>'Confirm','callback_data'=>'confirm_update']],
            [['text'=>'Cancel','callback_data'=>'cancel_update']],
        ];

        $payload = [
            'chat_id'      => $conversation->chat_id,
            'text'         => $summary,
            'reply_markup' => json_encode(['inline_keyboard'=>$keyboard]),
        ];
        $this->sendRawTelegramRequest('sendMessage', $payload);
        return response('OK');
    }

    /**
     * Actually apply changes to the DB record.
     */
    protected function applyBookingUpdates(TelegramConversation $conversation)
    {
        $bookingId = $conversation->data['booking_id'] ?? null;
        $booking   = Booking::find($bookingId);
        if (!$booking) {
            $this->sendTelegramMessage($conversation->chat_id,
                "Booking not found. Possibly removed?"
            );
            $this->endConversation($conversation);
            return response('OK');
        }

        $changes = $conversation->data['changes'] ?? [];

        // If user changes 'guest_id', also handle group_name if you want
        if (isset($changes['guest_id'])) {
            $guest = Guest::find($changes['guest_id']);
            if ($guest) {
                $changes['group_name'] = $guest->full_name;
            }
        }

        try {
            $booking->update($changes);
            $this->sendTelegramMessage($conversation->chat_id,
                "Booking #{$bookingId} updated successfully."
            );
        } catch (\Exception $e) {
            Log::error("Error updating booking #{$bookingId}: " . $e->getMessage());
            $this->sendTelegramMessage($conversation->chat_id,
                "Error updating booking. Please try again."
            );
        }

        $this->endConversation($conversation);
        return response('OK');
    }

    /* ================================================================
     * Shared Helpers, e.g. getActiveConversation, reAskCurrentStep, etc.
     * ================================================================ */

    protected function getActiveConversation($chatId)
    {
        $conversation = TelegramConversation::where('chat_id', $chatId)->first();
        if (!$conversation) return null;

        // Check for timeout
        $lastUpdated = Carbon::parse($conversation->updated_at);
        if ($lastUpdated->diffInMinutes(now()) > $this->conversationTimeout) {
            $conversation->delete();
            return null;
        }
        return $conversation;
    }

    protected function reAskCurrentStep(TelegramConversation $conversation)
    {
        $flow = $conversation->data['flow_type'] ?? '';
        if ($flow === 'create') {
            // same as your create re-ask steps
        } elseif ($flow === 'update') {
            $step = $conversation->step;
            switch ($step) {
                case 1:
                    $this->sendTelegramMessage($conversation->chat_id,
                        "Which booking ID do you want to update?"
                    );
                    break;
                case 2:
                    $bookingId = $conversation->data['booking_id'] ?? null;
                    $booking   = Booking::find($bookingId);
                    if ($booking) {
                        return $this->showUpdateFieldMenu($conversation, $booking);
                    }
                    $this->sendTelegramMessage($conversation->chat_id,
                        "Booking not found. Type a booking ID."
                    );
                    break;
                case 3:
                    $field = $conversation->data['update_field'] ?? '';
                    return $this->askFieldValue($conversation, $field);
                case 4:
                    return $this->askUpdateConfirmation($conversation);
            }
        }
        return response('OK');
    }

    protected function saveConversationData(TelegramConversation $conversation, array $newData)
    {
        $data = $conversation->data ?: [];
        foreach ($newData as $k => $v) {
            $data[$k] = $v;
        }
        $conversation->data = $data;
        $conversation->save();
    }

    protected function endConversation(TelegramConversation $conversation)
    {
        $conversation->delete();
    }

    protected function sendTelegramMessage($chatId, $text)
    {
        if (!$this->botToken) {
            Log::error("No TELEGRAM_BOT_TOKEN found in config.");
            return false;
        }

        $url = "https://api.telegram.org/bot{$this->botToken}/sendMessage";
        try {
            $response = Http::post($url, [
                'chat_id' => $chatId,
                'text'    => $text,
            ]);
            if ($response->failed()) {
                Log::error("sendTelegramMessage failed: {$response->body()}");
                return false;
            }
        } catch (\Exception $e) {
            Log::error("sendTelegramMessage exception: " . $e->getMessage());
            return false;
        }
        return true;
    }

    protected function sendRawTelegramRequest($method, array $payload)
    {
        if (!$this->botToken) {
            Log::error("No TELEGRAM_BOT_TOKEN found in config.");
            return false;
        }
        $url = "https://api.telegram.org/bot{$this->botToken}/{$method}";

        try {
            $options = [
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/json\r\n",
                    'content' => json_encode($payload),
                ],
            ];
            $context = stream_context_create($options);
            $res     = file_get_contents($url, false, $context);

            if ($res === false) {
                Log::error("sendRawTelegramRequest failed to connect: $method");
                return false;
            }
        } catch (\Exception $e) {
            Log::error("sendRawTelegramRequest exception: " . $e->getMessage());
            return false;
        }
        return true;
    }

    protected function answerCallbackQuery($callbackId, $text = 'OK', $showAlert = false)
    {
        $payload = [
            'callback_query_id' => $callbackId,
            'text'              => $text,
            'show_alert'        => $showAlert,
        ];
        $this->sendRawTelegramRequest('answerCallbackQuery', $payload);
    }
}
