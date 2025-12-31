<?php

use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
*/

Schedule::call(function () {
    Log::info('🔥 SCHEDULER ACTIVO (routes/console.php)');
})->everyMinute();

Schedule::command('seguimiento:buscar-tickets')
    ->everyMinute();
