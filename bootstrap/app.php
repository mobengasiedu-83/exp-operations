<?php

use App\Http\Middleware\AuditAdministration;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ProtectCaseHistory;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            ProtectCaseHistory::class,
            AddLinkHeadersForPreloadedAssets::class,
            EnsureUserIsActive::class,
            AuditAdministration::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash([
            'preferred_name', 'callback_number', 'guardian_name', 'guardian_relationship',
            'concern', 'onset', 'impact', 'body', 'note', 'review_reason', 'next_step', 'answers',
            'reason', 'next_action', 'outcome_note', 'destination_contact', 'notes',
            'summary', 'change_summary', 'approver_name',
            'description', 'immediate_action', 'escalation_note', 'resolution_summary',
            'critical_note', 'coaching_note', 'completion_note', 'verification_note',
            'purpose', 'assessment_note', 'revocation_reason', 'calibration_note',
        ]);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
