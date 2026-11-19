<?php

use App\Agents\AgentManager;
use App\Agents\EgressSync;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * La reconciliacion la hace el bridge (taap:bridge) en tiempo real; el
 * scheduler es la red de seguridad por si el bridge no esta corriendo.
 */
Schedule::call(fn () => app(AgentManager::class)->reconcile())
    ->name('taap-reconcile')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::call(fn () => app(EgressSync::class)->sync())
    ->everyFiveMinutes();
