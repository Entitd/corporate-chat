<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('crm:sync-users')
    ->cron(config('crm.sync_cron'))
    ->withoutOverlapping()
    ->when(fn (): bool => (bool) config('crm.sync_enabled'));
