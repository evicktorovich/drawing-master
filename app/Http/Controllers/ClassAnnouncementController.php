<?php

namespace App\Http\Controllers;

use App\Support\ClassAnnouncer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/class-announcements/run — called hourly by the Shuhai Marketing
 * droplet cron. All the decisions (what is new, sending hours, daily cap) are
 * made in ClassAnnouncer; this only checks who is calling.
 *
 *   dry=1             report what would happen, change nothing
 *   preview_to=EMAIL  send one [TEST] copy of the next email there, change nothing
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
        if ($token === '' || !hash_equals(self::TOKEN_SHA256, hash('sha256', $token))) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        // Two overlapping calls must not both start a campaign.
        $lock = Cache::store('file')->lock('class-announcer', 600);
        if (!$lock->get()) {
            return response()->json(['action' => 'busy'], 409);
        }
        try {
            $announcer = new ClassAnnouncer();
            $previewTo = trim((string) $request->input('preview_to', ''));
            if ($previewTo !== '') {
                if (!filter_var($previewTo, FILTER_VALIDATE_EMAIL)) {
                    return response()->json(['error' => 'preview_to is not an email'], 422);
                }
                return response()->json($announcer->preview($previewTo));
            }
            return response()->json($announcer->run($request->boolean('dry')));
        } catch (\Throwable $e) {
            Log::error('Class announcement failed', ['err' => $e->getMessage()]);
            return response()->json(['error' => substr($e->getMessage(), 0, 300)], 500);
        } finally {
            $lock->release();
        }
    }
}
