<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Emails the studio's list when new classes go up on the site.
 *
 * The droplet cron calls run() once an hour (POST /api/class-announcements/run).
 * Each call:
 *  1. notes every upcoming class with free spots it hasn't seen before;
 *  2. finishes a campaign that hit the daily sending cap, if one is open;
 *  3. otherwise, once no new class has appeared for GRACE_MINUTES (Alevtyna
 *     usually adds a month in one sitting and fixes typos right after), sends
 *     ONE email listing every class not yet announced.
 *
 * Guard rails: only between SEND_FROM_HOUR and SEND_UNTIL_HOUR Calgary time,
 * at most one new campaign per MIN_DAYS_BETWEEN days, at most DAILY_CAP emails
 * a day (the Resend account is on the free plan — 100 a day — and is shared
 * with other senders), nobody gets the same campaign twice (broadcast_sent),
 * unsubscribes are honoured. A class that is sold out, or happens today or
 * earlier, is never announced.
 */
class ClassAnnouncer
{
    public const TZ = 'America/Edmonton';
    public const GRACE_MINUTES = 60;
    public const MIN_DAYS_BETWEEN = 3;
    public const SEND_FROM_HOUR = 10;
    public const SEND_UNTIL_HOUR = 19;
    public const DAILY_CAP = 80;
    public const BATCH = 100;           // Resend batch endpoint limit

    /** @return array<string,mixed> summary for the cron log; never contains email addresses */
    public function run(bool $dry = false): array
    {
        $now = now(self::TZ);
        $classes = $this->announceable($now);
        if (!$dry) {
            $this->recordSightings($classes, $now);
        }

        $open = DB::table('class_announcement_campaigns')->whereNull('completed_at')->orderBy('id')->first();
        if ($open) {
            return $this->continueCampaign($open, $classes, $now, $dry);
        }

        $pending = $this->pending($classes, $now);
        if (!$pending) {
            return ['action' => 'idle', 'reason' => 'no new classes', 'upcoming' => count($classes)];
        }
        $summary = ['classes' => $this->describe($pending)];

        $newest = max(array_map(fn ($c) => Carbon::parse($c['first_seen_at'])->getTimestamp(), $pending));
        $readyAt = Carbon::createFromTimestamp($newest, self::TZ)->addMinutes(self::GRACE_MINUTES);
        if ($now->lt($readyAt)) {
            return ['action' => 'waiting', 'reason' => 'new classes still being added', 'ready_at' => $readyAt->toIso8601String()] + $summary;
        }
        if (!$this->inWindow($now)) {
            return ['action' => 'waiting', 'reason' => 'outside sending hours'] + $summary;
        }
        $last = DB::table('class_announcement_campaigns')->orderByDesc('started_at')->value('started_at');
        if ($last) {
            $next = Carbon::parse($last, 'UTC')->setTimezone(self::TZ)->addDays(self::MIN_DAYS_BETWEEN);
            if ($now->lt($next)) {
                return ['action' => 'waiting', 'reason' => 'previous announcement too recent', 'next_allowed' => $next->toIso8601String()] + $summary;
            }
        }

        $key = 'classes-' . $now->format('Ymd-Hi');
        $ids = array_map(fn ($c) => (int) $c['event']['id'], $pending);
        if ($dry) {
            $audience = count(Broadcast::recipients(false, false)['recipients']);
            return ['action' => 'would_start', 'campaign' => $key, 'audience' => $audience,
                    'subject' => self::subject(array_column($pending, 'event'))] + $summary;
        }

        $campaignId = DB::table('class_announcement_campaigns')->insertGetId([
            'campaign'   => $key,
            'event_ids'  => json_encode($ids),
            'subject'    => self::subject(array_column($pending, 'event')),
            'started_at' => now('UTC'),
            'sent'       => 0,
        ]);
        DB::table('class_announcements')->whereIn('event_id', $ids)->update(['campaign' => $key, 'announced_at' => now('UTC')]);

        $campaign = DB::table('class_announcement_campaigns')->where('id', $campaignId)->first();
        return $this->sendBatch($campaign, $classes, $now) + ['started' => true];
    }

    /** One test email with what the next announcement would contain. No state is touched. */
    public function preview(string $to): array
    {
        $now = now(self::TZ);
        $classes = $this->announceable($now);
        $pending = $this->pending($classes, $now);
        $events = $pending ? array_column($pending, 'event') : array_values($classes);
        if (!$events) {
            return ['action' => 'preview', 'error' => 'no upcoming classes with free spots'];
        }
        $sender = Broadcast::sender();
        if (!$sender) {
            return ['action' => 'preview', 'error' => 'Sending not configured (RESEND_API_KEY / BROADCAST_FROM)'];
        }
        $key = 'preview-' . $now->format('Ymd-Hi');
        $resp = Broadcast::resend($this->message($events, ['email' => $to, 'name' => ''], $key, $sender, '[TEST] '), 'emails');
        if (!$resp->successful()) {
            throw new \RuntimeException('Resend: ' . ($resp->json('message') ?: 'HTTP ' . $resp->status()));
        }
        return ['action' => 'preview', 'sent' => 1, 'id' => $resp->json('id'), 'classes' => $this->describe(array_map(fn ($e) => ['event' => $e], $events))];
    }

    // ---------- state ----------

    /**
     * Upcoming one-off classes with at least one free spot, keyed by id.
     * Today's classes are left out: an email the morning of is too late.
     *
     * @return array<int,array>
     */
    public function announceable(Carbon $now): array
    {
        $today = $now->format('Y-m-d');
        $out = [];
        foreach (EventLinks::catalog()['events'] as $ev) {
            $date = (string) ($ev['date'] ?? '');
            $id = (int) ($ev['id'] ?? 0);
            if ($id <= 0 || $date === '' || $date === '%' || $date <= $today) {
                continue;
            }
            if (ClassSeats::spotsLeft($ev) <= 0) {
                continue;
            }
            $out[$id] = $ev;
        }
        uasort($out, fn ($a, $b) => strcmp((string) $a['date'], (string) $b['date']));
        return $out;
    }

    private function recordSightings(array $classes, Carbon $now): void
    {
        if (!$classes) {
            return;
        }
        $known = DB::table('class_announcements')->whereIn('event_id', array_keys($classes))->pluck('event_id')->all();
        $known = array_flip(array_map('intval', $known));
        foreach ($classes as $id => $ev) {
            if (isset($known[$id])) {
                continue;
            }
            DB::table('class_announcements')->insertOrIgnore([
                'event_id'      => $id,
                'event_name'    => (string) ($ev['eventName'] ?? ''),
                'event_date'    => (string) ($ev['date'] ?? ''),
                'first_seen_at' => $now->copy()->setTimezone('UTC'),
            ]);
        }
    }

    /**
     * Classes on the site that no campaign has announced yet, with when they were first seen.
     * A class not recorded yet (dry runs, previews) counts as seen this minute.
     *
     * @return array<int,array{event: array, first_seen_at: string}>
     */
    private function pending(array $classes, Carbon $now): array
    {
        if (!$classes) {
            return [];
        }
        $rows = DB::table('class_announcements')->whereIn('event_id', array_keys($classes))->get()->keyBy('event_id');
        $out = [];
        foreach ($classes as $id => $ev) {
            $row = $rows[$id] ?? null;
            if ($row && $row->announced_at) {
                continue;
            }
            $seen = $row
                ? Carbon::parse($row->first_seen_at, 'UTC')->setTimezone(self::TZ)
                : $now->copy();
            $out[] = ['event' => $ev, 'first_seen_at' => $seen->toIso8601String()];
        }
        return $out;
    }

    private function continueCampaign(object $campaign, array $classes, Carbon $now, bool $dry): array
    {
        $ids = json_decode((string) $campaign->event_ids, true) ?: [];
        $live = array_values(array_filter(array_map(fn ($id) => $classes[$id] ?? null, $ids)));
        if (!$live) {
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
        return $this->sendBatch($campaign, $classes, $now);
    }

    private function sendBatch(object $campaign, array $classes, Carbon $now): array
    {
        $sender = Broadcast::sender();
        if (!$sender) {
            throw new \RuntimeException('Sending not configured (RESEND_API_KEY / BROADCAST_FROM)');
        }
        $ids = json_decode((string) $campaign->event_ids, true) ?: [];
        // Only what is still bookable goes into the email; a class that sold out since is dropped.
        $events = array_values(array_filter(array_map(fn ($id) => $classes[$id] ?? null, $ids)));

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

    private function describe(array $pending): array
    {
        return array_map(fn ($c) => ($c['event']['date'] ?? '') . ' ' . ($c['event']['eventName'] ?? ''), $pending);
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

    public static function subject(array $events): string
    {
        if (count($events) === 1) {
            $e = $events[0];
            return 'New class at Shuhai Art Studio: ' . self::title((string) $e['eventName'])
                . ', ' . Carbon::parse($e['date'])->format('M j');
        }
        $months = [];
        foreach ($events as $e) {
            $months[Carbon::parse($e['date'])->format('Y-m')] = Carbon::parse($e['date'])->format('F');
        }
        ksort($months);
        $months = array_values($months);
        $label = count($months) > 1
            ? implode(', ', array_slice($months, 0, -1)) . ' & ' . end($months)
            : $months[0];
        return 'New classes at Shuhai Art Studio for ' . $label;
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
        return count($events) === 1
            ? 'It’s Alevtyna from Shuhai Art Studio. A new class is open for booking, and I’d love to see you there:'
            : 'It’s Alevtyna from Shuhai Art Studio. New classes are open for booking, and I’d love to see you at one of them:';
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
