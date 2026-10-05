<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * What every email to the studio's clients shares: who is on the list, the
 * unsubscribe link, the footer, sending through Resend, and opens / clicks /
 * bookings afterwards.
 *
 * Used by the manual Broadcast tab in /admin and by the monthly class email
 * (ClassAnnouncer), so both respect the same unsubscribes and the same "who
 * counts as a client" rules.
 */
class Broadcast
{
    /**
     * Past clients (Stripe payers + named offline bookings) and newsletter
     * subscribers, minus everyone who unsubscribed.
     *
     * $excludeUpcoming drops people already booked on an upcoming class — right
     * for the "come back" broadcast, wrong for a new-class announcement.
     *
     * @return array{recipients: array<int,array>, excluded: int, subscriber_error: ?string}
     */
    public static function recipients(bool $fresh = false, bool $excludeUpcoming = true): array
    {
        $sessions = self::stripePaidSessions($fresh);
        $cj = json_decode((string) @file_get_contents(public_path('content.json')), true) ?: [];
        $today = now()->format('Y-m-d');

        // Who's already booked on an upcoming event → exclude from re-engagement.
        $exclude = [];
        if ($excludeUpcoming) {
            $upcomingNames = [];
            foreach (($cj['events'] ?? []) as $ev) {
                $d = (string) ($ev['date'] ?? '');
                if ($d === '' || $d === '%' || $d < $today) {
                    continue;
                }
                $nm = (string) ($ev['eventName'] ?? '');
                if ($nm !== '') $upcomingNames[$nm] = true;
                foreach (($ev['offlineBookings'] ?? []) as $ob) {
                    $e = strtolower(trim((string) ($ob['email'] ?? '')));
                    if ($e !== '') $exclude[$e] = true;
                }
            }
            foreach ($sessions as $s) {
                if ($s['email'] !== '' && isset($upcomingNames[$s['eventName']])) {
                    $exclude[$s['email']] = true;
                }
            }
        }

        // Past clients: Stripe payers + named offline bookings.
        $byEmail = [];
        $touch = function (string $email, string $name, ?string $date, string $src) use (&$byEmail) {
            if ($email === '') return;
            if (!isset($byEmail[$email])) {
                $byEmail[$email] = ['email' => $email, 'name' => $name, 'sources' => [], 'last' => $date];
            }
            $byEmail[$email]['sources'][$src] = true;
            if ($name !== '') $byEmail[$email]['name'] = $name;
            if ($date && (empty($byEmail[$email]['last']) || $date > $byEmail[$email]['last'])) {
                $byEmail[$email]['last'] = $date;
            }
        };
        foreach ($sessions as $s) {
            $touch($s['email'], $s['name'], $s['date'], 'client');
        }
        foreach (($cj['events'] ?? []) as $ev) {
            foreach (($ev['offlineBookings'] ?? []) as $ob) {
                $touch(
                    strtolower(trim((string) ($ob['email'] ?? ''))),
                    trim((string) ($ob['name'] ?? '')),
                    (string) ($ob['date'] ?? '') ?: null,
                    'client'
                );
            }
        }

        // Newsletter subscribers (Google Sheet) — optional / defensive.
        $subscriberError = null;
        try {
            foreach (self::newsletterSubscribers($fresh) as $sub) {
                $touch(strtolower(trim((string) ($sub['email'] ?? ''))), (string) ($sub['name'] ?? ''), null, 'subscriber');
            }
        } catch (\Throwable $e) {
            $subscriberError = substr($e->getMessage(), 0, 200);
            Log::warning('Broadcast audience subscriber read failed', ['err' => $e->getMessage()]);
        }

        // Suppress anyone who unsubscribed (absent table → skip).
        foreach (self::unsubscribed() as $u => $_) {
            $exclude[$u] = true;
        }

        $recipients = [];
        foreach ($byEmail as $email => $r) {
            if (isset($exclude[$email]) || !str_contains($email, '@')) {
                continue;
            }
            $isClient = isset($r['sources']['client']);
            $isSub    = isset($r['sources']['subscriber']);
            $recipients[] = [
                'email'  => $r['email'],
                'name'   => $r['name'],
                'source' => ($isClient && $isSub) ? 'both' : ($isClient ? 'client' : 'subscriber'),
                'last'   => $r['last'],
            ];
        }
        usort($recipients, fn ($a, $b) => strcmp((string) $b['last'], (string) $a['last']));

        return ['recipients' => $recipients, 'excluded' => count($exclude), 'subscriber_error' => $subscriberError];
    }

    /** @return array<string,bool> lower-cased emails that unsubscribed */
    public static function unsubscribed(): array
    {
        $out = [];
        try {
            foreach (DB::table('broadcast_unsubscribes')->pluck('email') as $u) {
                $out[strtolower(trim((string) $u))] = true;
            }
        } catch (\Throwable $e) {
            // suppression table not present yet — no-op
        }
        return $out;
    }

    /** All paid Stripe Checkout sessions (minus refunds and $0 tests), cached 10 min. */
    public static function stripePaidSessions(bool $fresh = false): array
    {
        $key = 'cms:stripe_paid_sessions';
        if ($fresh) {
            Cache::store('file')->forget($key);
        }
        return Cache::store('file')->remember($key, 600, function () {
            $sk = config('services.stripe.secret');
            if (!$sk) {
                throw new \RuntimeException('STRIPE_SECRET not configured');
            }
            \Stripe\Stripe::setApiKey($sk);

            $refunded = [];
            foreach (\Stripe\Refund::all(['limit' => 100])->autoPagingIterator() as $r) {
                if (!empty($r->payment_intent)) {
                    $refunded[$r->payment_intent] = true;
                }
            }

            $out = [];
            foreach (\Stripe\Checkout\Session::all(['limit' => 100])->autoPagingIterator() as $s) {
                if (($s->payment_status ?? '') !== 'paid') continue;
                if ((int) ($s->amount_total ?? 0) <= 0) continue;
                if (!empty($s->payment_intent) && isset($refunded[$s->payment_intent])) continue;
                $out[] = [
                    'email'     => strtolower(trim((string) ($s->customer_email ?? ($s->customer_details->email ?? '')))),
                    'name'      => trim((string) ($s->customer_details->name ?? ($s->metadata->name ?? ''))),
                    'phone'     => (string) ($s->metadata->phone ?? ''),
                    'amount'    => (float) (($s->amount_total ?? 0) / 100),
                    'date'      => date('Y-m-d', (int) $s->created),
                    'eventName' => (string) ($s->metadata->eventName ?? ''),
                ];
            }
            return $out;
        });
    }

    /** Newsletter subscribers from the signup Google Sheet ([timestamp, name, email]), cached 10 min. */
    public static function newsletterSubscribers(bool $fresh = false): array
    {
        $sheetId  = env('GOOGLE_SHEET_ID');
        $credPath = storage_path('app/google/credentials.json');
        if (!$sheetId || !is_file($credPath)) {
            return [];
        }
        $key = 'cms:subscribers';
        if ($fresh) {
            Cache::store('file')->forget($key);
        }
        return Cache::store('file')->remember($key, 600, function () use ($sheetId, $credPath) {
            $client = new \Google_Client();
            $client->setScopes([\Google_Service_Sheets::SPREADSHEETS_READONLY]);
            $client->setAuthConfig($credPath);
            $service = new \Google_Service_Sheets($client);
            $rows = $service->spreadsheets_values->get($sheetId, 'A:C')->getValues() ?: [];
            $out = [];
            foreach ($rows as $row) {
                $name  = $row[1] ?? '';
                $email = $row[2] ?? '';
                if (!is_string($email) || strpos($email, '@') === false) {
                    continue; // header / blank / malformed
                }
                $out[] = ['name' => (string) $name, 'email' => (string) $email];
            }
            return $out;
        });
    }

    public static function unsubToken(string $email): string
    {
        return substr(hash_hmac('sha256', strtolower(trim($email)), (string) config('app.key')), 0, 32);
    }

    public static function unsubUrl(string $email): string
    {
        $base = rtrim((string) (config('app.frontend_url') ?: config('app.url') ?: 'https://art-shuhai.com'), '/');
        return $base . '/unsubscribe?e=' . urlencode($email) . '&t=' . self::unsubToken($email);
    }

    /** RFC 8058 one-click unsubscribe headers (Gmail/Yahoo bulk-sender rules). */
    public static function headers(string $email): array
    {
        return [
            'List-Unsubscribe'      => '<' . self::unsubUrl($email) . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];
    }

    public static function signoffHtml(): string
    {
        return '<p style="margin-top:18px;">&mdash; Alevtyna, Shuhai Art Studio</p>';
    }

    public static function footerHtml(string $email): string
    {
        $unsub = htmlspecialchars(self::unsubUrl($email), ENT_QUOTES, 'UTF-8');
        return '<hr style="border:none;border-top:1px solid #eee;margin:24px 0 16px;">'
            . '<p style="font-size:12px;color:#999;line-height:1.5;">1324 11 Ave SW #202, Calgary &middot; a.art.shuhai@gmail.com<br>'
            . 'You are receiving this because you took a class or subscribed at art-shuhai.com. '
            . '<a href="' . $unsub . '" style="color:#999;">Unsubscribe</a>.</p>';
    }

    public static function footerText(string $email): string
    {
        return "1324 11 Ave SW #202, Calgary · a.art.shuhai@gmail.com\n"
            . "You are receiving this because you took a class or subscribed at art-shuhai.com.\n"
            . 'Unsubscribe: ' . self::unsubUrl($email);
    }

    /** Sender settings from env; null when sending isn't configured. */
    public static function sender(): ?array
    {
        $apiKey = env('RESEND_API_KEY');
        $from   = env('BROADCAST_FROM');
        if (!$apiKey || !$from) {
            return null;
        }
        return ['key' => $apiKey, 'from' => $from, 'reply_to' => env('BROADCAST_REPLY_TO') ?: null];
    }

    /** POST to Resend: 'emails' (one) or 'emails/batch' (up to 100). */
    public static function resend(array $payload, string $path)
    {
        $sender = self::sender();
        return Http::withToken((string) ($sender['key'] ?? ''))->asJson()->timeout(30)
            ->post('https://api.resend.com/' . $path, $payload);
    }

    /** Emails already sent in this campaign (lower-cased). */
    public static function sentIn(string $campaign): array
    {
        $out = [];
        try {
            foreach (DB::table('broadcast_sent')->where('campaign', $campaign)->pluck('email') as $u) {
                $out[strtolower(trim((string) $u))] = true;
            }
        } catch (\Throwable $e) {
        }
        return $out;
    }

    // ---------- opens / clicks / bookings ----------
    //
    // Resend's own open/click tracking is a per-domain switch, and the domain is
    // shared with AdPilot's email, so tracking lives here instead: a 1×1 image for
    // opens and a redirect for clicks, both signed so nobody can forge them.
    // Opens are a floor and a ceiling at once: Apple Mail loads images for the
    // user (counts as opened even if not read), clients that block images never
    // report. Clicks and bookings are the numbers to trust.

    public static function trackToken(string $campaign, string $email): string
    {
        return substr(hash_hmac('sha256', $campaign . '|' . strtolower(trim($email)), (string) config('app.key')), 0, 24);
    }

    private static function trackQuery(string $campaign, string $email): array
    {
        return [
            'c' => $campaign,
            'r' => rtrim(strtr(base64_encode(strtolower(trim($email))), '+/', '-_'), '='),
            't' => self::trackToken($campaign, $email),
        ];
    }

    public static function openPixelUrl(string $campaign, string $email): string
    {
        return EventLinks::CANONICAL_BASE . '/e/o?' . http_build_query(self::trackQuery($campaign, $email));
    }

    public static function clickUrl(string $campaign, string $email, string $target): string
    {
        return EventLinks::CANONICAL_BASE . '/e/c?' . http_build_query(self::trackQuery($campaign, $email) + ['u' => $target]);
    }

    /** Email address from a tracking link, or null when the signature doesn't match. */
    public static function verifyTrack(string $campaign, string $r, string $t): ?string
    {
        $email = base64_decode(strtr($r, '-_', '+/'), true);
        if (!is_string($email) || !str_contains($email, '@') || $campaign === '') {
            return null;
        }
        return hash_equals(self::trackToken($campaign, $email), $t) ? $email : null;
    }

    public static function recordEvent(string $campaign, string $email, string $type, ?string $url = null): void
    {
        try {
            DB::table('broadcast_events')->insert([
                'campaign'   => substr($campaign, 0, 64),
                'email'      => strtolower($email),
                'type'       => $type,
                'url'        => $url !== null ? substr($url, 0, 500) : null,
                'created_at' => now('UTC'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('broadcast_events insert failed', ['err' => $e->getMessage()]);
        }
    }

    /** How long after clicking a link in the email an order still counts as booked through it. */
    public const BOOKED_WITHIN_DAYS = 14;

    /**
     * Who got a campaign, who opened, who clicked, who booked afterwards.
     * "Booked" = a paid order on the site from the same email within
     * BOOKED_WITHIN_DAYS after that person clicked a link in this email (any class).
     * Without the click it's not the email's doing: regulars book anyway (ads,
     * Instagram), and with 80 emails a day the last ones reach people days later —
     * counting from the campaign start credited the email with orders paid before
     * the person even got it.
     */
    public static function campaignStats(string $campaign): array
    {
        $sent = DB::table('broadcast_sent')->where('campaign', $campaign)->get(['email', 'sent_at']);
        if ($sent->isEmpty()) {
            return ['campaign' => $campaign, 'summary' => ['sent' => 0, 'opened' => 0, 'clicked' => 0, 'booked' => 0, 'unsubscribed' => 0], 'recipients' => []];
        }
        $start = $sent->min('sent_at');

        $rows = [];
        foreach ($sent as $s) {
            $e = strtolower(trim((string) $s->email));
            $rows[$e] = ['email' => $e, 'name' => '', 'sent_at' => (string) $s->sent_at, 'opened_at' => null, 'opens' => 0,
                         'clicks' => 0, 'clicked_at' => null, 'clicked' => [], 'booked' => [], 'unsubscribed' => false];
        }

        foreach (DB::table('broadcast_events')->where('campaign', $campaign)->orderBy('created_at')->get() as $ev) {
            $e = strtolower((string) $ev->email);
            if (!isset($rows[$e])) {
                continue; // preview / test address
            }
            if ($ev->type === 'open') {
                $rows[$e]['opens']++;
                $rows[$e]['opened_at'] = $rows[$e]['opened_at'] ?? (string) $ev->created_at;
            } elseif ($ev->type === 'click') {
                $rows[$e]['clicks']++;
                $rows[$e]['clicked_at'] = $rows[$e]['clicked_at'] ?? (string) $ev->created_at;
                // A click proves the email was opened even when images were blocked.
                $rows[$e]['opened_at'] = $rows[$e]['opened_at'] ?? (string) $ev->created_at;
                $path = (string) parse_url((string) $ev->url, PHP_URL_PATH);
                if ($path !== '' && !in_array($path, $rows[$e]['clicked'], true)) {
                    $rows[$e]['clicked'][] = $path;
                }
            }
        }

        $leads = DB::table('leads')->where('payment_status', 'paid')->where('created_at', '>=', $start)
            ->whereIn(DB::raw('lower(email)'), array_keys($rows))
            ->orderBy('created_at')->get(['email', 'name', 'event_name', 'event_date', 'seats', 'created_at']);
        foreach ($leads as $l) {
            $e = strtolower(trim((string) $l->email));
            $clickedAt = $rows[$e]['clicked_at'];
            if ($clickedAt === null) {
                continue;
            }
            $paidAt = \Carbon\Carbon::parse($l->created_at, 'UTC');
            $from = \Carbon\Carbon::parse($clickedAt, 'UTC');
            if ($paidAt->lt($from) || $paidAt->gt($from->copy()->addDays(self::BOOKED_WITHIN_DAYS))) {
                continue;
            }
            $rows[$e]['booked'][] = trim($l->event_name . ' · ' . $l->event_date . ' · ×' . max(1, (int) ($l->seats ?? 1)));
            if ($rows[$e]['name'] === '' && $l->name) {
                $rows[$e]['name'] = (string) $l->name;
            }
        }

        try {
            foreach (DB::table('broadcast_unsubscribes')->where('created_at', '>=', $start)->pluck('email') as $u) {
                $u = strtolower(trim((string) $u));
                if (isset($rows[$u])) $rows[$u]['unsubscribed'] = true;
            }
        } catch (\Throwable $e) {
        }

        // Names from the client list when the cache is warm (never forces a Stripe call).
        $cached = Cache::store('file')->get('cms:stripe_paid_sessions', []);
        foreach ((array) $cached as $s) {
            $e = strtolower((string) ($s['email'] ?? ''));
            if (isset($rows[$e]) && $rows[$e]['name'] === '' && !empty($s['name'])) {
                $rows[$e]['name'] = (string) $s['name'];
            }
        }

        // DB timestamps are UTC without a zone; hand the browser real ISO times.
        $iso = fn ($t) => $t ? \Carbon\Carbon::parse($t, 'UTC')->toIso8601String() : null;
        foreach ($rows as &$r) {
            $r['sent_at'] = $iso($r['sent_at']);
            $r['opened_at'] = $iso($r['opened_at']);
            $r['clicked_at'] = $iso($r['clicked_at']);
        }
        unset($r);

        $list = array_values($rows);
        usort($list, fn ($a, $b) => [count($b['booked']), $b['clicks'], (int) !empty($b['opened_at'])] <=> [count($a['booked']), $a['clicks'], (int) !empty($a['opened_at'])]);

        return [
            'campaign' => $campaign,
            'subject'  => DB::table('class_announcement_campaigns')->where('campaign', $campaign)->value('subject'),
            'summary'  => [
                'sent'         => count($list),
                'opened'       => count(array_filter($list, fn ($r) => !empty($r['opened_at']))),
                'clicked'      => count(array_filter($list, fn ($r) => $r['clicks'] > 0)),
                'booked'       => count(array_filter($list, fn ($r) => !empty($r['booked']))),
                'unsubscribed' => count(array_filter($list, fn ($r) => $r['unsubscribed'])),
            ],
            'recipients' => $list,
        ];
    }

    /** Campaigns that have sent anything, newest first. */
    public static function campaigns(): array
    {
        return DB::table('broadcast_sent')->select('campaign', DB::raw('count(*) as sent'), DB::raw('min(sent_at) as first_sent'))
            ->groupBy('campaign')->orderByDesc('first_sent')->get()
            ->map(fn ($r) => ['campaign' => $r->campaign, 'sent' => (int) $r->sent, 'first_sent' => (string) $r->first_sent,
                              'subject' => DB::table('class_announcement_campaigns')->where('campaign', $r->campaign)->value('subject')])
            ->all();
    }

    /** @param array<int,string> $emails */
    public static function recordSent(string $campaign, array $emails): void
    {
        try {
            $rows = array_map(fn ($e) => ['campaign' => $campaign, 'email' => $e, 'sent_at' => now()], $emails);
            DB::table('broadcast_sent')->insertOrIgnore($rows);
        } catch (\Throwable $e) {
            Log::warning('broadcast_sent insert failed', ['err' => $e->getMessage()]);
        }
    }
}
