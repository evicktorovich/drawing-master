<?php
namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\MetaCapi;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $endpointSecret = config('services.stripe.webhook_secret');
        $payload        = $request->getContent();
        $sigHeader      = $request->header('Stripe-Signature');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $endpointSecret);
        } catch (\Exception $e) {
            Log::error('Stripe webhook signature verification failed', [
                'error'   => $e->getMessage(),
                'payload' => $payload,
            ]);
            return response('Webhook Error: ' . $e->getMessage(), 400);
        }

        if ($event->type === 'checkout.session.completed') {
            $session = $event->data->object;

            $lead = null;

            if (! empty($session->metadata->lead_id)) {
                $lead = Lead::find($session->metadata->lead_id);
            }

            if (! $lead && ! empty($session->metadata->security_nonce)) {
                $lead = Lead::where('security_nonce', $session->metadata->security_nonce)
                    ->orderBy('id', 'desc')
                    ->first();

            }

            if (! $lead) {
                Log::error('Lead not found for Stripe session', [
                    'session_id' => $session->id,
                    'metadata'   => $session->metadata,
                ]);
                return response('Lead not found', 404);
            }

            $lead->update([
                'payment_status'        => 'paid',
                'event_price'           => $session->amount_total / 100,
                'stripe_session_id'     => $session->id,
                'stripe_payment_intent' => $session->payment_intent,
            ]);

            $this->sendTelegramMessage($lead, $session, 'paid');

            // event_id = Stripe session id → matches the dataLayer 'purchase' push
            // fired on /thank-you, so Meta dedupes browser pixel + server event.
            $nameParts = preg_split('/\s+/', trim((string) $lead->name), 2);
            MetaCapi::sendEvent(
                eventName: 'Purchase',
                eventId: $session->id,
                userData: [
                    'email'      => $lead->email,
                    'phone'      => $lead->phone,
                    'first_name' => $nameParts[0] ?? null,
                    'last_name'  => $nameParts[1] ?? null,
                    'country'    => 'ca',
                ],
                customData: [
                    'value'        => (float) ($session->amount_total / 100),
                    'currency'     => strtoupper($session->currency ?: 'cad'),
                    'content_name' => $lead->event_name,
                    'content_ids'  => $lead->event_id ? [(string) $lead->event_id] : [],
                    'content_type' => 'product',
                    'order_id'     => $session->id,
                ],
                eventSourceUrl: config('app.frontend_url') . '/thank-you',
            );
        }

        return response('Webhook handled', 200);
    }

    protected function sendTelegramMessage(Lead $lead, $session, string $paymentStatus = 'pending')
    {
        $client = new Client();
        $token  = env('TELEGRAM_BOT_TOKEN');
        $chatId = env('TELEGRAM_CHAT_ID');

        $message = "🧾 *New Order #{$lead->id}*\n\n";
        $message .= "*Client:* {$lead->name}\n";
        $message .= "*Email:* {$lead->email}\n";
        $message .= "*Phone:* {$lead->phone}\n\n";
        $message .= "*Event:* {$lead->event_name}\n";
        $message .= "*Date:* {$lead->event_date}\n";
        $message .= "*Time:* {$lead->event_time}\n";
        $message .= "*Location:* {$lead->event_location}\n";
        $message .= "*Amount:* " . number_format($lead->event_price, 2) . " CAD\n\n";
        $message .= "*Payment status:* " . ucfirst($paymentStatus) . "\n\n";
        $message .= "*Message:*\n{$lead->message}\n\n";
        $message .= "*Payment ID:* `{$session->payment_intent}`";

        try {
            $client->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'form_params' => [
                    'chat_id'    => $chatId,
                    'text'       => $message,
                    'parse_mode' => 'Markdown',
                ],
            ]);

            Log::info('Telegram message sent for order', [
                'lead_id'        => $lead->id,
                'payment_status' => $paymentStatus,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send Telegram message', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}

// protected function sendPlainTextEmail(Lead $lead, $session)
// {
//     $emailText = "Новый заказ #{$lead->id}\n\n";
//     $emailText .= "Клиент: {$lead->name}\n";
//     $emailText .= "Email: {$lead->email}\n";
//     $emailText .= "Телефон: {$lead->phone}\n\n";

//     $emailText .= "Мероприятие: {$lead->event_name}\n";
//     $emailText .= "Дата: {$lead->event_date}\n";
//     $emailText .= "Время: {$lead->event_time}\n";
//     $emailText .= "Место: {$lead->event_location}\n";
//     $emailText .= "Сумма: " . number_format($lead->event_price, 2) . " CAD\n\n";

//     $emailText .= "Сообщение:\n{$lead->message}\n\n";
//     $emailText .= "ID платежа: {$session->payment_intent}\n";

//     try {
//         Mail::raw($emailText, function ($message) use ($lead) {
//             $message->to(config('mail.from.address'))
//                     ->subject("Новый заказ #{$lead->id}");
//         });

//         Log::info('Order confirmation email sent', [
//             'lead_id' => $lead->id,
//             'email' => $lead->email
//         ]);
//     } catch (\Exception $e) {
//         Log::error('Failed to send order confirmation email', [
//             'lead_id' => $lead->id,
//             'error' => $e->getMessage()
//         ]);
//     }
// }
