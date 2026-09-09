<?php

use App\Http\Controllers\Api\V1\AcopioController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ProductorController;
use App\Http\Controllers\Api\V1\RecepcionController;
use App\Http\Middleware\EnsureActiveUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware(['auth:sanctum', EnsureActiveUser::class]);

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');
    Route::middleware(['auth:sanctum', EnsureActiveUser::class])->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::apiResource('productores', ProductorController::class)->parameters(['productores' => 'productor'])->whereNumber('productor');
        Route::prefix('acopios')->name('acopios.')->group(function (): void {
            Route::get('/rutas', [AcopioController::class, 'routes'])->name('rutas');
            Route::post('/jornadas', [AcopioController::class, 'storeJourney'])->name('jornadas.store');
            Route::get('/jornadas/{uuid}', [AcopioController::class, 'showJourney'])->whereUuid('uuid')->name('jornadas.show');
            Route::post('/sincronizar', [AcopioController::class, 'sync'])->name('sync');
        });
        Route::post('/recepciones/lecturas', [RecepcionController::class, 'store'])->name('recepciones.lecturas.store');
    });
});
