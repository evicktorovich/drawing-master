<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
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
