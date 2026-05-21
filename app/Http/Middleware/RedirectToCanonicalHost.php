<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RedirectToCanonicalHost
{
    private const CANONICAL_HOST = 'art-shuhai.com';

    public function handle(Request $request, Closure $next)
    {
        $host = $request->getHost();
        $isCanonical = $host === self::CANONICAL_HOST
            || $host === 'www.' . self::CANONICAL_HOST;

        // Don't redirect API endpoints — third parties (Stripe, etc.) post
        // directly to whatever URL was registered in their dashboard, and
        // many don't follow 301 for POST.
        $isApi = str_starts_with($request->path(), 'api/');

        if (!$isCanonical && !$isApi) {
            return redirect()->away(
                'https://' . self::CANONICAL_HOST . $request->getRequestUri(),
                301
            );
        }

        return $next($request);
    }
}
