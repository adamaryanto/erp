<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// TEMPORARY (gampang dihapus): watchdog diagnosa tiap 5 menit.
// Butuh scheduler jalan (cron / Task Scheduler: php artisan schedule:run).
Schedule::command('diagnose:watch')->everyFiveMinutes();
