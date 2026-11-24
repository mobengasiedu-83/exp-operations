<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('exp:prune-telemetry')->hourly()->withoutOverlapping();

Schedule::command('horizon:snapshot')->everyFiveMinutes();

Schedule::command('exp:reconcile-calls --pending')->everyMinute()->withoutOverlapping();

Schedule::command('exp:close-stale-journeys')->everyMinute()->withoutOverlapping();

Schedule::command('exp:evaluate-alerts')->everyMinute()->withoutOverlapping();

Schedule::command('exp:escalate-callbacks')->everyFiveMinutes()->withoutOverlapping();
