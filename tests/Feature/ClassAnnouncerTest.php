<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Support\ClassAnnouncer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Automatic "new classes" emails. What must hold: one email per batch of new
 * classes, never outside sending hours, never twice to the same person, never
 * more than the daily cap, never to someone who unsubscribed, and never about a
 * class nobody can book any more.
 */
class ClassAnnouncerTest extends TestCase
{
    use RefreshDatabase;

    private const AUTUMN_LIGHT = [
        'id' => 44, 'eventName' => 'AUTUMN LIGHT ACRYLIC CLASS', 'date' => '2026-11-04', 'day' => 'Wednesday',
        'time' => '6:00 pm - 9:00 pm', 'price' => 84, 'img' => 'assets/img/uploaded/autumn.jpg',
        'description' => 'Beginner-friendly. All supplies provided + light snacks and beverages',
    ];
    private const NATURES_MIRROR = [
        'id' => 50, 'eventName' => 'NATURE’S MIRROR WATERCOLOR CLASS', 'date' => '2026-11-25', 'day' => 'Wednesday',
        'time' => '6:00 pm - 9:00 pm', 'price' => 84, 'img' => 'assets/img/uploaded/mirror.png',
    ];
    private const WATERCOLOR_COURSE = [
        'id' => 41, 'eventName' => '5-WEEK PAINTING COURSE: “PLAYING WITH WATERCOLOR”', 'date' => '2026-11-02', 'day' => 'Mondays',
        'time' => '11:00 am - 2:00 pm', 'price' => 367.5, 'img' => 'assets/img/uploaded/course.png',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['RESEND_API_KEY'] = $_SERVER['RESEND_API_KEY'] = 're_test';
        $_ENV['BROADCAST_FROM'] = $_SERVER['BROADCAST_FROM'] = 'Shuhai Art Studio <studio@example.com>';
        Http::fake(['api.resend.com/*' => Http::response(['data' => [['id' => 'x']], 'id' => 'x'], 200)]);
        $this->clients(['ann@example.com' => 'ANN LEE', 'bob@example.com' => 'Bob']);
    }

    protected function tearDown(): void
    {
        Cache::store('file')->flush();
        unset($_ENV['RESEND_API_KEY'], $_SERVER['RESEND_API_KEY'], $_ENV['BROADCAST_FROM'], $_SERVER['BROADCAST_FROM']);
        parent::tearDown();
    }

    private function catalog(array $events): void
    {
        $dir = sys_get_temp_dir() . '/class-announcer-' . getmypid();
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/content.json', json_encode(['events' => $events, 'regularClasses' => []]));
        $this->app->usePublicPath($dir);
    }

    /** Past clients, the way Stripe hands them over (cached so no Stripe call is made). */
    private function clients(array $people): void
    {
        $sessions = [];
        foreach ($people as $email => $name) {
            $sessions[] = ['email' => $email, 'name' => $name, 'phone' => '', 'amount' => 84.0,
                           'date' => '2026-06-01', 'eventName' => 'OLD CLASS'];
        }
        // forever: the tests travel days ahead, a 10-minute entry would expire and hit Stripe.
        Cache::store('file')->forever('cms:stripe_paid_sessions', $sessions);
    }

    private function at(string $calgaryTime): void
    {
        $this->travelTo(Carbon::parse($calgaryTime, 'America/Edmonton'));
    }

    private function run_(): array
    {
        return (new ClassAnnouncer())->run();
    }

    /** @return array<int,array> every message handed to Resend so far */
    private function sentMessages(): array
    {
        $out = [];
        foreach (Http::recorded() as [$request]) {
            /** @var HttpRequest $request */
            $data = $request->data();
            $out = array_merge($out, isset($data['to']) ? [$data] : array_values($data));
        }
        return $out;
    }

    public function test_new_classes_wait_until_editing_stops_then_go_out_in_one_email(): void
    {
        $this->catalog([self::AUTUMN_LIGHT, self::NATURES_MIRROR]);

        $this->at('2026-10-05 11:00');
        $this->assertSame('waiting', $this->run_()['action']);
        Http::assertNothingSent();

        $this->at('2026-10-05 12:05');
        $result = $this->run_();
        $this->assertSame('sent', $result['action']);
        $this->assertSame(2, $result['sent_now']);
        Http::assertSentCount(1);                       // one batch call

        $messages = $this->sentMessages();
        $this->assertCount(2, $messages);
        foreach ($messages as $m) {
            $this->assertStringContainsString('Autumn Light Acrylic Class', $m['html']);
            $this->assertStringContainsString('Nature’s Mirror Watercolor Class', $m['html']);
            $this->assertSame('New classes at Shuhai Art Studio for November', $m['subject']);
        }

        $this->at('2026-10-05 13:05');
        $this->assertSame('idle', $this->run_()['action']);
        Http::assertSentCount(1);
    }

    public function test_a_class_added_while_waiting_restarts_the_wait_and_joins_the_same_email(): void
    {
        $this->catalog([self::AUTUMN_LIGHT]);
        $this->at('2026-10-05 11:00');
        $this->run_();

        $this->catalog([self::AUTUMN_LIGHT, self::NATURES_MIRROR]);
        $this->at('2026-10-05 11:50');
        $this->assertSame('waiting', $this->run_()['action']);

        $this->at('2026-10-05 12:30');
        $this->assertSame('waiting', $this->run_()['action']);   // 40 min since the second class

        $this->at('2026-10-05 12:55');
        $this->assertSame('sent', $this->run_()['action']);
        $this->assertStringContainsString('Nature’s Mirror', $this->sentMessages()[0]['html']);
    }

    public function test_nothing_goes_out_in_the_evening_or_at_night(): void
    {
        $this->catalog([self::AUTUMN_LIGHT]);
        $this->at('2026-10-05 19:30');
        $this->run_();
        $this->at('2026-10-05 22:00');
        $this->assertSame('outside sending hours', $this->run_()['reason']);
        $this->at('2026-10-06 09:00');
        $this->assertSame('outside sending hours', $this->run_()['reason']);
        Http::assertNothingSent();

        $this->at('2026-10-06 10:00');
        $this->assertSame('sent', $this->run_()['action']);
    }

    public function test_sold_out_and_same_day_classes_are_never_announced(): void
    {
        $soldOut = array_merge(self::NATURES_MIRROR, ['maxAttendees' => 1]);
        $today = array_merge(self::AUTUMN_LIGHT, ['id' => 60, 'eventName' => 'TODAY CLASS', 'date' => '2026-10-05']);
        Lead::create(['name' => 'B', 'email' => 'b@example.com', 'phone' => '1', 'message' => '', 'event_id' => 50,
            'event_name' => self::NATURES_MIRROR['eventName'], 'event_date' => 'November 25', 'event_price' => 84,
            'seats' => 1, 'payment_status' => 'paid']);
        $this->catalog([$soldOut, $today]);

        $this->at('2026-10-05 11:00');
        $this->assertSame('idle', $this->run_()['action']);
        $this->at('2026-10-05 13:00');
        $this->assertSame('idle', $this->run_()['action']);
        Http::assertNothingSent();
    }

    public function test_people_who_unsubscribed_get_nothing(): void
    {
        DB::table('broadcast_unsubscribes')->insert(['email' => 'bob@example.com', 'created_at' => now()]);
        $this->catalog([self::AUTUMN_LIGHT]);
        $this->at('2026-10-05 11:00');
        $this->run_();
        $this->at('2026-10-05 12:01');
        $this->run_();

        $to = array_map(fn ($m) => $m['to'][0], $this->sentMessages());
        $this->assertSame(['ann@example.com'], $to);
    }

    public function test_a_long_list_is_spread_over_days_and_nobody_gets_it_twice(): void
    {
        $people = [];
        for ($i = 1; $i <= ClassAnnouncer::DAILY_CAP + 5; $i++) {
            $people["p{$i}@example.com"] = "Person {$i}";
        }
        $this->clients($people);
        $this->catalog([self::AUTUMN_LIGHT]);

        $this->at('2026-10-05 11:00');
        $this->run_();
        $this->at('2026-10-05 12:01');
        $first = $this->run_();
        $this->assertSame(ClassAnnouncer::DAILY_CAP, $first['sent_now']);
        $this->assertSame(5, $first['remaining']);

        $this->at('2026-10-05 13:01');
        $this->assertSame('daily cap reached', $this->run_()['reason']);

        $this->at('2026-10-06 10:01');
        $second = $this->run_();
        $this->assertSame(5, $second['sent_now']);
        $this->assertSame(0, $second['remaining']);

        $to = array_map(fn ($m) => $m['to'][0], $this->sentMessages());
        $this->assertCount(ClassAnnouncer::DAILY_CAP + 5, $to);
        $this->assertCount(count($to), array_unique($to));

        $this->at('2026-10-06 11:01');
        $this->assertSame('idle', $this->run_()['action']);
    }

    public function test_the_next_batch_of_classes_waits_three_days_after_the_last_email(): void
    {
        $this->catalog([self::AUTUMN_LIGHT]);
        $this->at('2026-10-05 11:00');
        $this->run_();
        $this->at('2026-10-05 12:01');
        $this->run_();

        $this->catalog([self::AUTUMN_LIGHT, self::NATURES_MIRROR]);
        $this->at('2026-10-06 11:00');
        $this->run_();
        $this->at('2026-10-06 12:30');
        $this->assertSame('previous announcement too recent', $this->run_()['reason']);

        $this->at('2026-10-08 12:30');
        $result = $this->run_();
        $this->assertSame('sent', $result['action']);
        $this->assertSame(1, $result['classes']);
        $last = array_slice($this->sentMessages(), -1)[0];
        $this->assertStringContainsString('Nature’s Mirror', $last['html']);
        $this->assertStringNotContainsString('Autumn Light', $last['html']);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->catalog([self::AUTUMN_LIGHT]);
        $this->at('2026-10-05 11:00');
        $result = (new ClassAnnouncer())->run(true);
        $this->assertSame('waiting', $result['action']);
        $this->assertSame(0, DB::table('class_announcements')->count());
        Http::assertNothingSent();
    }

    public function test_the_email_reads_like_the_site(): void
    {
        $this->catalog([self::WATERCOLOR_COURSE, self::NATURES_MIRROR]);
        $msg = (new ClassAnnouncer())->message(
            [self::WATERCOLOR_COURSE, self::NATURES_MIRROR],
            ['email' => 'ann@example.com', 'name' => 'ANN LEE'],
            'classes-20261005-1200',
            ['from' => 'Studio <s@example.com>', 'reply_to' => null]
        );

        $this->assertStringContainsString('Hi Ann,', $msg['html']);
        $this->assertStringContainsString('Starts Monday, November 2 · Mondays, 11:00 am – 2:00 pm', $msg['text']);
        $this->assertStringContainsString('Wednesday, November 25 · 6:00 pm – 9:00 pm', $msg['text']);
        $this->assertStringContainsString('$367.50', $msg['text']);
        $this->assertStringContainsString('$84', $msg['text']);
        $this->assertStringContainsString('5-Week Painting Course: “Playing with Watercolor”', $msg['text']);
        $this->assertStringContainsString(
            'https://art-shuhai.com/event/nature-s-mirror-watercolor-class?utm_source=newsletter&utm_medium=email&utm_campaign=classes-20261005-1200&utm_content=class-50',
            $msg['text']
        );
        $this->assertStringContainsString('/unsubscribe?e=ann%40example.com&amp;t=', $msg['html']);
        $this->assertArrayHasKey('List-Unsubscribe', $msg['headers']);
    }

    public function test_the_endpoint_turns_away_callers_without_the_token(): void
    {
        $this->postJson('https://art-shuhai.com/api/class-announcements/run')->assertForbidden();
        $this->postJson('https://art-shuhai.com/api/class-announcements/run', [], ['X-Announce-Token' => 'guess'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_admin_audience_still_leaves_out_people_booked_on_an_upcoming_class(): void
    {
        Cache::store('file')->forever('cms:stripe_paid_sessions', [
            ['email' => 'ann@example.com', 'name' => 'Ann', 'phone' => '', 'amount' => 84.0, 'date' => '2026-09-01', 'eventName' => self::AUTUMN_LIGHT['eventName']],
            ['email' => 'bob@example.com', 'name' => 'Bob', 'phone' => '', 'amount' => 84.0, 'date' => '2026-06-01', 'eventName' => 'OLD CLASS'],
        ]);
        $this->catalog([self::AUTUMN_LIGHT]);
        $this->at('2026-10-05 11:00');

        $res = $this->withSession(['cms_admin' => true])->getJson('https://art-shuhai.com/cms/audience')->assertOk();
        $this->assertSame(['bob@example.com'], array_column($res->json('recipients'), 'email'));
    }
}
