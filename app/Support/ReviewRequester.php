<?php

namespace App\Support;

use App\Models\Lead;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The day after a class, everyone who came gets one email from Alevtyna asking
 * for a Google review. Agreed with the studio in the group chat on 2026-11-09.
 *
 * Runs on the same hourly tick as the monthly class email
 * (POST /api/class-announcements/run from the Shuhai Marketing droplet):
 *
 *  1. record — every hour, the classes on today's schedule are written to
 *     review_classes. A class that left the site before its day (cancelled)
 *     is never recorded, so nobody is thanked for a class that didn't happen.
 *  2. send — from the day after a class (a multi-week course: after its last
 *     week), between 10:00 and 19:00 Calgary, its people get the email:
 *     paid online orders of that class, minus refunds, plus the offline
 *     bookings with an email that Alevtyna entered in the CMS.
 *
 * Guard rails: once per person, ever (broadcast_sent, campaign "reviews");
 * unsubscribes honoured; only classes that ended in the last LOOKBACK_DAYS
 * (a missed day catches up, a class from long ago is never brought up);
 * the daily cap is shared with the class email (Resend free plan).
 *
 * Everyone gets the same review link. Google does not allow asking only happy
 * clients for reviews ("review gating"), so anyone unhappy is invited to reply
 * to the email instead — the reply goes to the studio's inbox.
 */
class ReviewRequester
{
    public const CAMPAIGN = 'reviews';
    /** SHUHAI ART STUDIO, 1324 11 Ave SW — Google "write a review" link. Short link on the site: /review. */
    public const GOOGLE_REVIEW_URL = 'https://search.google.com/local/writereview?placeid=ChIJjY9psgEV1AYREzNCD6ceLL8';
    public const LOOKBACK_DAYS = 7;

    /**
     * @return array<string,mixed> summary for the cron log; never contains email addresses
     */
    public function run(bool $dry = false): array
    {
        if (!self::enabled()) {
            return ['action' => 'off', 'reason' => 'review_classes table missing (migration not run yet)'];
        }
        $now = now(ClassAnnouncer::TZ);
        $recorded = $dry ? [] : $this->record($now);

        $classes = $this->dueClasses($now);
        $people = $this->people($classes);
        $base = ['recorded_today' => $recorded, 'classes' => array_map(fn ($c) => $c->class_date . ' ' . $c->event_name, $classes)];

        if (!$people) {
            return ['action' => 'idle'] + $base;
        }
        if (!$this->inWindow($now)) {
            return ['action' => 'waiting', 'reason' => 'outside sending hours', 'to_send' => count($people)] + $base;
        }
        if ($dry) {
            return ['action' => 'would_send', 'to_send' => count($people)] + $base;
        }

        $capLeft = max(0, ClassAnnouncer::DAILY_CAP - ClassAnnouncer::sentToday($now));
        $batch = array_slice($people, 0, min(ClassAnnouncer::BATCH, $capLeft));
        if (!$batch) {
            return ['action' => 'waiting', 'reason' => 'daily cap reached', 'to_send' => count($people)] + $base;
        }

        $sender = Broadcast::sender();
        if (!$sender) {
            throw new \RuntimeException('Sending not configured (RESEND_API_KEY / BROADCAST_FROM)');
        }
        $payload = array_map(fn ($p) => $this->message($p, $sender), $batch);
        $resp = Broadcast::resend($payload, 'emails/batch');
        if (!$resp->successful()) {
            throw new \RuntimeException('Resend: ' . ($resp->json('message') ?: 'HTTP ' . $resp->status()));
        }
        Broadcast::recordSent(self::CAMPAIGN, array_map(fn ($p) => $p['email'], $batch));

        return ['action' => 'sent', 'sent_now' => count($batch), 'remaining' => count($people) - count($batch)] + $base;
    }

    /** One [TEST] email to $to, about the latest recorded class (or the next one on the site). No state is touched. */
    public function preview(string $to): array
    {
        $class = self::enabled() ? DB::table('review_classes')->orderByDesc('last_day')->first() : null;
        $event = $class ? self::eventOf($class) : null;
        if (!$event) {
            foreach (EventLinks::catalog()['events'] as $ev) {
                if (!in_array((string) ($ev['date'] ?? ''), ['', '%'], true)) {
                    $event = $ev;
                    break;
                }
            }
        }
        if (!$event) {
            return ['action' => 'preview', 'error' => 'no class to show in the preview'];
        }
        $sender = Broadcast::sender();
        if (!$sender) {
            return ['action' => 'preview', 'error' => 'Sending not configured (RESEND_API_KEY / BROADCAST_FROM)'];
        }
        $msg = $this->message(['email' => $to, 'name' => '', 'event' => $event], $sender, 'preview-reviews');
        $msg['subject'] = '[TEST] ' . $msg['subject'];
        $resp = Broadcast::resend($msg, 'emails');
        if (!$resp->successful()) {
            throw new \RuntimeException('Resend: ' . ($resp->json('message') ?: 'HTTP ' . $resp->status()));
        }
        return ['action' => 'preview', 'sent' => 1, 'id' => $resp->json('id'), 'class' => (string) ($event['eventName'] ?? '')];
    }

    public static function enabled(): bool
    {
        static $has = null;
        if ($has === null) {
            try {
                $has = Schema::hasTable('review_classes');
            } catch (\Throwable $e) {
                $has = false;
            }
        }
        return $has;
    }

    // ---------- 1. record the classes on today's schedule ----------

    /** @return array<int,string> what was noted, for the log */
    public function record(Carbon $now): array
    {
        $today = $now->format('Y-m-d');
        $noted = [];
        foreach (EventLinks::catalog()['events'] as $ev) {
            $span = self::span($ev);
            if (!$span || $today < $span[0] || $today > $span[1]) {
                continue;
            }
            $offline = [];
            foreach ((array) ($ev['offlineBookings'] ?? []) as $ob) {
                $email = strtolower(trim((string) ($ob['email'] ?? '')));
                if (str_contains($email, '@')) {
                    $offline[] = ['name' => trim((string) ($ob['name'] ?? '')), 'email' => $email];
                }
            }
            $key = ['event_id' => (int) $ev['id'], 'class_date' => $span[0]];
            $fields = [
                'event_name' => (string) ($ev['eventName'] ?? ''),
                'event_date' => (string) $ev['date'],
                'day'        => trim((string) ($ev['day'] ?? '')) ?: null,
                'last_day'   => $span[1],
                'offline'    => json_encode($offline),
                'updated_at' => now('UTC'),
            ];
            if (DB::table('review_classes')->where($key)->exists()) {
                DB::table('review_classes')->where($key)->update($fields);
            } else {
                DB::table('review_classes')->insert($key + $fields + ['created_at' => now('UTC')]);
            }
            $noted[] = $span[0] . ' ' . $fields['event_name'];
        }
        return $noted;
    }

    /**
     * First and last day of a class: [date, date] for a one-off class,
     * [start, start + (N-1) weeks] for "5-WEEK …" courses held on "Mondays".
     * Null when there is no date, or a recurring class doesn't say how many weeks.
     *
     * @return array{0:string,1:string}|null
     */
    public static function span(array $ev): ?array
    {
        $date = (string) ($ev['date'] ?? '');
        if ((int) ($ev['id'] ?? 0) <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        $start = Carbon::parse($date, ClassAnnouncer::TZ);
        $day = strtolower(trim((string) ($ev['day'] ?? ''), " ,"));
        $recurring = $day !== '' && str_ends_with($day, 's') && $day !== strtolower($start->format('l'));
        if (!$recurring) {
            return [$date, $date];
        }
        if (!preg_match('/(\d+)\s*-?\s*weeks?\b/i', (string) ($ev['eventName'] ?? ''), $m) || (int) $m[1] < 1 || (int) $m[1] > 26) {
            return null;
        }
        return [$date, $start->copy()->addWeeks((int) $m[1] - 1)->format('Y-m-d')];
    }

    // ---------- 2. who to ask ----------

    /** Recorded classes that ended yesterday … LOOKBACK_DAYS ago. */
    private function dueClasses(Carbon $now): array
    {
        return DB::table('review_classes')
            ->where('last_day', '<', $now->format('Y-m-d'))
            ->where('last_day', '>=', $now->copy()->subDays(self::LOOKBACK_DAYS)->format('Y-m-d'))
            ->orderBy('last_day')->get()->all();
    }

    /**
     * Everyone to ask now, one entry per address (their latest class), minus
     * those already asked and those who unsubscribed.
     *
     * @return array<int,array{email:string,name:string,event:array}>
     */
    private function people(array $classes): array
    {
        if (!$classes) {
            return [];
        }
        $asked = Broadcast::sentIn(self::CAMPAIGN);
        $unsub = Broadcast::unsubscribed();
        $refunded = null;

        $out = [];
        foreach ($classes as $class) {   // oldest first → a later class overwrites
            $event = self::eventOf($class);
            $lastDayEnd = Carbon::parse($class->last_day, ClassAnnouncer::TZ)->endOfDay()->setTimezone('UTC');

            $who = [];
            foreach (Lead::where('payment_status', 'paid')->where('event_id', (int) $class->event_id)->get() as $order) {
                if (!ClassSeats::orderIsFor($order, $event)) {
                    continue;
                }
                // The label has no year: an order placed after the class, or a
                // year before it, is for another class with the same id and date.
                $placed = $order->created_at ? Carbon::instance($order->created_at)->setTimezone('UTC') : null;
                if ($placed && ($placed->gt($lastDayEnd) || $placed->lt($lastDayEnd->copy()->subYear()))) {
                    continue;
                }
                if (!empty($order->stripe_payment_intent)) {
                    $refunded ??= Broadcast::refundedIntents();
                    if (isset($refunded[(string) $order->stripe_payment_intent])) {
                        continue;
                    }
                }
                $who[] = ['email' => (string) $order->email, 'name' => (string) $order->name];
            }
            foreach ((array) json_decode((string) $class->offline, true) as $ob) {
                $who[] = ['email' => (string) ($ob['email'] ?? ''), 'name' => (string) ($ob['name'] ?? '')];
            }

            foreach ($who as $p) {
                $email = strtolower(trim($p['email']));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || isset($asked[$email]) || isset($unsub[$email])) {
                    continue;
                }
                $out[$email] = ['email' => $email, 'name' => trim($p['name']), 'event' => $event];
            }
        }
        return array_values($out);
    }

    /** A recorded class in the shape the rest of the code uses for content.json events. */
    private static function eventOf(object $class): array
    {
        return [
            'id'        => (int) $class->event_id,
            'eventName' => (string) $class->event_name,
            'date'      => (string) $class->event_date,
            'day'       => (string) ($class->day ?? ''),
        ];
    }

    private function inWindow(Carbon $now): bool
    {
        return $now->hour >= ClassAnnouncer::SEND_FROM_HOUR && $now->hour < ClassAnnouncer::SEND_UNTIL_HOUR;
    }

    // ---------- the email ----------

    /** @param array{email:string,name:string,event:array} $p */
    public function message(array $p, array $sender, string $campaign = self::CAMPAIGN): array
    {
        $email = $p['email'];
        $first = trim((string) $p['name']) !== '' ? preg_split('/\s+/', trim((string) $p['name']))[0] : '';
        $first = $first !== '' ? mb_convert_case(mb_strtolower($first, 'UTF-8'), MB_CASE_TITLE, 'UTF-8') : '';
        $link = Broadcast::clickUrl($campaign, $email, EventLinks::CANONICAL_BASE . '/review');

        return [
            'from'     => $sender['from'],
            'to'       => [$email],
            'reply_to' => $sender['reply_to'],
            'subject'  => self::subject($first),
            'html'     => self::html($p['event'], $first, $email, $campaign, $link),
            'text'     => self::text($p['event'], $first, $email, $link),
            'headers'  => Broadcast::headers($email),
        ];
    }

    public static function subject(string $first): string
    {
        return 'Thank you for painting with me' . ($first !== '' ? ', ' . $first : '');
    }

    /** "Thank you for coming to Poppy Blooms Watercolor Class on Wednesday, November 11." */
    public static function thanks(array $event): string
    {
        $title = ClassAnnouncer::title((string) ($event['eventName'] ?? ''));
        $span = self::span($event);
        if ($span && $span[0] !== $span[1]) {
            return "Thank you for taking {$title} with me.";
        }
        $when = $span ? ' on ' . Carbon::parse($span[0])->format('l, F j') : '';
        return "Thank you for coming to {$title}{$when}.";
    }

    private const HOPE = 'I hope you enjoyed it and are happy with what you created.';
    private const ASK = 'If you have a minute, would you leave a short review on Google? It really helps other people in Calgary find the studio.';
    private const REPLY = 'And if something wasn’t quite right, just reply to this email. I read every message and would love to make the next class even better.';

    public static function html(array $event, string $first, string $email, string $campaign, string $link): string
    {
        $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        return '<div style="font-family:system-ui,-apple-system,Segoe UI,sans-serif;font-size:15px;line-height:1.6;color:#222;max-width:560px;margin:0 auto;">'
            . '<p>Hi ' . $h($first !== '' ? $first : 'there') . ',</p>'
            . '<p>' . $h(self::thanks($event)) . ' ' . $h(self::HOPE) . '</p>'
            . '<p>' . $h(self::ASK) . '</p>'
            . '<p style="margin:22px 0;"><a href="' . $h($link) . '" style="display:inline-block;background:#222;color:#fff;font-size:15px;padding:10px 20px;border-radius:5px;text-decoration:none;">Leave a review</a></p>'
            . '<p>' . $h(self::REPLY) . '</p>'
            . Broadcast::signoffHtml() . Broadcast::footerHtml($email)
            . '<img src="' . $h(Broadcast::openPixelUrl($campaign, $email)) . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;">'
            . '</div>';
    }

    public static function text(array $event, string $first, string $email, string $link): string
    {
        return 'Hi ' . ($first !== '' ? $first : 'there') . ",\n\n"
            . self::thanks($event) . ' ' . self::HOPE . "\n\n"
            . self::ASK . "\n" . $link . "\n\n"
            . self::REPLY . "\n\n— Alevtyna, Shuhai Art Studio\n\n" . Broadcast::footerText($email);
    }
}
