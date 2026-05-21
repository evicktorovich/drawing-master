<?php

namespace App\Http\Controllers;

use App\Services\MetaCapi;
use Illuminate\Http\Request;
use GuzzleHttp\Client;

class ContactController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|max:255',
            'message' => 'required|string|max:1000',
        ]);

        $this->sendMessageToTelegram($validated);

        $eventId = MetaCapi::generateEventId();
        MetaCapi::sendEvent(
            eventName: 'Lead',
            eventId: $eventId,
            userData: MetaCapi::userContextFromRequest($request),
            eventSourceUrl: $request->header('referer') ?: 'https://art-shuhai.com/',
        );

        return response()->json([
            'message'  => 'Lead created successfully',
            'event_id' => $eventId,
        ], 201);
    }

    private function sendMessageToTelegram($data)
    {
        $client = new Client();
        $token = env('TELEGRAM_BOT_TOKEN'); // Получаем токен из .env
        $chatId = env('TELEGRAM_CHAT_ID'); // Получаем chat_id из .env

        $message = "New contact:\n";
        $message .= "Name: {$data['name']}\n";
        $message .= "Email: {$data['email']}\n";
        $message .= "Message: {$data['message']}\n";



        $response = $client->post("https://api.telegram.org/bot{$token}/sendMessage", [
            'form_params' => [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'Markdown',
            ],
        ]);

        return $response;
    }
}
