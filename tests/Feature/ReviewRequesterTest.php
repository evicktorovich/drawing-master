<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Support\ReviewRequester;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The day after a class, its people are asked for a Google review.
 * What must hold: only people who came (paid, not refunded, this class and not
 * another one with a recycled id), only about a class that really ran, only
 * once per person, never outside sending hours, never after unsubscribing,
 * never about a class from long ago, and within the shared daily cap.
 */
class ReviewRequesterTest extends TestCase
{
    use RefreshDatabase;

    private const POPPY = [
        'id' => 46, 'eventName' => 'POPPY BLOOMS WATERCOLOR CLASS', 'date' => '2026-11-11', 'day' => 'Wednesday',
        'time' => '6:00 pm - 9:00 pm', 'price' => 84, 'img' => 'assets/img/uploaded/poppy.png',
    ];
    private const COURSE = [
        'id' => 41, 'eventName' => '5-WEEK PAINTING COURSE: “PLAYING WITH WATERCOLOR”', 'date' => '2026-11-02', 'day' => 'Mondays',
        'time' => '11:00 am - 2:00 pm', 'price' => 367.5, 'img' => 'assets/img/uploaded/course.png',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['RESEND_API_KEY'] = $_SERVER['RESEND_API_KEY'] = 're_test';
        $_ENV['BROADCAST_FROM'] = $_SERVER['BROADCAST_FROM'] = 'Shuhai Art Studio <studio@example.com>';
        $_ENV['BROADCAST_REPLY_TO'] = $_SERVER['BROADCAST_REPLY_TO'] = 'a.art.shuhai@gmail.com';
        Http::fake(['api.resend.com/*' => Http::response(['data' => [['id' => 'x']], 'id' => 'x'], 200)]);
        // No Stripe calls in tests: the refund list comes from the cache.
        Cache::store('file')->forever('cms:stripe_refunded_intents', ['pi_refunded' => true]);
        Cache::store('file')->forever('cms:stripe_paid_sessions', []);
    }

    protected function tearDown(): void
    {
        Cache::store('file')->flush();
        unset($_ENV['RESEND_API_KEY'], $_SERVER['RESEND_API_KEY'], $_ENV['BROADCAST_FROM'], $_SERVER['BROADCAST_FROM'],
              $_ENV['BROADCAST_REPLY_TO'], $_SERVER['BROADCAST_REPLY_TO']);
        parent::tearDown();
    }

    private function catalog(array $events): void
    {
        $dir = sys_get_temp_dir() . '/review-requester-' . getmypid();
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/content.json', json_encode(['events' => $events, 'regularClasses' => []]));
        $this->app->usePublicPath($dir);
    }

    private function at(string $calgaryTime): void
    {
        $this->travelTo(Carbon::parse($calgaryTime, 'America/Edmonton'));
    }

    private function run_(): array
    {
        return (new ReviewRequester())->run();
    }

    private function paid(string $email, string $name, array $event, string $dateLabel, array $extra = []): Lead
    {
        return Lead::create($extra + [
            'name' => $name, 'email' => $email, 'phone' => '1', 'message' => '', 'event_id' => $event['id'],
            'event_name' => $event['eventName'], 'event_date' => $dateLabel, 'event_price' => $event['price'],
            'payment_status' => 'paid',
        ]);
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

    private function sentTo(): array
    {
        $to = array_map(fn ($m) => $m['to'][0], $this->sentMessages());
        sort($to);
        return $to;
    }

    public function test_the_day_after_a_class_its_people_are_asked_once(): void
    {
        $this->catalog([self::POPPY]);
        $this->at('2026-11-05 12:00');
        $this->paid('Ann@Example.com', 'ANN LEE', self::POPPY, 'November 11 (Wednesday)');
        Lead::create(['name' => 'Bob', 'email' => 'bob@example.com', 'phone' => '1', 'message' => '', 'event_id' => 46,
            'event_name' => self::POPPY['eventName'], 'event_date' => 'November 11 (Wednesday)', 'payment_status' => 'pending']);

        $this->at('2026-11-11 08:00');
        $this->assertSame('idle', $this->run_()['action']);      // class day: noted, nobody asked yet
        $this->assertSame(1, DB::table('review_classes')->count());

        $this->at('2026-11-12 09:30');
        $this->assertSame('waiting', $this->run_()['action']);   // before 10:00
        Http::assertNothingSent();

        $this->at('2026-11-12 10:05');
        $res = $this->run_();
        $this->assertSame('sent', $res['action']);
        $this->assertSame(1, $res['sent_now']);
        $this->assertSame(['ann@example.com'], $this->sentTo());

        $this->at('2026-11-12 11:05');
        $this->assertSame('idle', $this->run_()['action']);
        Http::assertSentCount(1);
    }

    public function test_only_a_class_that_was_on_the_site_on_its_day_counts(): void
    {
        // Taken off the site before its date = cancelled: never asked about.
        $this->catalog([self::POPPY]);
        $this->at('2026-11-05 12:00');
        $this->paid('ann@example.com', 'Ann', self::POPPY, 'November 11 (Wednesday)');
        $this->at('2026-11-10 12:00');
        $this->run_();
        $this->catalog([]);
        $this->at('2026-11-11 12:00');
        $this->run_();
        $this->at('2026-11-12 10:00');
        $this->run_();
        Http::assertNothingSent();

        // Taken off the morning after it ran: still asked about.
        $this->catalog([self::POPPY]);
        $this->travelTo(Carbon::parse('2026-11-11 12:00', 'America/Edmonton'));
        $this->run_();
        $this->catalog([]);
        $this->at('2026-11-12 10:00');
        $this->run_();
        $this->assertSame(['ann@example.com'], $this->sentTo());
    }

    public function test_refunds_unsubscribes_and_orders_for_other_classes_are_left_out(): void
    {
        $this->catalog([self::POPPY + ['offlineBookings' => [
            ['name' => 'Fay Offline', 'email' => 'fay@example.com'],
            ['name' => 'No Email', 'email' => ''],
        ]]]);
        $this->at('2026-11-05 12:00');
        $this->paid('ann@example.com', 'Ann', self::POPPY, 'November 11 (Wednesday)', ['stripe_payment_intent' => 'pi_refunded']);
        $this->paid('cara@example.com', 'Cara', self::POPPY, 'November 11 (Wednesday)');
        $this->paid('eve@example.com', 'Eve', self::POPPY, '');                           // no date on the order: title decides
        $this->paid('gus@example.com', 'Gus', self::POPPY, 'November 11 (Wednesday)', ['stripe_payment_intent' => 'pi_ok']);
        DB::table('broadcast_unsubscribes')->insert(['email' => 'cara@example.com', 'created_at' => now()]);
        // Same id, earlier class that used it before (ids are recycled).
        $this->at('2026-08-20 12:00');
        $this->paid('dan@example.com', 'Dan', ['id' => 46, 'eventName' => 'SUMMER FIELDS', 'price' => 84], 'September 3 (Wednesday)');
        // Same id, same date label, but a year earlier.
        $this->at('2025-11-01 12:00');
        $this->paid('old@example.com', 'Old', self::POPPY, 'November 11 (Tuesday)');

        $this->at('2026-11-11 12:00');
        $this->run_();
        $this->at('2026-11-12 10:00');
        $this->run_();
        $this->assertSame(['eve@example.com', 'fay@example.com', 'gus@example.com'], $this->sentTo());
    }

    public function test_a_course_is_asked_about_after_its_last_week(): void
    {
        $this->catalog([self::COURSE]);
        $this->at('2026-10-20 12:00');
        $this->paid('ann@example.com', 'Ann', self::COURSE, 'November 2 (Mondays)');

        $this->at('2026-11-16 12:00');                 // mid-course (e.g. the day this went live)
        $this->run_();
        $this->assertSame('2026-11-30', DB::table('review_classes')->value('last_day'));

        $this->at('2026-11-24 10:00');
        $this->run_();
        Http::assertNothingSent();

        $this->at('2026-12-01 10:00');
        $this->run_();
        $this->assertSame(['ann@example.com'], $this->sentTo());
        $this->assertStringContainsString('Thank you for taking 5-Week Painting Course', $this->sentMessages()[0]['text']);
    }

    public function test_a_class_from_more_than_a_week_ago_is_not_brought_up(): void
    {
        $this->catalog([self::POPPY]);
        $this->at('2026-11-05 12:00');
        $this->paid('ann@example.com', 'Ann', self::POPPY, 'November 11 (Wednesday)');
        $this->at('2026-11-11 12:00');
        $this->run_();

        $this->at('2026-11-19 10:00');
        $this->assertSame('idle', $this->run_()['action']);
        Http::assertNothingSent();
    }

    public function test_the_daily_cap_is_shared_with_the_class_email(): void
    {
        $this->catalog([self::POPPY]);
        $this->at('2026-11-05 12:00');
        $this->paid('ann@example.com', 'Ann', self::POPPY, 'November 11 (Wednesday)');
        $this->at('2026-11-11 12:00');
        $this->run_();

        $this->at('2026-11-12 10:00');
        $rows = [];
        for ($i = 0; $i < 80; $i++) {
            $rows[] = ['campaign' => 'classes-2026-11', 'email' => "p{$i}@example.com", 'sent_at' => now()];
        }
        DB::table('broadcast_sent')->insert($rows);

        $res = $this->run_();
        $this->assertSame('waiting', $res['action']);
        $this->assertSame('daily cap reached', $res['reason']);
        Http::assertNothingSent();

        $this->at('2026-11-13 10:00');
        $this->run_();
        $this->assertSame(['ann@example.com'], $this->sentTo());
    }

    /** 100 past clients for the monthly class email (cached, so no Stripe call). */
    private function hundredClients(): void
    {
        $sessions = [];
        for ($i = 0; $i < 100; $i++) {
            $sessions[] = ['email' => "client{$i}@example.com", 'name' => "Client {$i}", 'phone' => '', 'amount' => 84.0,
                           'date' => '2026-06-01', 'eventName' => 'OLD CLASS'];
        }
        Cache::store('file')->forever('cms:stripe_paid_sessions', $sessions);
    }

    private const OCT28 = [
        'id' => 39, 'eventName' => 'HARVEST MOON ACRYLIC CLASS', 'date' => '2026-10-28', 'day' => 'Wednesday',
        'time' => '6:00 pm - 9:00 pm', 'price' => 84, 'img' => 'assets/img/uploaded/moon.jpg',
    ];

    private function threeAskedAboutOct28(): void
    {
        $this->catalog([self::OCT28, self::POPPY]);
        $this->at('2026-10-20 12:00');
        foreach (['ann', 'bob', 'cat'] as $n) {
            $this->paid("{$n}@example.com", ucfirst($n), self::OCT28, 'October 28 (Wednesday)');
        }
        $this->at('2026-10-28 12:00');
        (new ReviewRequester())->run();          // noted on its day
        $this->hundredClients();
    }

    public function test_on_a_class_email_day_reviews_go_first_and_the_class_email_takes_the_rest(): void
    {
        config(['services.class_announce_token_sha256' => hash('sha256', 'test-token')]);
        $this->threeAskedAboutOct28();

        $this->at('2026-11-01 10:07');
        $res = $this->postJson('https://art-shuhai.com/api/class-announcements/run', [], ['X-Announce-Token' => 'test-token'])->assertOk();
        $this->assertSame(3, $res->json('reviews.sent_now'));
        $this->assertSame(77, $res->json('sent_now'));            // 80 a day in all
        $this->assertSame(23, $res->json('remaining'));
        $this->assertSame(80, DB::table('broadcast_sent')->count());
    }

    public function test_the_class_email_leaves_room_for_review_emails_still_waiting(): void
    {
        $this->threeAskedAboutOct28();

        $this->at('2026-11-01 10:07');
        $res = (new \App\Support\ClassAnnouncer())->run();       // reviews not sent yet this hour
        $this->assertSame(77, $res['sent_now']);
        $this->assertSame(3, ReviewRequester::waitingCount());

        $this->assertSame(3, (new ReviewRequester())->run()['sent_now']);
        $this->assertSame(0, ReviewRequester::waitingCount());
    }

    public function test_the_email_and_its_link(): void
    {
        $this->catalog([self::POPPY]);
        $this->at('2026-11-05 12:00');
        $this->paid('ann@example.com', 'ANN LEE', self::POPPY, 'November 11 (Wednesday)');
        $this->at('2026-11-11 12:00');
        $this->run_();
        $this->at('2026-11-12 10:00');
        $this->run_();

        $m = $this->sentMessages()[0];
        $this->assertSame('Shuhai Art Studio <studio@example.com>', $m['from']);
        $this->assertSame('a.art.shuhai@gmail.com', $m['reply_to']);
        $this->assertSame('Thank you for painting with me, Ann', $m['subject']);
        $this->assertStringContainsString('Hi Ann,', $m['text']);
        $this->assertStringContainsString('Thank you for coming to Poppy Blooms Watercolor Class on Wednesday, November 11.', $m['text']);
        $this->assertStringContainsString('If you feel we didn’t quite make it a 5, please reply to this email. I love hearing feedback', $m['text']);
        $this->assertArrayHasKey('List-Unsubscribe', $m['headers']);

        preg_match('#<a href="(https://art-shuhai\.com/e/c\?[^"]+)"#', $m['html'], $click);
        $this->get(html_entity_decode($click[1]))->assertRedirect('https://art-shuhai.com/review');
        $this->get('https://art-shuhai.com/review')->assertRedirect(ReviewRequester::GOOGLE_REVIEW_URL);
        $this->assertSame(1, DB::table('broadcast_events')->where(['campaign' => 'reviews', 'type' => 'click'])->count());
    }

    public function test_dry_run_records_and_sends_nothing(): void
    {
        $this->catalog([self::POPPY]);
        $this->at('2026-11-11 12:00');
        $this->assertSame('idle', (new ReviewRequester())->run(true)['action']);
        $this->assertSame(0, DB::table('review_classes')->count());
        Http::assertNothingSent();
    }

    public function test_the_hourly_call_runs_both_and_a_review_failure_does_not_fail_the_class_email(): void
    {
        config(['services.class_announce_token_sha256' => hash('sha256', 'test-token')]);
        $this->catalog([self::POPPY]);
        $this->at('2026-11-11 12:00');

        $res = $this->postJson('https://art-shuhai.com/api/class-announcements/run', [], ['X-Announce-Token' => 'test-token'])->assertOk();
        $this->assertSame('idle', $res->json('action'));
        $this->assertSame('idle', $res->json('reviews.action'));
        $this->assertSame(['2026-11-11 POPPY BLOOMS WATERCOLOR CLASS'], $res->json('reviews.recorded_today'));

        // Resend down for the review email: the call still answers 200 with the class-email result.
        $this->paid('ann@example.com', 'Ann', self::POPPY, 'November 11 (Wednesday)');
        Http::swap(new \Illuminate\Http\Client\Factory());   // drop setUp's 200 stub — the first match wins
        Http::fake(['api.resend.com/*' => Http::response(['message' => 'boom'], 500)]);
        $this->at('2026-11-12 10:00');
        $res = $this->postJson('https://art-shuhai.com/api/class-announcements/run', [], ['X-Announce-Token' => 'test-token'])->assertOk();
        $this->assertSame('idle', $res->json('action'));
        $this->assertSame('error', $res->json('reviews.action'));
        $this->assertNull($res->json('error'));
        $this->assertSame(0, DB::table('broadcast_sent')->where('campaign', 'reviews')->count());
    }
}
