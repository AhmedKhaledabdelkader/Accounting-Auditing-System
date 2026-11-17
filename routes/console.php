<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Needs the scheduler in production (`* * * * * php artisan schedule:run`), or abandoned uploads are never deleted.
Schedule::command('classification:prune')->hourly();
// A crashed worker's run must not show "running" for up to an hour: the stalled check alone runs every 5 minutes.
Schedule::command('classification:prune --stalled')->everyFiveMinutes()->withoutOverlapping();
