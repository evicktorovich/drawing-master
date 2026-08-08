<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Waitlist;
use App\Support\ClassSeats;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WaitlistController extends Controller
{
    public function join(Request $request)
    {
        $validated = $request->validate([
            'event_id'   => 'required|integer',
            'event_name' => 'nullable|string',
            'event_date' => 'nullable|string',
            'name'       => 'required|string|max:255',
            'email'      => 'required|email|max:255',
            'phone'      => 'required|string|max:64',
        ]);

        $waitlist = Waitlist::create([
            'event_id' => $validated['event_id'],
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'phone'    => $validated['phone'],
        ]);

        $this->sendTelegramMessage($waitlist, $validated['event_name'] ?? null, $validated['event_date'] ?? null);

        return response()->json(['ok' => true, 'id' => $waitlist->id]);
    }

    /**
     * Seats taken per class — what drives the SOLD OUT badge and the spot
     * picker on the site. The counting rules live in ClassSeats so checkout
     * enforces exactly what the site advertises.
     */
    public function availability()
    {
        return response()->json([
            'max'  => config('services.event_max_attendees', 12),
            'paid' => (object) ClassSeats::paidByClass(),
        ]);
    }

    protected function sendTelegramMessage(Waitlist $waitlist, ?string $eventName, ?string $eventDate): void
    {
        $token  = env('TELEGRAM_BOT_TOKEN');
        $chatId = env('TELEGRAM_CHAT_ID');

        if (! $token || ! $chatId) {
            Log::warning('Telegram credentials missing — skipping waitlist alert', [
                'waitlist_id' => $waitlist->id,
            ]);
            return;
        }

        $eventLabel = $eventName ?: ('Event #' . $waitlist->event_id);
        $dateLabel  = $eventDate ? "\n*Date:* {$eventDate}" : '';

        $message  = "📋 *Waitlist signup #{$waitlist->id}*\n\n";
        $message .= "*Event:* {$eventLabel}{$dateLabel}\n\n";
        $message .= "*Client:* {$waitlist->name}\n";
        $message .= "*Email:* {$waitlist->email}\n";
        $message .= "*Phone:* {$waitlist->phone}\n";

        try {
            $client = new Client();
            $client->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'form_params' => [
                    'chat_id'    => $chatId,
                    'text'       => $message,
                    'parse_mode' => 'Markdown',
                ],
            ]);

            $waitlist->update(['telegram_sent' => true]);
        } catch (\Throwable $e) {
            Log::error('Telegram waitlist alert failed', [
                'waitlist_id' => $waitlist->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
