<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Monthly email to the studio's list: on the 1st of every month, the classes
 * of that month.
 *
 * The droplet cron calls run() once an hour (POST /api/class-announcements/run).
 * On the 1st, at the first call between SEND_FROM_HOUR and SEND_UNTIL_HOUR
 * Calgary time, ONE email goes out listing the month's one-off classes that
 * still have free spots (classes on the 1st itself are left out: an email the
 * morning of is too late). Jack's rule, 2026-10-02: "каждого 1-го числа месяца
 * на текущий месяц только ивенты".
 *
 * Guard rails: one campaign per month (unique key classes-YYYY-MM), at most
 * DAILY_CAP emails a day (the Resend account is on the free plan — 100 a day —
 * and shared with other senders), the rest of a long list goes the next day(s),
 * nobody gets the same campaign twice (broadcast_sent), unsubscribes honoured.
 */
class ClassAnnouncer
{
    public const TZ = 'America/Edmonton';
    public const SEND_FROM_HOUR = 10;
    public const SEND_UNTIL_HOUR = 19;
    public const DAILY_CAP = 80;
    public const BATCH = 100;           // Resend batch endpoint limit

    /**
     * $force starts this month's email today even if it isn't the 1st (manual
     * catch-up when the 1st was missed); sending hours and the once-a-month rule still apply.
     *
     * @return array<string,mixed> summary for the cron log; never contains email addresses
     */
    public function run(bool $dry = false, bool $force = false): array
    {
        $now = now(self::TZ);

        $open = DB::table('class_announcement_campaigns')->whereNull('completed_at')->orderBy('id')->first();
        if ($open) {
            return $this->continueCampaign($open, $now, $dry);
        }

        $key = self::campaignKey($now);
        if (DB::table('class_announcement_campaigns')->where('campaign', $key)->exists()) {
            return ['action' => 'idle', 'reason' => 'this month already sent', 'campaign' => $key];
        }
        if ($now->day !== 1 && !$force) {
            return ['action' => 'idle', 'reason' => 'waiting for the 1st',
                    'next' => $now->copy()->startOfMonth()->addMonthNoOverflow()->setTime(self::SEND_FROM_HOUR, 0)->toIso8601String()];
        }
        if (!$this->inWindow($now)) {
            return ['action' => 'waiting', 'reason' => 'outside sending hours', 'campaign' => $key];
        }

        $events = $this->monthEvents($now, $now);
        if (!$events) {
            if (!$dry) {
                // Recorded as done, so the hourly calls don't report "no classes" all day.
                DB::table('class_announcement_campaigns')->insert([
                    'campaign' => $key, 'event_ids' => '[]', 'subject' => null, 'sent' => 0,
                    'started_at' => now('UTC'), 'completed_at' => now('UTC'),
                ]);
            }
            return ['action' => 'empty', 'campaign' => $key, 'month' => $now->format('F'),
                    'reason' => 'no classes with free spots this month'];
        }

        $summary = ['campaign' => $key, 'classes' => count($events), 'list' => $this->describe($events)];
        if ($dry) {
            return ['action' => 'would_start', 'audience' => count(Broadcast::recipients(false, false)['recipients']),
                    'subject' => self::subject($events)] + $summary;
        }

        $id = DB::table('class_announcement_campaigns')->insertGetId([
            'campaign'   => $key,
            'event_ids'  => json_encode(array_map(fn ($e) => (int) $e['id'], $events)),
            'subject'    => self::subject($events),
            'started_at' => now('UTC'),
            'sent'       => 0,
        ]);
        $campaign = DB::table('class_announcement_campaigns')->where('id', $id)->first();
        return $this->sendBatch($campaign, $now) + ['started' => true, 'month' => $now->format('F')];
    }

    /**
     * One test email with what the next monthly email would contain. No state is touched.
     * Before this month's email has gone out on the 1st it previews this month, otherwise next month.
     */
    public function preview(string $to): array
    {
        $now = now(self::TZ);
        $month = ($now->day === 1 && !DB::table('class_announcement_campaigns')->where('campaign', self::campaignKey($now))->exists())
            ? $now->copy()
            : $now->copy()->startOfMonth()->addMonthNoOverflow();
        $events = $this->monthEvents($month, $now);
        if (!$events) {
            return ['action' => 'preview', 'error' => 'no classes with free spots in ' . $month->format('F Y')];
        }
        $sender = Broadcast::sender();
        if (!$sender) {
            return ['action' => 'preview', 'error' => 'Sending not configured (RESEND_API_KEY / BROADCAST_FROM)'];
        }
        $resp = Broadcast::resend($this->message($events, ['email' => $to, 'name' => ''], 'preview-' . $month->format('Y-m'), $sender, '[TEST] '), 'emails');
        if (!$resp->successful()) {
            throw new \RuntimeException('Resend: ' . ($resp->json('message') ?: 'HTTP ' . $resp->status()));
        }
        return ['action' => 'preview', 'sent' => 1, 'id' => $resp->json('id'), 'month' => $month->format('F Y'), 'list' => $this->describe($events)];
    }

    public static function campaignKey(Carbon $month): string
    {
        return 'classes-' . $month->format('Y-m');
    }

    // ---------- state ----------

    /**
     * One-off classes of $month that can still be booked as of $now: dated after
     * today (not today — too late for an email) and with at least one free spot.
     *
     * @return array<int,array> sorted by date
     */
    public function monthEvents(Carbon $month, Carbon $now): array
    {
        $ym = $month->format('Y-m');
        $out = [];
        foreach (EventLinks::catalog()['events'] as $ev) {
            if ((int) ($ev['id'] ?? 0) > 0 && substr((string) ($ev['date'] ?? ''), 0, 7) === $ym && $this->bookable($ev, $now)) {
                $out[] = $ev;
            }
        }
        usort($out, fn ($a, $b) => strcmp((string) $a['date'], (string) $b['date']));
        return $out;
    }

    private function bookable(array $ev, Carbon $now): bool
    {
        $date = (string) ($ev['date'] ?? '');
        if ($date === '' || $date === '%' || $date <= $now->format('Y-m-d')) {
            return false;
        }
        return ClassSeats::spotsLeft($ev) > 0;
    }

    /** The campaign's classes that are still bookable, in date order. */
    private function campaignEvents(object $campaign, Carbon $now): array
    {
        $ids = array_flip(array_map('intval', json_decode((string) $campaign->event_ids, true) ?: []));
        $out = [];
        foreach (EventLinks::catalog()['events'] as $ev) {
            if (isset($ids[(int) ($ev['id'] ?? 0)]) && $this->bookable($ev, $now)) {
                $out[] = $ev;
            }
        }
        usort($out, fn ($a, $b) => strcmp((string) $a['date'], (string) $b['date']));
        return $out;
    }

    private function continueCampaign(object $campaign, Carbon $now, bool $dry): array
    {
        if (!$this->campaignEvents($campaign, $now)) {
            // Every class in it has passed or sold out while we waited for the cap.
            if (!$dry) {
                DB::table('class_announcement_campaigns')->where('id', $campaign->id)->update(['completed_at' => now('UTC')]);
            }
            return ['action' => 'closed', 'campaign' => $campaign->campaign, 'reason' => 'its classes are no longer bookable', 'sent' => (int) $campaign->sent];
        }
        if (!$this->inWindow($now)) {
            return ['action' => 'waiting', 'campaign' => $campaign->campaign, 'reason' => 'outside sending hours', 'sent' => (int) $campaign->sent];
        }
        if ($dry) {
            return ['action' => 'would_continue', 'campaign' => $campaign->campaign, 'sent' => (int) $campaign->sent];
        }
        return $this->sendBatch($campaign, $now);
    }

    private function sendBatch(object $campaign, Carbon $now): array
    {
        $sender = Broadcast::sender();
        if (!$sender) {
            throw new \RuntimeException('Sending not configured (RESEND_API_KEY / BROADCAST_FROM)');
        }
        // Only what is still bookable goes into the email; a class that sold out since is dropped.
        $events = $this->campaignEvents($campaign, $now);

        $already = Broadcast::sentIn($campaign->campaign);
        $todo = array_values(array_filter(Broadcast::recipients(false, false)['recipients'],
            fn ($r) => !isset($already[strtolower($r['email'])])));

        $capLeft = max(0, self::DAILY_CAP - $this->sentToday($now));
        $batch = array_slice($todo, 0, min(self::BATCH, $capLeft));
        $base = ['campaign' => $campaign->campaign, 'classes' => count($events)];

        if ($batch) {
            $payload = array_map(fn ($r) => $this->message($events, $r, $campaign->campaign, $sender), $batch);
            $resp = Broadcast::resend($payload, 'emails/batch');
            if (!$resp->successful()) {
                throw new \RuntimeException('Resend: ' . ($resp->json('message') ?: 'HTTP ' . $resp->status()));
            }
            Broadcast::recordSent($campaign->campaign, array_map(fn ($r) => strtolower($r['email']), $batch));
        }

        $remaining = count($todo) - count($batch);
        $sent = (int) $campaign->sent + count($batch);
        $update = ['sent' => $sent];
        if ($remaining <= 0) {
            $update['completed_at'] = now('UTC');
        }
        DB::table('class_announcement_campaigns')->where('id', $campaign->id)->update($update);

        if (!$batch && $remaining > 0) {
            return ['action' => 'waiting', 'reason' => 'daily cap reached', 'remaining' => $remaining, 'sent' => $sent] + $base;
        }
        return ['action' => 'sent', 'sent_now' => count($batch), 'sent' => $sent, 'remaining' => max(0, $remaining)] + $base;
    }

    private function sentToday(Carbon $now): int
    {
        try {
            return DB::table('broadcast_sent')
                ->where('sent_at', '>=', $now->copy()->startOfDay()->setTimezone(config('app.timezone', 'UTC')))
                ->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function inWindow(Carbon $now): bool
    {
        return $now->hour >= self::SEND_FROM_HOUR && $now->hour < self::SEND_UNTIL_HOUR;
    }

    private function describe(array $events): array
    {
        return array_map(fn ($e) => ($e['date'] ?? '') . ' ' . ($e['eventName'] ?? ''), $events);
    }

    // ---------- the email ----------

    /** One Resend message for one recipient. */
    public function message(array $events, array $recipient, string $campaign, array $sender, string $subjectPrefix = ''): array
    {
        $email = (string) $recipient['email'];
        $name = trim((string) ($recipient['name'] ?? ''));
        $first = $name !== '' ? preg_split('/\s+/', $name)[0] : '';
        $first = $first !== '' ? mb_convert_case(mb_strtolower($first, 'UTF-8'), MB_CASE_TITLE, 'UTF-8') : 'there';

        return [
            'from'     => $sender['from'],
            'to'       => [$email],
            'reply_to' => $sender['reply_to'],
            'subject'  => $subjectPrefix . self::subject($events),
            'html'     => self::html($events, $first, $email, $campaign),
            'text'     => self::text($events, $first, $email, $campaign),
            'headers'  => Broadcast::headers($email),
        ];
    }

    /** "November classes at Shuhai Art Studio" (month of the first class listed). */
    public static function subject(array $events): string
    {
        $month = Carbon::parse($events[0]['date'])->format('F');
        return count($events) === 1
            ? $month . ' class at Shuhai Art Studio: ' . self::title((string) $events[0]['eventName'])
            : $month . ' classes at Shuhai Art Studio';
    }

    /** "AUTUMN LIGHT ACRYLIC CLASS" → "Autumn Light Acrylic Class": titles are typed in caps in the CMS. */
    public static function title(string $name): string
    {
        $t = mb_convert_case(mb_strtolower(trim($name), 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        // MB_CASE_TITLE capitalises after apostrophes: "Nature’S" → "Nature’s".
        $t = preg_replace_callback("/(['’])(\p{Lu})\b/u", fn ($m) => $m[1] . mb_strtolower($m[2], 'UTF-8'), $t) ?? $t;
        // Short words stay lower-case mid-title: "Morning in the Mist", "Playing with Watercolor".
        return preg_replace_callback('/(?<=\s)(A|An|And|At|By|For|In|Of|On|Or|The|To|With)(?=\s)/u',
            fn ($m) => strtolower($m[1]), $t) ?? $t;
    }

    /** "Wednesday, November 4 · 6:00 pm – 9:00 pm"; multi-week courses read "Starts Monday, November 2 · Mondays, …". */
    public static function when(array $e): string
    {
        $date = Carbon::parse((string) $e['date']);
        $day = trim((string) ($e['day'] ?? ''), " ,");
        $time = str_replace(' - ', ' – ', trim((string) ($e['time'] ?? '')));
        $recurring = $day !== '' && str_ends_with(strtolower($day), 's') && strtolower($day) !== strtolower($date->format('l'));
        $out = ($recurring ? 'Starts ' : '') . $date->format('l, F j');
        if ($recurring) {
            $out .= ' · ' . $day . ($time !== '' ? ', ' . $time : '');
        } elseif ($time !== '') {
            $out .= ' · ' . $time;
        }
        return $out;
    }

    public static function price($price): string
    {
        $p = (float) $price;
        return '$' . (fmod($p, 1.0) == 0.0 ? number_format($p, 0) : number_format($p, 2));
    }

    public static function link(array $e, string $campaign): string
    {
        $catalog = EventLinks::catalog()['events'];
        $slugs = EventLinks::slugsFor($catalog);
        $slug = null;
        foreach ($catalog as $i => $item) {
            if ((int) ($item['id'] ?? 0) === (int) ($e['id'] ?? -1)) {
                $slug = $slugs[$i];
                break;
            }
        }
        $url = $slug !== null ? EventLinks::url($slug) : EventLinks::CANONICAL_BASE . '/#events';
        return $url . '?' . http_build_query([
            'utm_source'   => 'newsletter',
            'utm_medium'   => 'email',
            'utm_campaign' => $campaign,
            'utm_content'  => 'class-' . (int) ($e['id'] ?? 0),
        ]);
    }

    private static function intro(array $events): string
    {
        $month = Carbon::parse($events[0]['date'])->format('F');
        return count($events) === 1
            ? "It’s Alevtyna from Shuhai Art Studio. Here’s what’s on in {$month}, and I’d love to see you there:"
            : "It’s Alevtyna from Shuhai Art Studio. Here’s what’s on in {$month}, and I’d love to see you at one of them:";
    }

    private const OUTRO = 'Groups are small, so if a date works for you, it’s best to book early. Hope to paint with you soon!';

    public static function html(array $events, string $first, string $email, string $campaign): string
    {
        $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rows = '';
        foreach ($events as $e) {
            $url = $h(self::link($e, $campaign));
            $img = $h(EventLinks::imageUrl($e['img'] ?? ''));
            $desc = trim((string) ($e['description'] ?? ''));
            $rows .= '<tr>'
                . '<td width="120" valign="top" style="padding:0 16px 22px 0;">'
                . '<a href="' . $url . '"><img src="' . $img . '" width="120" alt="' . $h(self::title((string) $e['eventName'])) . '" style="display:block;width:120px;height:auto;border-radius:6px;border:0;"></a></td>'
                . '<td valign="top" style="padding:0 0 22px 0;">'
                . '<div style="font-family:Georgia,serif;font-size:17px;line-height:1.3;color:#222;margin:0 0 4px;">' . $h(self::title((string) $e['eventName'])) . '</div>'
                . '<div style="font-size:14px;color:#555;margin:0 0 2px;">' . $h(self::when($e)) . '</div>'
                . '<div style="font-size:14px;color:#555;margin:0 0 6px;">' . $h(self::price($e['price'] ?? 0)) . '</div>'
                . ($desc !== '' ? '<div style="font-size:14px;color:#333;margin:0 0 8px;">' . $h($desc) . '</div>' : '')
                . '<a href="' . $url . '" style="display:inline-block;background:#222;color:#fff;font-size:14px;padding:8px 16px;border-radius:5px;text-decoration:none;">Book your spot</a>'
                . '</td></tr>';
        }
        return '<div style="font-family:system-ui,-apple-system,Segoe UI,sans-serif;font-size:15px;line-height:1.6;color:#222;max-width:560px;margin:0 auto;">'
            . '<p>Hi ' . $h($first) . ',</p>'
            . '<p>' . $h(self::intro($events)) . '</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin:18px 0 4px;">' . $rows . '</table>'
            . '<p>' . $h(self::OUTRO) . '</p>'
            . Broadcast::signoffHtml() . Broadcast::footerHtml($email) . '</div>';
    }

    public static function text(array $events, string $first, string $email, string $campaign): string
    {
        $out = "Hi {$first},\n\n" . self::intro($events) . "\n\n";
        foreach ($events as $e) {
            $out .= self::title((string) $e['eventName']) . "\n"
                . self::when($e) . "\n"
                . self::price($e['price'] ?? 0) . "\n"
                . (trim((string) ($e['description'] ?? '')) !== '' ? trim((string) $e['description']) . "\n" : '')
                . 'Book: ' . self::link($e, $campaign) . "\n\n";
        }
        return $out . self::OUTRO . "\n\n— Alevtyna, Shuhai Art Studio\n\n" . Broadcast::footerText($email);
    }
}
