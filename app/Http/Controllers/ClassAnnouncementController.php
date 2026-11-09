<?php

namespace App\Http\Controllers;

use App\Support\ClassAnnouncer;
use App\Support\ReviewRequester;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/class-announcements/run — called hourly by the Shuhai Marketing
 * droplet cron. All the decisions (the 1st of the month, sending hours, daily
 * cap) are made in ClassAnnouncer; this only checks who is calling.
 *
 *   dry=1             report what would happen, change nothing
 *   preview_to=EMAIL  send one [TEST] copy of the next monthly email there, change nothing
 *   force=1           send this month's email today although it isn't the 1st (catch-up)
 *   stats=CAMPAIGN    opens / clicks / bookings of a campaign (what the admin "Email stats" tab shows)
 *
 * The same call also runs the review requests (App\Support\ReviewRequester) and
 * reports them under 'reviews'; dry=1 applies to them too.
 *   reviews_preview_to=EMAIL  one [TEST] review-request email there, change nothing
 *   stats=reviews             who got it / opened / clicked "Leave a review"
 */
class ClassAnnouncementController extends Controller
{
    /**
     * sha256 of the token the cron sends in X-Announce-Token. The token itself
     * lives only on the droplet (/root/.config/art-shuhai/announce_token), so it
     * needs no Railway variable. To rotate: new token there, new hash here.
     */
    private const TOKEN_SHA256 = '284e7515fdbefe774af9b0685b14fd1ee5148f9cb8b341b928087e7fe16c394f';

    public function run(Request $request)
    {
        $token = (string) $request->header('X-Announce-Token', '');
        // The config key exists only so tests can call the endpoint with a token of their own.
        $expected = (string) config('services.class_announce_token_sha256', self::TOKEN_SHA256);
        if ($token === '' || !hash_equals($expected, hash('sha256', $token))) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        // Two overlapping calls must not both start a campaign.
        $lock = Cache::store('file')->lock('class-announcer', 600);
        if (!$lock->get()) {
            return response()->json(['action' => 'busy'], 409);
        }
        try {
            $announcer = new ClassAnnouncer();
            $stats = trim((string) $request->input('stats', ''));
            if ($stats !== '') {
                return response()->json(\App\Support\Broadcast::campaignStats($stats));
            }
            $previewTo = trim((string) $request->input('preview_to', ''));
            if ($previewTo !== '') {
                if (!filter_var($previewTo, FILTER_VALIDATE_EMAIL)) {
                    return response()->json(['error' => 'preview_to is not an email'], 422);
                }
                return response()->json($announcer->preview($previewTo));
            }
            $reviewPreviewTo = trim((string) $request->input('reviews_preview_to', ''));
            if ($reviewPreviewTo !== '') {
                if (!filter_var($reviewPreviewTo, FILTER_VALIDATE_EMAIL)) {
                    return response()->json(['error' => 'reviews_preview_to is not an email'], 422);
                }
                return response()->json((new ReviewRequester())->preview($reviewPreviewTo));
            }
            // Review requests ride the same hourly call and go FIRST: on a class-email
            // day the class email takes only what is left of the daily cap (Jack,
            // 2026-11-09). Their own failure must not look like a failed class email,
            // so it is reported inside 'reviews'.
            try {
                $reviews = (new ReviewRequester())->run($request->boolean('dry'));
            } catch (\Throwable $e) {
                Log::error('Review requests failed', ['err' => $e->getMessage()]);
                $reviews = ['action' => 'error', 'error' => substr($e->getMessage(), 0, 300)];
            }
            $result = $announcer->run($request->boolean('dry'), $request->boolean('force'));
            $result['reviews'] = $reviews;
            return response()->json($result);
        } catch (\Throwable $e) {
            Log::error('Class announcement failed', ['err' => $e->getMessage()]);
            return response()->json(['error' => substr($e->getMessage(), 0, 300)], 500);
        } finally {
            $lock->release();
        }
    }
}
