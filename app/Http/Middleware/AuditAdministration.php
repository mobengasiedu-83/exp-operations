<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditAdministration
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->user() !== null && ($request->is('admin/*') || in_array($request->route()?->getName(), ['calls.reconcile', 'live-operations.alerts.acknowledge'], true)) && ! $request->isMethodSafe() && $response->getStatusCode() < 400 && ! session()->has('errors')) {
            activity('administration')->causedBy($request->user())
                ->withProperties(['route' => $request->route()?->getName(), 'method' => $request->method()])
                ->log('Administration change');
        }

        return $response;
    }
}
