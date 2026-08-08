<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AdminCmsController extends Controller
{
    private const REPO_OWNER = 'evicktorovich';
    private const REPO_NAME = 'drawing-master';
    private const REPO_BRANCH = 'master';
    private const CONTENT_PATH = 'public/content.json';
    private const IMAGE_DIR = 'public/assets/img/uploaded';

    public function login(Request $request)
    {
        $password = (string) $request->input('password', '');
        $expected = (string) env('ADMIN_PASSWORD', '');
        if ($expected === '') {
            return response()->json(['error' => 'Admin password not configured on server'], 503);
        }
        if (!hash_equals($expected, $password)) {
            usleep(random_int(100000, 400000)); // throttle
            return response()->json(['error' => 'Invalid password'], 401);
        }
        $request->session()->put('cms_admin', true);
        $request->session()->regenerate();
        return response()->json(['ok' => true]);
    }

    public function logout(Request $request)
    {
        $request->session()->forget('cms_admin');
        return response()->json(['ok' => true]);
    }

    public function status(Request $request)
    {
        return response()->json(['authed' => $request->session()->get('cms_admin') === true]);
    }

    /**
     * Paid orders per event (read-only), reconciled against Stripe.
     *
     * The public site counts "seats taken" by event_id alone (see WaitlistController::availability),
     * but event_id slots get recycled across classes over time, so this groups by (event_id + name)
     * and cross-checks the live Stripe Checkout sessions to surface:
     *   - lost orders   (paid in Stripe, not recorded here → site shows too many free spots)
     *   - stray records (recorded here / counted by the public badge, but a different class or a $0 test)
     */
    public function orders(Request $request)
    {
        if (!$request->session()->get('cms_admin')) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }

        $SEP = "\x00";
        $max = (int) config('services.event_max_attendees', 12);

        // ---- DB side: paid leads, grouped by (event_id + event_name) ----
        $leads = \App\Models\Lead::where('payment_status', 'paid')
            ->orderBy('event_id')
            ->orderBy('id')
            ->get(['name', 'email', 'phone', 'event_id', 'event_name', 'event_price', 'event_date', 'created_at']);

        $byId = [];     // event_id => count  (mirrors /api/availability — what the public badge subtracts)
        $groups = [];   // "id\0name" => group
        foreach ($leads as $l) {
            $eid = (string) ($l->event_id ?? '');
            $byId[$eid] = ($byId[$eid] ?? 0) + 1;
            $key = $eid . $SEP . (string) $l->event_name;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'event_id'   => $eid,
                    'event_name' => (string) $l->event_name,
                    'event_date' => (string) $l->event_date,
                    'orders'     => [],
                ];
            }
            $groups[$key]['orders'][] = [
                'name'   => (string) $l->name,
                'email'  => (string) $l->email,
                'phone'  => (string) $l->phone,
                'amount' => $l->event_price !== null ? (float) $l->event_price : null,
                'date'   => optional($l->created_at)->toDateString(),
            ];
        }

        // ---- Waitlist counts per event (bonus context) ----
        $waitlistById = [];
        try {
            $waitlistById = \App\Models\Waitlist::selectRaw('event_id, COUNT(*) as c')
                ->groupBy('event_id')->pluck('c', 'event_id')->toArray();
        } catch (\Throwable $e) {
            // table optional in some environments
        }

        // ---- Stripe side: recent paid sessions (real), minus refunds and $0 tests ----
        $stripe = ['ok' => false, 'error' => null, 'byKey' => [], 'byId' => []];
        $windowDays = 220;
        try {
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

            $cutoff = now()->subDays($windowDays)->timestamp;
            foreach (\Stripe\Checkout\Session::all(['limit' => 100, 'created' => ['gte' => $cutoff]])->autoPagingIterator() as $s) {
                if (($s->payment_status ?? '') !== 'paid') {
                    continue;
                }
                if ((int) ($s->amount_total ?? 0) <= 0) {
                    continue; // skip $0 test purchases
                }
                if (!empty($s->payment_intent) && isset($refunded[$s->payment_intent])) {
                    continue; // skip refunded
                }
                $eid   = (string) ($s->metadata->event_id ?? '');
                $enm   = (string) ($s->metadata->eventName ?? '');
                $email = (string) ($s->customer_email ?? ($s->customer_details->email ?? ''));
                $key   = $eid . $SEP . $enm;
                if (!isset($stripe['byKey'][$key])) {
                    $stripe['byKey'][$key] = ['count' => 0, 'emails' => []];
                }
                $stripe['byKey'][$key]['count']++;
                if ($email !== '') {
                    $stripe['byKey'][$key]['emails'][] = $email;
                }
                $stripe['byId'][$eid] = ($stripe['byId'][$eid] ?? 0) + 1;
            }
            $stripe['ok'] = true;
        } catch (\Throwable $e) {
            $stripe['error'] = substr($e->getMessage(), 0, 200);
            Log::warning('AdminCms orders Stripe reconcile failed', ['err' => $e->getMessage()]);
        }

        // ---- merge: union of DB groups and Stripe keys ----
        $allKeys = array_values(array_unique(array_merge(array_keys($groups), array_keys($stripe['byKey']))));
        $out = [];
        foreach ($allKeys as $key) {
            $parts = explode($SEP, $key, 2);
            $eid   = $parts[0] ?? '';
            $enm   = $parts[1] ?? '';
            $orders   = $groups[$key]['orders'] ?? [];
            $dbCount  = count($orders);
            $dbEmails = array_map(fn ($o) => strtolower(trim((string) $o['email'])), $orders);
            $sCount   = $stripe['ok'] ? ($stripe['byKey'][$key]['count'] ?? 0) : null;
            $sEmails  = $stripe['byKey'][$key]['emails'] ?? [];
            $sEmailsLc = array_map(fn ($e) => strtolower(trim($e)), $sEmails);

            $stripeOnly = $stripe['ok']
                ? array_values(array_unique(array_filter($sEmails, fn ($e) => $e !== '' && !in_array(strtolower(trim($e)), $dbEmails, true))))
                : [];
            $dbOnly = $stripe['ok']
                ? array_values(array_unique(array_filter(
                    array_map(fn ($o) => (string) $o['email'], $orders),
                    fn ($e) => $e !== '' && !in_array(strtolower(trim($e)), $sEmailsLc, true)
                )))
                : [];

            $out[] = [
                'event_id'     => $eid,
                'event_name'   => $enm !== '' ? $enm : '(no name)',
                'event_date'   => $groups[$key]['event_date'] ?? '',
                'orders'       => $orders,
                'db_count'     => $dbCount,
                'site_count'   => (int) ($byId[$eid] ?? 0),
                'stripe_count' => $sCount,
                'stripe_only'  => $stripeOnly,
                'db_only'      => $dbOnly,
                'waitlist'     => (int) ($waitlistById[$eid] ?? 0),
                'mismatch'     => $stripe['ok'] ? ((int) $sCount !== $dbCount) : false,
            ];
        }

        usort($out, function ($a, $b) {
            if ($a['mismatch'] !== $b['mismatch']) {
                return $a['mismatch'] ? -1 : 1;
            }
            return ((int) $a['event_id']) <=> ((int) $b['event_id']);
        });

        return response()->json([
            'generated_at'      => now()->toIso8601String(),
            'max'               => $max,
            'total_db_paid'     => $leads->count(),
            'stripe_ok'         => $stripe['ok'],
            'stripe_error'      => $stripe['error'],
            'stripe_window_days' => $windowDays,
            'site_paid_by_id'   => $byId,
            'stripe_paid_by_id' => $stripe['ok'] ? $stripe['byId'] : null,
            'groups'            => $out,
        ]);
    }

    /**
     * Lightweight client list + analytics (read-only), built from Stripe.
     *
     * Stripe holds the full purchase history (the leads table only keeps recent rows),
     * so the customer base is aggregated from all paid Checkout sessions (minus refunds
     * and $0 tests), keyed by email.
     */
    public function clients(Request $request)
    {
        if (!$request->session()->get('cms_admin')) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }

        try {
            $sessions = $this->stripePaidSessions($request->boolean('fresh'));

            $byEmail = [];
            $classCounts = [];
            $thisMonth = now()->format('Y-m');

            foreach ($sessions as $s) {
                $email = $s['email'];
                if ($email === '') {
                    continue;
                }
                $name = $s['name']; $phone = $s['phone']; $amt = $s['amount']; $date = $s['date']; $cls = $s['eventName'];

                if (!isset($byEmail[$email])) {
                    $byEmail[$email] = [
                        'email'   => $email,
                        'name'    => $name,
                        'phone'   => $phone,
                        'orders'  => 0,
                        'spent'   => 0.0,
                        'first'   => $date,
                        'last'    => $date,
                        'classes' => [],
                    ];
                }
                $c = &$byEmail[$email];
                $c['orders']++;
                $c['spent'] += $amt;
                if ($date < $c['first']) $c['first'] = $date;
                if ($date > $c['last'])  $c['last']  = $date;
                if ($name !== '')  $c['name']  = $name;
                if ($phone !== '') $c['phone'] = $phone;
                if ($cls !== '')   $c['classes'][] = $cls;
                unset($c);

                if ($cls !== '') {
                    $classCounts[$cls] = ($classCounts[$cls] ?? 0) + 1;
                }
            }

            // Fold in named offline bookings from content.json so offline-paid clients
            // show up here too (and feed the broadcast audience). They already count
            // toward seats via bookedOffline; this is for the client record.
            try {
                $cjPath = public_path('content.json');
                if (is_file($cjPath)) {
                    $cj = json_decode((string) file_get_contents($cjPath), true);
                    foreach (($cj['events'] ?? []) as $ev) {
                        $price = (float) ($ev['price'] ?? 0);
                        $cls   = (string) ($ev['eventName'] ?? '');
                        foreach (($ev['offlineBookings'] ?? []) as $ob) {
                            $email = strtolower(trim((string) ($ob['email'] ?? '')));
                            if ($email === '') {
                                continue; // no contact → can't be a client record
                            }
                            $name  = trim((string) ($ob['name'] ?? ''));
                            $phone = (string) ($ob['phone'] ?? '');
                            $date  = (string) ($ob['date'] ?? '');
                            if (!isset($byEmail[$email])) {
                                $byEmail[$email] = [
                                    'email' => (string) ($ob['email'] ?? $email),
                                    'name' => $name, 'phone' => $phone,
                                    'orders' => 0, 'spent' => 0.0,
                                    'first' => $date ?: null, 'last' => $date ?: null,
                                    'classes' => [], 'offline' => true,
                                ];
                            }
                            $c = &$byEmail[$email];
                            $c['orders']++;
                            $c['spent'] += $price;
                            $c['offline'] = true;
                            if ($name !== '')  $c['name']  = $name;
                            if ($phone !== '') $c['phone'] = $phone;
                            if ($date !== '') {
                                if (empty($c['first']) || $date < $c['first']) $c['first'] = $date;
                                if (empty($c['last'])  || $date > $c['last'])  $c['last']  = $date;
                            }
                            if ($cls !== '') {
                                $c['classes'][] = $cls;
                                $classCounts[$cls] = ($classCounts[$cls] ?? 0) + 1;
                            }
                            unset($c);
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('AdminCms clients offline-merge failed', ['err' => $e->getMessage()]);
            }

            $clients = [];
            foreach ($byEmail as $c) {
                $c['classes']       = array_values(array_unique($c['classes']));
                $c['classes_count'] = count($c['classes']);
                $c['spent']         = round($c['spent'], 2);
                $clients[] = $c;
            }
            usort($clients, fn ($a, $b) => $b['spent'] <=> $a['spent']);

            arsort($classCounts);
            $topClasses = [];
            foreach (array_slice($classCounts, 0, 8, true) as $n => $cnt) {
                $topClasses[] = ['name' => $n, 'count' => $cnt];
            }

            return response()->json([
                'generated_at' => now()->toIso8601String(),
                'source'       => 'stripe',
                'summary' => [
                    'total_clients'  => count($clients),
                    'repeat_clients' => count(array_filter($clients, fn ($c) => $c['orders'] > 1)),
                    'total_revenue'  => round(array_sum(array_map(fn ($c) => $c['spent'], $clients)), 2),
                    'total_orders'   => array_sum(array_map(fn ($c) => $c['orders'], $clients)),
                    'new_this_month' => count(array_filter($clients, fn ($c) => substr((string) $c['first'], 0, 7) === $thisMonth)),
                ],
                'top_classes' => $topClasses,
                'clients'     => $clients,
            ]);
        } catch (\Throwable $e) {
            Log::error('AdminCms clients failed', ['err' => $e->getMessage()]);
            return response()->json(['error' => 'Clients load failed: ' . substr($e->getMessage(), 0, 200)], 502);
        }
    }

    /**
     * Broadcast audience (read-only): newsletter subscribers + past clients
     * (Stripe + named offline), excluding anyone already booked on an upcoming
     * (future-dated) event. For the re-engagement broadcast — no email is sent here.
     */
    public function audience(Request $request)
    {
        if (!$request->session()->get('cms_admin')) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }

        try {
            $sessions = $this->stripePaidSessions($request->boolean('fresh'));
            $cj = json_decode((string) @file_get_contents(public_path('content.json')), true) ?: [];
            $today = now()->format('Y-m-d');

            // Who's already booked on an upcoming event → exclude from re-engagement.
            $upcomingNames = [];
            $exclude = [];
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
                foreach ($this->newsletterSubscribers($request->boolean('fresh')) as $sub) {
                    $touch(strtolower(trim((string) ($sub['email'] ?? ''))), (string) ($sub['name'] ?? ''), null, 'subscriber');
                }
            } catch (\Throwable $e) {
                $subscriberError = substr($e->getMessage(), 0, 200);
                Log::warning('AdminCms audience subscriber read failed', ['err' => $e->getMessage()]);
            }

            // Suppress anyone who unsubscribed (table created out-of-band; absent → skip).
            try {
                foreach (\Illuminate\Support\Facades\DB::table('broadcast_unsubscribes')->pluck('email') as $u) {
                    $exclude[strtolower(trim((string) $u))] = true;
                }
            } catch (\Throwable $e) {
                // suppression table not present yet — no-op
            }

            $recipients = [];
            foreach ($byEmail as $email => $r) {
                if (isset($exclude[$email])) {
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

            $bySource = ['client' => 0, 'subscriber' => 0, 'both' => 0];
            foreach ($recipients as $r) {
                $bySource[$r['source']]++;
            }

            return response()->json([
                'generated_at'     => now()->toIso8601String(),
                'summary'          => ['total' => count($recipients), 'by_source' => $bySource, 'excluded_upcoming' => count($exclude)],
                'subscriber_error' => $subscriberError,
                'recipients'       => $recipients,
            ]);
        } catch (\Throwable $e) {
            Log::error('AdminCms audience failed', ['err' => $e->getMessage()]);
            return response()->json(['error' => 'Audience load failed: ' . substr($e->getMessage(), 0, 200)], 502);
        }
    }

    /**
     * Send the broadcast via Resend (free-tier friendly). `test` mode emails one
     * address; `bulk` sends up to `limit` (<=100) of the recipients passed from the
     * audience view, skipping unsubscribed + already-sent-this-campaign. No send here
     * touches anyone twice (recorded in broadcast_sent).
     */
    public function sendBroadcast(Request $request)
    {
        if (!$request->session()->get('cms_admin')) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }
        $apiKey  = env('RESEND_API_KEY');
        $from    = env('BROADCAST_FROM');
        $replyTo = env('BROADCAST_REPLY_TO');
        if (!$apiKey || !$from) {
            return response()->json(['error' => 'Sending not configured (set RESEND_API_KEY and BROADCAST_FROM in env).'], 400);
        }

        $subject = trim((string) $request->input('subject', ''));
        $body    = (string) $request->input('body', '');
        if ($subject === '' || trim($body) === '') {
            return response()->json(['error' => 'Subject and body are required.'], 422);
        }
        $mode     = (string) $request->input('mode', 'test');
        $campaign = substr(md5($subject), 0, 24);

        $send = function (array $payload, string $path) use ($apiKey) {
            return Http::withToken($apiKey)->asJson()->timeout(30)
                ->post('https://api.resend.com/' . $path, $payload);
        };
        $headers = fn (string $email) => [
            'List-Unsubscribe'      => '<' . $this->unsubUrl($email) . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];

        // ---- TEST ----
        if ($mode === 'test') {
            $to = strtolower(trim((string) $request->input('test_email', '')));
            if ($to === '' || !str_contains($to, '@')) {
                return response()->json(['error' => 'Enter a valid test email.'], 422);
            }
            $resp = $send([
                'from' => $from, 'to' => [$to], 'reply_to' => $replyTo ?: null,
                'subject' => '[TEST] ' . $subject,
                'html' => $this->renderEmailHtml($body, 'there', $to),
                'headers' => $headers($to),
            ], 'emails');
            if (!$resp->successful()) {
                return response()->json(['error' => 'Resend: ' . ($resp->json('message') ?: ('HTTP ' . $resp->status()))], 502);
            }
            return response()->json(['ok' => true, 'sent' => 1, 'id' => $resp->json('id')]);
        }

        // ---- BULK ----
        $incoming = $request->input('recipients', []);
        if (!is_array($incoming)) {
            $incoming = [];
        }
        $limit = min(100, max(1, (int) $request->input('limit', 100)));

        $unsub = [];
        $already = [];
        try {
            foreach (\Illuminate\Support\Facades\DB::table('broadcast_unsubscribes')->pluck('email') as $u) {
                $unsub[strtolower(trim((string) $u))] = true;
            }
        } catch (\Throwable $e) {
        }
        try {
            foreach (\Illuminate\Support\Facades\DB::table('broadcast_sent')->where('campaign', $campaign)->pluck('email') as $u) {
                $already[strtolower(trim((string) $u))] = true;
            }
        } catch (\Throwable $e) {
        }

        $batch = [];
        $picked = [];
        $eligible = 0;
        foreach ($incoming as $r) {
            $email = strtolower(trim((string) ($r['email'] ?? '')));
            if ($email === '' || !str_contains($email, '@') || isset($unsub[$email]) || isset($already[$email]) || isset($picked[$email])) {
                continue;
            }
            $eligible++;
            if (count($batch) >= $limit) {
                continue; // count remaining but don't add
            }
            $picked[$email] = true;
            $nm = trim((string) ($r['name'] ?? ''));
            $first = $nm !== '' ? preg_split('/\s+/', $nm)[0] : 'there';
            $batch[] = [
                'from' => $from, 'to' => [(string) $r['email']], 'reply_to' => $replyTo ?: null,
                'subject' => $subject,
                'html' => $this->renderEmailHtml($body, $first, $email),
                'headers' => $headers($email),
            ];
        }

        if (empty($batch)) {
            return response()->json(['ok' => true, 'sent' => 0, 'remaining' => 0, 'message' => 'Nobody left to send to (all already sent or unsubscribed).']);
        }

        $resp = $send($batch, 'emails/batch');
        if (!$resp->successful()) {
            return response()->json(['error' => 'Resend: ' . ($resp->json('message') ?: ('HTTP ' . $resp->status()))], 502);
        }

        try {
            $rows = array_map(fn ($e) => ['campaign' => $campaign, 'email' => $e, 'sent_at' => now()], array_keys($picked));
            \Illuminate\Support\Facades\DB::table('broadcast_sent')->insertOrIgnore($rows);
        } catch (\Throwable $e) {
            Log::warning('broadcast_sent insert failed', ['err' => $e->getMessage()]);
        }

        return response()->json(['ok' => true, 'sent' => count($batch), 'remaining' => max(0, $eligible - count($batch))]);
    }

    /** Public unsubscribe landing — records the email in the suppression list. */
    public function unsubscribe(Request $request)
    {
        $email = strtolower(trim((string) $request->query('e', '')));
        $token = (string) $request->query('t', '');
        $ok = false;
        if ($email !== '' && str_contains($email, '@') && hash_equals($this->unsubToken($email), $token)) {
            try {
                \Illuminate\Support\Facades\DB::table('broadcast_unsubscribes')->insertOrIgnore(['email' => $email, 'created_at' => now()]);
                $ok = true;
            } catch (\Throwable $e) {
                Log::error('unsubscribe insert failed', ['err' => $e->getMessage()]);
            }
        }
        $msg = $ok
            ? 'You have been unsubscribed and will no longer receive emails from Shuhai Art Studio.'
            : 'This unsubscribe link is invalid or expired. Email a.art.shuhai@gmail.com to be removed.';
        $html = '<!doctype html><meta charset="utf-8"><title>Unsubscribe</title>'
            . '<div style="font-family:system-ui,-apple-system,sans-serif;max-width:520px;margin:80px auto;padding:0 20px;text-align:center;">'
            . '<h2 style="font-weight:600;font-family:Georgia,serif;">Shuhai Art Studio</h2>'
            . '<p style="font-size:16px;line-height:1.55;color:#333;">' . htmlspecialchars($msg) . '</p></div>';
        return response($html, $ok ? 200 : 400)->header('Content-Type', 'text/html; charset=utf-8');
    }

    private function unsubToken(string $email): string
    {
        return substr(hash_hmac('sha256', strtolower(trim($email)), (string) config('app.key')), 0, 32);
    }
    private function unsubUrl(string $email): string
    {
        $base = rtrim((string) (config('app.frontend_url') ?: config('app.url') ?: 'https://art-shuhai.com'), '/');
        return $base . '/unsubscribe?e=' . urlencode($email) . '&t=' . $this->unsubToken($email);
    }
    private function renderEmailHtml(string $body, string $firstName, string $email): string
    {
        $text   = str_replace('{name}', $firstName, $body);
        $escaped = nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
        $unsub  = htmlspecialchars($this->unsubUrl($email), ENT_QUOTES, 'UTF-8');
        $signoff = '<p style="margin-top:18px;">&mdash; Alevtyna, Shuhai Art Studio</p>';
        $footer = '<hr style="border:none;border-top:1px solid #eee;margin:24px 0 16px;">'
            . '<p style="font-size:12px;color:#999;line-height:1.5;">1324 11 Ave SW #202, Calgary &middot; a.art.shuhai@gmail.com<br>'
            . 'You are receiving this because you took a class or subscribed at art-shuhai.com. '
            . '<a href="' . $unsub . '" style="color:#999;">Unsubscribe</a>.</p>';
        return '<div style="font-family:system-ui,-apple-system,Segoe UI,sans-serif;font-size:15px;line-height:1.6;color:#222;max-width:560px;margin:0 auto;">'
            . '<p>' . $escaped . '</p>' . $signoff . $footer . '</div>';
    }

    /** All paid Stripe Checkout sessions (minus refunds and $0 tests), cached 10 min. */
    private function stripePaidSessions(bool $fresh = false): array
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
    private function newsletterSubscribers(bool $fresh = false): array
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

    public function diag(Request $request)
    {
        // Unauthenticated diagnostic: surfaces effective session config + PHP upload
        // limits so we can confirm env/config caching is not masking the real values.
        // Reveals no secrets.
        return response()->json([
            'session_driver_config' => config('session.driver'),
            'session_driver_env'    => env('SESSION_DRIVER'),
            'session_lifetime'      => config('session.lifetime'),
            'app_env'               => config('app.env'),
            'app_debug'             => (bool) config('app.debug'),
            'config_cached'         => file_exists(base_path('bootstrap/cache/config.php')),
            'session_id'            => $request->session()->getId(),
            'cms_admin_in_session'  => $request->session()->get('cms_admin') === true,
            'request_cookies'       => array_keys($request->cookies->all()),
            'php_ini' => [
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size'       => ini_get('post_max_size'),
                'memory_limit'        => ini_get('memory_limit'),
                'max_execution_time'  => ini_get('max_execution_time'),
                'loaded_ini'          => php_ini_loaded_file() ?: '(none)',
            ],
        ]);
    }

    public function load(Request $request)
    {
        if (!$request->session()->get('cms_admin')) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }
        $token = (string) env('GITHUB_TOKEN', '');
        if ($token === '') {
            return response()->json(['error' => 'GITHUB_TOKEN not configured'], 503);
        }
        try {
            $resp = Http::withToken($token)
                ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
                ->timeout(30)
                ->connectTimeout(10)
                ->get($this->ghUrl('/contents/' . self::CONTENT_PATH), ['ref' => self::REPO_BRANCH]);
            if (!$resp->successful()) {
                $detail = $resp->json('message') ?: (substr((string) $resp->body(), 0, 200) ?: 'no body');
                return response()->json(['error' => 'GitHub ' . $resp->status() . ': ' . $detail], 502);
            }
            $data = $resp->json();
            $content = base64_decode(str_replace("\n", '', $data['content']));
            return response()->json([
                'content' => json_decode($content, true),
                'sha' => $data['sha'],
            ]);
        } catch (\Throwable $e) {
            Log::error('AdminCms load failed', ['err' => $e->getMessage()]);
            return response()->json(['error' => 'Load failed: ' . $e->getMessage()], 500);
        }
    }

    public function save(Request $request)
    {
        if (!$request->session()->get('cms_admin')) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }
        $token = (string) env('GITHUB_TOKEN', '');
        if ($token === '') {
            return response()->json(['error' => 'GITHUB_TOKEN not configured'], 503);
        }
        $content = $request->input('content');
        $sha = (string) $request->input('sha', '');
        if (!is_array($content) || $sha === '') {
            return response()->json(['error' => 'content + sha required'], 400);
        }
        try {
            $content['updatedAt'] = now()->toIso8601String();
            $content['lastEventId'] = $this->highestClassId($content);
            $payload = json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            $resp = Http::withToken($token)
                ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
                ->timeout(60)
                ->connectTimeout(10)
                ->put($this->ghUrl('/contents/' . self::CONTENT_PATH), [
                    'message' => 'Admin: content update (' . now()->format('Y-m-d H:i') . ')',
                    'content' => base64_encode($payload),
                    'sha' => $sha,
                    'branch' => self::REPO_BRANCH,
                ]);
            if (!$resp->successful()) {
                $detail = $resp->json('message') ?: (substr((string) $resp->body(), 0, 200) ?: 'no body');
                return response()->json(['error' => 'GitHub ' . $resp->status() . ': ' . $detail], 502);
            }
            return response()->json(['ok' => true, 'sha' => $resp->json('content.sha')]);
        } catch (\Throwable $e) {
            Log::error('AdminCms save failed', ['err' => $e->getMessage()]);
            return response()->json(['error' => 'Save failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Highest class number ever handed out.
     *
     * Stored in content.json and never allowed to go down, so a class number is
     * retired with its class: paid orders live under that number, and reissuing
     * it makes the old orders count against the new class. Kept server-side as
     * well as in the editor so a stale open browser tab cannot reset it.
     */
    private function highestClassId(array $content): int
    {
        $ids = [(int) ($content['lastEventId'] ?? 0)];
        foreach (['events', 'regularClasses'] as $key) {
            foreach ((array) ($content[$key] ?? []) as $item) {
                $ids[] = (int) (is_array($item) ? ($item['id'] ?? 0) : 0);
            }
        }

        return max($ids);
    }

    public function upload(Request $request)
    {
        if (!$request->session()->get('cms_admin')) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }
        $token = (string) env('GITHUB_TOKEN', '');
        if ($token === '') {
            return response()->json(['error' => 'GITHUB_TOKEN not configured'], 503);
        }
        $file = $request->file('image');
        if (!$file || !$file->isValid()) {
            // Distinguish "user sent nothing" from "PHP rejected the upload before
            // we got a chance to see it" (post_max_size exceeded → $_POST/$_FILES emptied,
            // upload_max_filesize exceeded → file is set but invalid with UPLOAD_ERR_INI_SIZE).
            $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
            $postMax       = $this->iniBytes(ini_get('post_max_size'));
            $uploadMax     = $this->iniBytes(ini_get('upload_max_filesize'));
            if ($postMax > 0 && $contentLength > $postMax) {
                return response()->json([
                    'error' => 'Image too large for the server (post body ' . round($contentLength / 1024 / 1024, 1)
                        . ' MB > post_max_size ' . ini_get('post_max_size') . '). Resize and retry.',
                ], 413);
            }
            if ($file && $file->getError() === UPLOAD_ERR_INI_SIZE) {
                return response()->json([
                    'error' => 'Image larger than upload_max_filesize (' . ini_get('upload_max_filesize') . '). Resize and retry.',
                ], 413);
            }
            return response()->json(['error' => 'No file uploaded'], 400);
        }
        if ($file->getSize() > 8 * 1024 * 1024) {
            return response()->json(['error' => 'Image too large (max 8 MB)'], 400);
        }
        $mime = $file->getMimeType();
        if (!Str::startsWith($mime, 'image/')) {
            return response()->json(['error' => 'Not an image file'], 400);
        }
        $safeName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) . '.' . strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $path = self::IMAGE_DIR . '/' . time() . '-' . $safeName;
        $b64 = base64_encode(file_get_contents($file->getRealPath()));
        $url = '/' . substr($path, strlen('public/'));
        $ghHeaders = ['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'];
        try {
            $resp = Http::withToken($token)
                ->withHeaders($ghHeaders)
                ->timeout(90)
                ->connectTimeout(10)
                ->put($this->ghUrl('/contents/' . $path), [
                    'message' => 'Admin: upload ' . $safeName,
                    'content' => $b64,
                    'branch' => self::REPO_BRANCH,
                ]);
            if ($resp->successful()) {
                return response()->json(['ok' => true, 'url' => $url]);
            }
            $detail = $resp->json('message') ?: (substr((string) $resp->body(), 0, 200) ?: 'no body');
            return response()->json([
                'error' => 'GitHub ' . $resp->status() . ': ' . $detail,
            ], 502);
        } catch (\Throwable $e) {
            // Timeouts under PUT-large-base64 are not rare; GitHub may have completed
            // the write before our client gave up. Verify by GETing the path back.
            Log::warning('AdminCms upload threw — verifying via GET', [
                'err'  => $e->getMessage(),
                'path' => $path,
            ]);
            try {
                $verify = Http::withToken($token)
                    ->withHeaders($ghHeaders)
                    ->timeout(15)
                    ->get($this->ghUrl('/contents/' . $path), ['ref' => self::REPO_BRANCH]);
                if ($verify->ok()) {
                    Log::info('AdminCms upload verified after timeout', ['path' => $path]);
                    return response()->json(['ok' => true, 'url' => $url, 'recovered' => true]);
                }
            } catch (\Throwable $verifyErr) {
                Log::error('AdminCms upload verify also failed', ['err' => $verifyErr->getMessage()]);
            }
            return response()->json([
                'error' => 'Upload failed: ' . substr($e->getMessage(), 0, 200),
            ], 500);
        }
    }

    private function ghUrl(string $tail): string
    {
        return 'https://api.github.com/repos/' . self::REPO_OWNER . '/' . self::REPO_NAME . $tail;
    }

    private function iniBytes(string $val): int
    {
        $val = trim($val);
        if ($val === '') {
            return 0;
        }
        $last = strtolower(substr($val, -1));
        $num  = (int) $val;
        return match ($last) {
            'g'     => $num * 1024 * 1024 * 1024,
            'm'     => $num * 1024 * 1024,
            'k'     => $num * 1024,
            default => $num,
        };
    }
}
