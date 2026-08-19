<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Run the reaper every 5 minutes to clean up zombie AI jobs
// Schedule::command('ai:reap-zombies')->everyFiveMinutes();
