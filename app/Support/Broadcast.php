<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * What every email to the studio's clients shares: who is on the list, the
 * unsubscribe link, the footer, and sending through Resend.
 *
 * Used by the manual Broadcast tab in /admin and by the automatic new-class
 * announcements (ClassAnnouncer), so both respect the same unsubscribes and
 * the same "who counts as a client" rules.
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
