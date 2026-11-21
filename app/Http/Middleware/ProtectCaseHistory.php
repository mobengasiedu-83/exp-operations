<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ProtectCaseHistory
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('knowledge-bank/offline')) {
            return $next($request);
        }

        if (! $request->is('cases', 'cases/*', 'workspace', 'transfers', 'transfers/*', 'callbacks', 'callbacks/*', 'referrals', 'referrals/*', 'knowledge-bank', 'knowledge-bank/*', 'quality-assurance', 'quality-assurance/*', 'training', 'training/*', 'incidents', 'incidents/*')) {
            return $next($request);
        }
        Inertia::encryptHistory($request->isSecure());
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
