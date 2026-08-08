<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Support\ClassSeats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the SOLD OUT badge, the spot picker and the checkout capacity check
 * all read. Two things have to hold at once: an order books as many spots as
 * it paid for, and it only counts for the class holding its id today.
 */
class ClassSeatsTest extends TestCase
{
    use RefreshDatabase;

    private const SUN_CROWNED = [
        'id' => 17,
        'eventName' => 'SUN-CROWNED ACRYLIC CLASS',
        'date' => '2026-08-17',
        'day' => 'Monday',
    ];

    /** Point public_path() at a scratch copy so tests never touch the real content.json. */
    private function catalog(array $events): void
    {
        $dir = sys_get_temp_dir() . '/class-seats-' . getmypid();
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/content.json', json_encode([
            'events' => $events,
            'regularClasses' => [],
        ]));
        $this->app->usePublicPath($dir);
    }

    /** Passing seats => null writes the row without a seat count, the way orders were written before. */
    private function order(array $attributes = []): Lead
    {
        $attributes = array_merge([
            'name' => 'Buyer',
            'email' => 'buyer@example.com',
            'phone' => '4030000000',
            'message' => 'See you there',
            'event_id' => 17,
            'event_name' => self::SUN_CROWNED['eventName'],
            'event_date' => 'August 17 (Monday)',
            'event_price' => 84,
            'seats' => 1,
            'payment_status' => 'paid',
        ], $attributes);

        if ($attributes['seats'] === null) {
            unset($attributes['seats']);
        }

        return Lead::create($attributes);
    }

    public function test_an_order_takes_as_many_spots_as_it_paid_for(): void
    {
        $this->catalog([self::SUN_CROWNED]);
        $this->order(['seats' => 3]);

        $this->assertSame(3, ClassSeats::paidFor(self::SUN_CROWNED));
        $this->assertSame(9, ClassSeats::spotsLeft(self::SUN_CROWNED));
    }

    public function test_spots_add_up_across_orders_of_different_sizes(): void
    {
        $this->catalog([self::SUN_CROWNED]);
        $this->order(['seats' => 3]);
        $this->order(['seats' => 1, 'email' => 'b@example.com']);
        $this->order(['seats' => 2, 'email' => 'c@example.com']);

        $this->assertSame(6, ClassSeats::paidFor(self::SUN_CROWNED));
        $this->assertSame(6, ClassSeats::spotsLeft(self::SUN_CROWNED));
        $this->assertSame(['17' => 6], ClassSeats::paidByClass());
    }

    public function test_orders_placed_before_multi_spot_booking_count_as_one(): void
    {
        $this->catalog([self::SUN_CROWNED]);
        $this->order(['seats' => null]);

        $this->assertSame(1, ClassSeats::paidFor(self::SUN_CROWNED));
    }

    public function test_unpaid_orders_hold_no_spot(): void
    {
        $this->catalog([self::SUN_CROWNED]);
        $this->order(['seats' => 4, 'payment_status' => 'pending']);

        $this->assertSame(0, ClassSeats::paidFor(self::SUN_CROWNED));
    }

    public function test_offline_bookings_take_spots_too(): void
    {
        $event = self::SUN_CROWNED + ['bookedOffline' => 2];
        $this->catalog([$event]);
        $this->order(['seats' => 3]);

        $this->assertSame(5, ClassSeats::taken($event));
        $this->assertSame(7, ClassSeats::spotsLeft($event));
    }

    public function test_a_class_is_full_when_its_spots_are_all_taken(): void
    {
        $this->catalog([self::SUN_CROWNED]);
        $this->order(['seats' => 12]);

        $this->assertSame(0, ClassSeats::spotsLeft(self::SUN_CROWNED));
    }

    public function test_per_class_capacity_beats_the_studio_default(): void
    {
        $event = self::SUN_CROWNED + ['maxAttendees' => 6];
        $this->catalog([$event]);
        $this->order(['seats' => 2]);

        $this->assertSame(4, ClassSeats::spotsLeft($event));
    }

    /** The 2026-08-08 regression: a new class inherited a deleted one's sold spots. */
    public function test_spots_sold_by_the_class_that_used_this_id_before_do_not_count(): void
    {
        $this->catalog([self::SUN_CROWNED]);
        $this->order([
            'seats' => 3,
            'event_name' => 'PET PORTRAIT WATERCOLOR CLASS',
            'event_date' => 'July 31 (Friday)',
        ]);

        $this->assertSame(0, ClassSeats::paidFor(self::SUN_CROWNED));
        $this->assertSame(12, ClassSeats::spotsLeft(self::SUN_CROWNED));
    }

    public function test_renaming_a_class_keeps_its_orders(): void
    {
        $renamed = ['id' => 17, 'eventName' => 'SUN CROWNED ACRYLIC CLASS!', 'date' => '2026-08-17', 'day' => 'Monday'];
        $this->catalog([$renamed]);
        $this->order(['seats' => 2]);

        $this->assertSame(2, ClassSeats::paidFor($renamed));
    }

    public function test_moving_a_class_to_another_date_keeps_its_orders(): void
    {
        $moved = self::SUN_CROWNED;
        $moved['date'] = '2026-09-21';
        $this->catalog([$moved]);
        $this->order(['seats' => 2]);

        $this->assertSame(2, ClassSeats::paidFor($moved));
    }

    public function test_ids_the_site_no_longer_lists_are_left_alone(): void
    {
        $this->catalog([self::SUN_CROWNED]);
        $this->order(['seats' => 2, 'event_id' => 13, 'event_name' => 'SUNSET IN VENICE WATERCOLOR CLASS']);

        $this->assertSame(['13' => 2], ClassSeats::paidByClass());
    }
}
