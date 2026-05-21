<?php
namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\MetaCapi;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Stripe\Stripe;

class PaymentController extends Controller
{
    public function createCheckoutSession(Request $request)
    {
        try {
            // Преобразование eventId в строку перед валидацией
            $request->merge([
                'eventId'        => (string) $request->eventId,
                'eventName'      => $request->eventName ?? '',
                'eventDate'      => $request->eventDate ?? '',
                'eventTime'      => $request->eventTime ?? '',
                'eventLocation'  => $request->eventLocation ?? '',
                'security_nonce' => $request->securityNonce ?? '',
            ]);

            $validated = $request->validate([
                'price'          => 'required|numeric',
                'eventId'        => 'required|integer',
                'eventName'      => 'required|string',
                'eventDate'      => 'required|string',
                'eventTime'      => 'required|string',
                'eventLocation'  => 'required|string',
                'name'           => 'required|string',
                'email'          => 'required|email',
                'phone'          => 'required|string',
                'message'        => 'nullable|string',
                'security_nonce' => 'nullable|string',
            ]);

            $stripeSecret = config('services.stripe.secret');
            if (empty($stripeSecret)) {
                throw new \Exception("Stripe secret key is not configured.");
            }

            Stripe::setApiKey($stripeSecret);

            $frontendUrl = config('app.frontend_url');
            if (empty($frontendUrl)) {
                throw new \Exception("Frontend URL (FRONTEND_URL) is not configured.");
            }

            // Подготовка метаданных
            $metadata = [
                'event_id'       => $validated['eventId'],
                'name'           => $validated['name'],
                'email'          => $validated['email'],
                'phone'          => $validated['phone'] ?? '',
                'message'        => $validated['message'] ?? '',
                'price'          => $validated['price'],
                'eventName'      => $validated['eventName'],
                'eventDate'      => $validated['eventDate'],
                'eventTime'      => $validated['eventTime'],
                'eventLocation'  => $validated['eventLocation'],
                'security_nonce' => $validated['security_nonce'],
            ];

            $metadata = array_map(function ($value) {
                return is_string($value) ? strip_tags($value) : $value;
            }, $metadata);

            Log::debug('Creating Stripe session with metadata:', $metadata);

            $lead = Lead::where('security_nonce', $validated['security_nonce'])
                ->where('event_id', $validated['eventId'])
                ->latest()
                ->first();

            if (! $lead) {
                $lead = Lead::create([
                    'name'           => $validated['name'],
                    'email'          => $validated['email'],
                    'phone'          => $validated['phone'] ?? null,
                    'message'        => $validated['message'] ?? null,
                    'event_id'       => $validated['eventId'],
                    'event_name'     => $validated['eventName'],
                    'event_date'     => $validated['eventDate'],
                    'event_time'     => $validated['eventTime'],
                    'event_location' => $validated['eventLocation'],
                    'event_price'    => $validated['price'],
                    'payment_status' => 'pending',
                    'security_nonce' => $validated['security_nonce'],
                ]);
            }

            // if ($lead->stripe_session_id) {
            //     return response()->json([
            //         'sessionId'   => $lead->stripe_session_id,
            //         'checkoutUrl' => "https://checkout.stripe.com/pay/{$lead->stripe_session_id}",
            //         'leadId'      => $lead->id,
            //         'debug'       => [
            //             'mode'     => 'reused',
            //             'metadata' => $metadata,
            //         ],
            //     ]);
            // }

            $session = Session::create([
                'payment_method_types' => ['card'],
                'line_items'           => [[
                    'price_data' => [
                        'currency'     => 'cad',
                        'product_data' => [
                            'name'        => $validated['eventName'] ?: 'Event #' . $validated['eventId'],
                            'description' => $this->buildEventDescription($validated),
                        ],
                        'unit_amount'  => (int) round($validated['price'] * 100),
                    ],
                    'quantity'   => 1,
                ]],
                'mode'                 => 'payment',
                'success_url'          => $frontendUrl . '/thank-you?session_id={CHECKOUT_SESSION_ID}&lead_id=' . $lead->id,
                'cancel_url'           => $frontendUrl . '/?canceled=true',
                'customer_email'       => $validated['email'],
                'metadata'             => array_merge($metadata, [
                    'lead_id' => $lead->id,
                ]),
            ]);

            $lead->update([
                'stripe_session_id' => $session->id,
            ]);

            if ($request->input('subscribe')) {
                // Subscription::updateOrCreate(
                //     ['email' => $validated['email']],
                //     ['name' => $validated['name'], 'subscribed' => true]
                // );
                $this->sendToGoogleSheet($lead);
            }

            $this->sendTelegramMessage($lead, $session, 'pending');

            Log::info('Stripe session created successfully', [
                'session_id' => $session->id,
                'lead_id'    => $lead->id,
                'event_id'   => $validated['eventId'],
                'amount'     => $validated['price'],
            ]);

            $capiEventId = MetaCapi::generateEventId();
            MetaCapi::sendEvent(
                eventName: 'InitiateCheckout',
                eventId: $capiEventId,
                userData: MetaCapi::userContextFromRequest($request),
                customData: [
                    'value'        => (float) $validated['price'],
                    'currency'     => 'CAD',
                    'content_name' => $validated['eventName'],
                    'content_ids'  => [(string) $validated['eventId']],
                    'content_type' => 'product',
                    'num_items'    => 1,
                ],
                eventSourceUrl: $request->header('referer') ?: 'https://art-shuhai.com/',
            );

            return response()->json([
                'sessionId'   => $session->id,
                'checkoutUrl' => $session->url,
                'leadId'      => $lead->id,
                'eventId'     => $capiEventId,
                'debug'       => [
                    'mode'     => $session->livemode ? 'live' : 'test',
                    'metadata' => $metadata,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Checkout session error', [
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);
            return response()->json([
                'error'   => $e instanceof \Stripe\Exception\ApiErrorException
                ? 'Payment processing error'
                : 'Application error',
                'details' => $e->getMessage(),
            ], $e instanceof \Stripe\Exception\ApiErrorException  ? 400 : 500);
        }
    }

/**
 * Формирует описание события для Stripe
 */
    private function buildEventDescription(array $data): string
    {
        $description = [];

        if (! empty($data['eventDate'])) {
            $date = $data['eventDate'];
            $description[] = "Date: " . $date;
        }

        if (! empty($data['eventTime'])) {
            $description[] = "Time: " . $data['eventTime'];
        }

        if (! empty($data['eventLocation'])) {
            $description[] = "Location: " . $data['eventLocation'];
        }

        return implode(' | ', $description);
    }

    protected function sendTelegramMessage(Lead $lead, $session, string $paymentStatus = 'pending')
    {
        if ($lead->telegram_sent) {
            Log::info('Telegram already sent, skipping.', ['lead_id' => $lead->id]);
            return;
        }

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

        try {
            $client->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'form_params' => [
                    'chat_id'    => $chatId,
                    'text'       => $message,
                    'parse_mode' => 'Markdown',
                ],
            ]);
            $lead->update(['telegram_sent' => true]);

            Log::info('Telegram message sent for order', [
                'lead_id' => $lead->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send Telegram message', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    private function sendToGoogleSheet(Lead $lead)
    {
        $client = new \Google_Client();
        $client->setApplicationName('Art Shuhai Google Sheets');
        $client->setScopes([\Google_Service_Sheets::SPREADSHEETS]);
        $client->setAuthConfig(storage_path('app/google/credentials.json'));
        $client->setAccessType('offline');

        $service = new \Google_Service_Sheets($client);

        $spreadsheetId = env('GOOGLE_SHEET_ID');
        $range         = 'C2:C'; // Range where emails are stored

        try {
            // Get existing emails from the sheet
            $existing = $service->spreadsheets_values->get($spreadsheetId, $range);
            $emails   = collect($existing->getValues())->flatten()->toArray();

            // Skip if email already exists
            if (in_array($lead->email, $emails)) {
                \Log::info('Google Sheet: email already exists, skipping.', ['email' => $lead->email]);
                return;
            }

            // Prepare values to insert
            $values = [
                [
                    now()->toDateTimeString(),
                    $lead->name,
                    $lead->email,
                ],
            ];

            $body = new \Google_Service_Sheets_ValueRange([
                'values' => $values,
            ]);

            $params = ['valueInputOption' => 'USER_ENTERED'];

            // Append the new row
            $service->spreadsheets_values->append($spreadsheetId, 'A1', $body, $params);

            \Log::info('Google Sheet: data successfully added.', ['lead_id' => $lead->id]);
        } catch (\Exception $e) {
            \Log::error('Google Sheet: failed to add data', [
                'error'   => $e->getMessage(),
                'lead_id' => $lead->id,
            ]);
        }
    }

}
