<?php

namespace Tests\Feature;

use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The browser 'purchase' event on /thank-you must carry what the order cost,
 * or GA4 books $0 revenue and Meta drops the amount when it keeps the browser copy.
 */
class ThankYouPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private const SESSION = 'cs_test_a1B2c3D4e5F6g7H8i9J0';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function order(array $attributes = []): Lead
    {
        return Lead::create(array_merge([
            'name' => 'Buyer',
            'email' => 'buyer@example.com',
            'phone' => '4030000000',
            'message' => 'See you there',
            'event_id' => 34,
            'event_name' => 'AUTUMN REIGNS ACRYLIC CLASS',
            'event_date' => 'October 9 (Friday)',
            'event_price' => 84,
            'seats' => 1,
            'payment_status' => 'pending',
            'stripe_session_id' => self::SESSION,
        ], $attributes));
    }

    private function visit(string $sessionId)
    {
        return $this->get('https://art-shuhai.com/thank-you?session_id=' . $sessionId . '&lead_id=1');
    }

    public function test_paid_order_reports_the_amount_stripe_charged(): void
    {
        // The webhook overwrites event_price with the order total.
        $this->order(['seats' => 2, 'event_price' => 168, 'payment_status' => 'paid']);

        $this->visit(self::SESSION)
            ->assertOk()
            ->assertSee('"value":168', false)
            ->assertSee('"num_items":2', false);
    }

    public function test_order_the_webhook_has_not_reached_yet_multiplies_the_spot_price(): void
    {
        $this->order(['seats' => 3, 'event_price' => 84]);

        $this->visit(self::SESSION)
            ->assertOk()
            ->assertSee('"value":252', false)
            ->assertSee('"num_items":3', false);
    }

    public function test_unknown_or_malformed_session_sends_the_event_without_an_amount(): void
    {
        $this->order();

        $this->visit('cs_test_nothing_like_this_here')->assertOk()->assertSee('var order = null;', false);
        $this->visit('not-a-session')->assertOk()->assertSee('var order = null;', false);
    }
}
