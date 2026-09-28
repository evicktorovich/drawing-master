<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use Illuminate\Http\Request;

/**
 * /thank-you — where Stripe sends the buyer back after paying.
 *
 * The page fires the browser 'purchase' event. It used to carry only the
 * Stripe session id, so GA4 showed $0 revenue and Meta lost the amount
 * whenever it kept the browser copy over the server one. The order is
 * already in our database (stripe_session_id is saved when checkout opens),
 * so the amount comes from there — no call to Stripe on page load.
 */
class ThankYouController extends Controller
{
    public function show(Request $request)
    {
        return view('thank-you-page', [
            'purchase' => $this->purchase((string) $request->query('session_id', '')),
        ]);
    }

    private function purchase(string $sessionId): ?array
    {
        if (! preg_match('/^cs_[A-Za-z0-9_]{10,255}$/', $sessionId)) {
            return null;
        }

        $lead = Lead::where('stripe_session_id', $sessionId)->latest('id')->first();
        if (! $lead) {
            return null;
        }

        // Before the Stripe webhook lands event_price is the price of one spot;
        // the webhook overwrites it with the amount actually paid for the order.
        $seats = $lead->seatCount();
        $price = (float) $lead->event_price;
        $value = $lead->payment_status === 'paid' ? $price : $price * $seats;

        return [
            'value'        => round($value, 2),
            'content_name' => (string) $lead->event_name,
            'num_items'    => $seats,
        ];
    }
}
