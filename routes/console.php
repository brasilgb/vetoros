<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('vetoros:send-payment-followups')
    ->dailyAt('09:00')
    ->withoutOverlapping();

Schedule::command('vetoros:send-budget-followups')
    ->dailyAt('10:00')
    ->withoutOverlapping();

Schedule::command('vetoros:send-subscription-status-notifications')
    ->dailyAt('08:00')
    ->withoutOverlapping();

Schedule::command('vetoros:process-maintenance-contracts')
    ->dailyAt('07:30')
    ->withoutOverlapping();

Schedule::command('vetoros:send-tenant-feedback-requests')
    ->dailyAt('11:00')
    ->withoutOverlapping();

Schedule::command('vetoros:process-customer-feedback-requests')
    ->dailyAt('11:30')
    ->withoutOverlapping();

Schedule::command('fiscal:sync-spedy')
    ->everyTenMinutes()
    ->withoutOverlapping();
