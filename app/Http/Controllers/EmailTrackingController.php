<?php

namespace App\Http\Controllers;

use App\Support\Broadcast;
use App\Support\EventLinks;
use Illuminate\Http\Request;

/**
 * Opens and clicks of campaign emails (see Broadcast::openPixelUrl / clickUrl).
 * Links are signed per campaign + address; an unsigned or tampered link still
 * works for the reader (image / redirect) but records nothing.
 */
class EmailTrackingController extends Controller
{
    /** 1×1 transparent GIF. */
    private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function open(Request $request)
    {
        $campaign = (string) $request->query('c', '');
        $email = Broadcast::verifyTrack($campaign, (string) $request->query('r', ''), (string) $request->query('t', ''));
        if ($email !== null) {
            Broadcast::recordEvent($campaign, $email, 'open');
        }
        return response(base64_decode(self::PIXEL), 200, [
            'Content-Type'  => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    public function click(Request $request)
    {
        $target = (string) $request->query('u', '');
        $host = strtolower((string) parse_url($target, PHP_URL_HOST));
        // Only our own site: anything else would make this an open redirect.
        if (!preg_match('#^https?://#i', $target) || !in_array($host, ['art-shuhai.com', 'www.art-shuhai.com'], true)) {
            $target = EventLinks::CANONICAL_BASE . '/#events';
        }

        $campaign = (string) $request->query('c', '');
        $email = Broadcast::verifyTrack($campaign, (string) $request->query('r', ''), (string) $request->query('t', ''));
        if ($email !== null) {
            Broadcast::recordEvent($campaign, $email, 'click', $target);
        }
        return redirect()->away($target, 302);
    }
}
