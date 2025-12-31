<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WhatsappController;
use App\Http\Controllers\WhatsappSeguimientoController;
/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Aquí registras todas las rutas que deberían ser accedidas sin CSRF,
| como webhooks. Estas rutas se cargan por el middleware "api".
|
*/

// Ruta de prueba opcional
Route::get('/ping', function () {
    return response()->json(['message' => 'pong']);
});

// Webhook de Meta (WhatsApp Cloud API)
Route::get('/webhook', [WhatsappController::class, 'verify']);
Route::post('/webhook', [WhatsappController::class, 'receive']);


//Webhook para bot seguimiento
Route::get('/webhook-seguimiento', [WhatsappSeguimientoController::class, 'verify']);
Route::post('/webhook-seguimiento', [WhatsappSeguimientoController::class, 'receive']);
