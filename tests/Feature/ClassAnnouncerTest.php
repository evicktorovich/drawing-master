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
 * Monthly class email: on the 1st, the month's classes that can still be booked.
 * What must hold: once a month, never outside sending hours, never twice to the
 * same person, never more than the daily cap, never to someone who unsubscribed,
 * never about a class nobody can book any more, never other months' classes.
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

    private const OCTOBER_CLASS = [
        'id' => 40, 'eventName' => 'INDUSTRIAL SUNSET ACRYLIC CLASS', 'date' => '2026-10-30', 'day' => 'Friday',
        'time' => '6:00 pm - 9:00 pm', 'price' => 84, 'img' => 'assets/img/uploaded/sunset.jpg',
    ];
    private const DECEMBER_CLASS = [
        'id' => 52, 'eventName' => 'WINTER LIGHTS ACRYLIC CLASS', 'date' => '2026-12-04', 'day' => 'Friday',
        'time' => '6:00 pm - 9:00 pm', 'price' => 84, 'img' => 'assets/img/uploaded/winter.jpg',
    ];

    public function test_on_the_first_at_ten_the_months_classes_go_out_in_one_email(): void
    {
        $this->catalog([self::OCTOBER_CLASS, self::AUTUMN_LIGHT, self::NATURES_MIRROR, self::DECEMBER_CLASS]);

        $this->at('2026-11-01 09:00');
        $this->assertSame('outside sending hours', $this->run_()['reason']);
        Http::assertNothingSent();

        $this->at('2026-11-01 10:00');
        $result = $this->run_();
        $this->assertSame('sent', $result['action']);
        $this->assertSame(2, $result['sent_now']);
        $this->assertSame(2, $result['classes']);
        Http::assertSentCount(1);                       // one batch call

        foreach ($this->sentMessages() as $m) {
            $this->assertSame('November classes at Shuhai Art Studio', $m['subject']);
            $this->assertStringContainsString('Autumn Light Acrylic Class', $m['html']);
            $this->assertStringContainsString('Nature’s Mirror Watercolor Class', $m['html']);
            $this->assertStringNotContainsString('Industrial Sunset', $m['html']);   // October
            $this->assertStringNotContainsString('Winter Lights', $m['html']);       // December
            $this->assertStringContainsString('what’s on in November', $m['html']);
        }
    }

    public function test_only_once_a_month(): void
    {
        $this->catalog([self::AUTUMN_LIGHT]);
        $this->at('2026-11-01 10:00');
        $this->run_();
        $this->at('2026-11-01 11:00');
        $this->assertSame('this month already sent', $this->run_()['reason']);
        $this->at('2026-11-02 10:00');
        $this->assertSame('this month already sent', $this->run_()['reason']);
        Http::assertSentCount(1);
    }

    public function test_nothing_goes_out_on_other_days(): void
    {
        $this->catalog([self::AUTUMN_LIGHT, self::NATURES_MIRROR]);
        $this->at('2026-11-02 10:00');
        $result = $this->run_();
        $this->assertSame('waiting for the 1st', $result['reason']);
        $this->assertStringStartsWith('2026-12-01T10:00', $result['next']);
        Http::assertNothingSent();
    }

    public function test_a_missed_first_can_be_sent_by_hand(): void
    {
        $this->catalog([self::AUTUMN_LIGHT, self::NATURES_MIRROR]);
        $this->at('2026-11-03 12:00');
        $result = (new ClassAnnouncer())->run(false, true);
        $this->assertSame('sent', $result['action']);
        $this->at('2026-11-03 13:00');
        $this->assertSame('this month already sent', (new ClassAnnouncer())->run(false, true)['reason']);
    }

    public function test_sold_out_and_same_day_classes_are_left_out(): void
    {
        $soldOut = array_merge(self::NATURES_MIRROR, ['maxAttendees' => 1]);
        $firstOfMonth = array_merge(self::AUTUMN_LIGHT, ['id' => 60, 'eventName' => 'MORNING IN THE MIST ACRYLIC CLASS', 'date' => '2026-11-01']);
        Lead::create(['name' => 'B', 'email' => 'b@example.com', 'phone' => '1', 'message' => '', 'event_id' => 50,
            'event_name' => self::NATURES_MIRROR['eventName'], 'event_date' => 'November 25', 'event_price' => 84,
            'seats' => 1, 'payment_status' => 'paid']);
        $this->catalog([$soldOut, $firstOfMonth, self::AUTUMN_LIGHT]);

        $this->at('2026-11-01 10:00');
        $this->assertSame(1, $this->run_()['classes']);
        $html = $this->sentMessages()[0]['html'];
        $this->assertStringContainsString('Autumn Light', $html);
        $this->assertStringNotContainsString('Nature’s Mirror', $html);
        $this->assertStringNotContainsString('Morning in the Mist', $html);
        $this->assertSame('November class at Shuhai Art Studio: Autumn Light Acrylic Class', $this->sentMessages()[0]['subject']);
    }

    public function test_a_month_without_bookable_classes_sends_nothing_and_says_so_once(): void
    {
        $this->catalog([self::OCTOBER_CLASS, self::DECEMBER_CLASS]);
        $this->at('2026-11-01 10:00');
        $this->assertSame('empty', $this->run_()['action']);
        $this->at('2026-11-01 11:00');
        $this->assertSame('this month already sent', $this->run_()['reason']);
        Http::assertNothingSent();
    }

    public function test_people_who_unsubscribed_get_nothing(): void
    {
        DB::table('broadcast_unsubscribes')->insert(['email' => 'bob@example.com', 'created_at' => now()]);
        $this->catalog([self::AUTUMN_LIGHT]);
        $this->at('2026-11-01 10:00');
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

        $this->at('2026-11-01 10:00');
        $first = $this->run_();
        $this->assertSame(ClassAnnouncer::DAILY_CAP, $first['sent_now']);
        $this->assertSame(5, $first['remaining']);

        $this->at('2026-11-01 11:00');
        $this->assertSame('daily cap reached', $this->run_()['reason']);

        $this->at('2026-11-02 10:00');
        $second = $this->run_();
        $this->assertSame(5, $second['sent_now']);
        $this->assertSame(0, $second['remaining']);

        $to = array_map(fn ($m) => $m['to'][0], $this->sentMessages());
        $this->assertCount(ClassAnnouncer::DAILY_CAP + 5, $to);
        $this->assertCount(count($to), array_unique($to));

        $this->at('2026-11-02 11:00');
        $this->assertSame('this month already sent', $this->run_()['reason']);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->catalog([self::AUTUMN_LIGHT]);
        $this->at('2026-11-01 10:00');
        $result = (new ClassAnnouncer())->run(true);
        $this->assertSame('would_start', $result['action']);
        $this->assertSame(0, DB::table('class_announcement_campaigns')->count());
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

    /** Pull the open-pixel and the first "Book your spot" link out of a sent email. */
    private function trackingLinks(array $message): array
    {
        preg_match('#<img src="(https://art-shuhai\.com/e/o\?[^"]+)"#', $message['html'], $pixel);
        preg_match('#<a href="(https://art-shuhai\.com/e/c\?[^"]+)"#', $message['html'], $click);
        return [html_entity_decode($pixel[1]), html_entity_decode($click[1])];
    }

    public function test_opens_clicks_and_bookings_after_the_email_show_up_in_stats(): void
    {
        $this->catalog([self::AUTUMN_LIGHT]);
        $this->at('2026-11-01 10:00');
        $this->run_();
        $ann = collect($this->sentMessages())->firstWhere('to', ['ann@example.com']);
        [$pixel, $click] = $this->trackingLinks($ann);

        $this->get($pixel)->assertOk()->assertHeader('Content-Type', 'image/gif');
        $this->get($click)->assertRedirect(
            'https://art-shuhai.com/event/autumn-light-acrylic-class?utm_source=newsletter&utm_medium=email&utm_campaign=classes-2026-11&utm_content=class-44'
        );
        $this->at('2026-11-01 15:00');
        Lead::create(['name' => 'Ann Lee', 'email' => 'ANN@example.com', 'phone' => '1', 'message' => '', 'event_id' => 44,
            'event_name' => self::AUTUMN_LIGHT['eventName'], 'event_date' => 'November 4', 'event_price' => 84,
            'seats' => 2, 'payment_status' => 'paid']);

        $stats = \App\Support\Broadcast::campaignStats('classes-2026-11');
        $this->assertSame(['sent' => 2, 'opened' => 1, 'clicked' => 1, 'booked' => 1, 'unsubscribed' => 0], $stats['summary']);
        $row = $stats['recipients'][0];
        $this->assertSame('ann@example.com', $row['email']);
        $this->assertSame(['/event/autumn-light-acrylic-class'], $row['clicked']);
        $this->assertSame(['AUTUMN LIGHT ACRYLIC CLASS · November 4 · ×2'], $row['booked']);
    }

    public function test_an_order_from_before_the_email_is_not_counted_as_booked_by_it(): void
    {
        $this->at('2026-10-20 12:00');
        Lead::create(['name' => 'Bob', 'email' => 'bob@example.com', 'phone' => '1', 'message' => '', 'event_id' => 44,
            'event_name' => self::AUTUMN_LIGHT['eventName'], 'event_date' => 'November 4', 'event_price' => 84,
            'seats' => 1, 'payment_status' => 'paid']);
        $this->catalog([self::AUTUMN_LIGHT]);
        $this->at('2026-11-01 10:00');
        $this->run_();

        $this->assertSame(0, \App\Support\Broadcast::campaignStats('classes-2026-11')['summary']['booked']);
    }

    public function test_a_forged_link_records_nothing_and_never_redirects_off_site(): void
    {
        $this->catalog([self::AUTUMN_LIGHT]);
        $this->at('2026-11-01 10:00');
        $this->run_();
        [$pixel, $click] = $this->trackingLinks($this->sentMessages()[0]);

        $this->get(preg_replace('/t=[^&]+/', 't=forged', $pixel))->assertOk();
        $offsite = preg_replace('/u=[^&]+/', 'u=' . urlencode('https://evil.example/phish'), $click);
        $this->get($offsite)->assertRedirect('https://art-shuhai.com/#events');

        $this->assertSame(0, DB::table('broadcast_events')->where('type', 'open')->count());   // forged pixel
        $this->assertSame(1, DB::table('broadcast_events')->where('type', 'click')->count());  // signed link, sent home instead
    }

    public function test_admin_email_stats_tab_lists_campaigns(): void
    {
        $this->catalog([self::AUTUMN_LIGHT]);
        $this->at('2026-11-01 10:00');
        $this->run_();

        $this->getJson('https://art-shuhai.com/cms/email-stats')->assertUnauthorized();
        $res = $this->withSession(['cms_admin' => true])->getJson('https://art-shuhai.com/cms/email-stats')->assertOk();
        $this->assertSame('classes-2026-11', $res->json('campaigns.0.campaign'));
        $this->assertSame('November class at Shuhai Art Studio: Autumn Light Acrylic Class', $res->json('stats.subject'));
        $this->assertSame(2, $res->json('stats.summary.sent'));
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
