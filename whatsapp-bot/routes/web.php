<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TicketWebController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/formulario-ticket/{phone}', [TicketWebController::class, 'formulario']);
Route::post('/formulario-ticket', [TicketWebController::class, 'guardar']);
