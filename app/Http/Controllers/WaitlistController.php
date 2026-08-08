<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Waitlist;
use App\Support\EventLinks;
use Carbon\Carbon;
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
     * Seats taken per class — what drives the SOLD OUT badge on the site.
     *
     * Orders are stored under the class id, and class ids get recycled: delete
     * a class and a later one can be created with the same number, inheriting
     * every seat the old class sold (SUN-CROWNED showed SOLD OUT on 12 seats
     * that belonged to PET PORTRAIT). So an order only counts for the class
     * holding that id right now — see orderBelongsTo().
     */
    public function availability()
    {
        $max = config('services.event_max_attendees', 12);

        $current = [];
        foreach (EventLinks::catalog()['events'] as $event) {
            if (isset($event['id'])) {
                $current[(string) $event['id']] = $event;
            }
        }

        $paidCounts = [];
        $orders = Lead::where('payment_status', 'paid')->get(['event_id', 'event_name', 'event_date']);
        foreach ($orders as $order) {
            $id = (string) $order->event_id;
            $event = $current[$id] ?? null;
            if ($event !== null && ! $this->orderBelongsTo($order, $event)) {
                continue;
            }
            $paidCounts[$id] = ($paidCounts[$id] ?? 0) + 1;
        }

        return response()->json([
            'max'  => $max,
            'paid' => (object) $paidCounts,
        ]);
    }

    /**
     * Does this paid order belong to the class that holds its id today?
     *
     * A matching title or a matching date is enough on its own, so fixing a
     * typo in the title keeps the orders, and so does moving the class to
     * another date. Orders carrying neither field are kept — nothing proves
     * they are stale, and losing a real seat oversells the class.
     */
    protected function orderBelongsTo(Lead $order, array $event): bool
    {
        $orderName = $this->textKey((string) $order->event_name);
        $orderDate = $this->dateKey((string) $order->event_date);

        if ($orderName === '' && $orderDate === '') {
            return true;
        }
        if ($orderName !== '' && $orderName === $this->textKey((string) ($event['eventName'] ?? ''))) {
            return true;
        }

        return $orderDate !== '' && $orderDate === $this->dateKey($this->eventDateLabel($event));
    }

    /** The date string the sign-up form sends, e.g. "August 17 (Monday)". Mirrors OrderModal.getFormattedEventDateForPayload(). */
    protected function eventDateLabel(array $event): string
    {
        $day  = trim((string) ($event['day'] ?? ''), " \t\n\r\0\x0B,");
        $date = (string) ($event['date'] ?? '');

        if ($date === '' || $date === '%') {
            return $day !== '' ? 'Every ' . $day : 'Every day';
        }

        try {
            $label = Carbon::parse($date)->format('F j');
        } catch (\Throwable $e) {
            return $date;
        }

        return $day !== '' ? "{$label} ({$day})" : $label;
    }

    /** "August 17 (Monday)" and "August 17" compare equal — the weekday is decoration. */
    protected function dateKey(string $label): string
    {
        return $this->textKey((string) preg_replace('/\(.*?\)/u', ' ', $label));
    }

    /** Case, spacing and punctuation must not decide whether an order counts. */
    protected function textKey(string $text): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower(trim($text), 'UTF-8'));
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
