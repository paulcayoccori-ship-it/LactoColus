<?php

use App\Http\Controllers\Api\V1\AcopioController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CalidadController;
use App\Http\Controllers\Api\V1\ComunicadoController;
use App\Http\Controllers\Api\V1\LiquidacionController;
use App\Http\Controllers\Api\V1\PenalizacionController;
use App\Http\Controllers\Api\V1\ProduccionController;
use App\Http\Controllers\Api\V1\ProductorController;
use App\Http\Controllers\Api\V1\RankingController;
use App\Http\Controllers\Api\V1\RecepcionController;
use App\Http\Controllers\Api\V1\ReporteController;
use App\Http\Controllers\Api\V1\TrasladoController;
use App\Http\Controllers\Api\V1\VentasController;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return new UserResource($request->user()->load('roles'));
})->middleware(['auth:sanctum', EnsureActiveUser::class]);

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('/ranking', [RankingController::class, 'index'])->middleware('throttle:60,1')->name('ranking.publico');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');
    Route::middleware(['auth:sanctum', EnsureActiveUser::class])->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::apiResource('productores', ProductorController::class)->middleware('can:api-productores-existente')->parameters(['productores' => 'productor'])->whereNumber('productor');
        Route::prefix('acopios')->name('acopios.')->group(function (): void {
            Route::get('/rutas', [AcopioController::class, 'routes'])->name('rutas');
            Route::get('/jornadas', [AcopioController::class, 'journeys'])->name('jornadas.index');
            Route::post('/jornadas', [AcopioController::class, 'storeJourney'])->name('jornadas.store');
            Route::get('/jornadas/{uuid}', [AcopioController::class, 'showJourney'])->whereUuid('uuid')->name('jornadas.show');
            Route::post('/jornadas/{uuid}/cerrar', [AcopioController::class, 'closeJourney'])->whereUuid('uuid')->name('jornadas.cerrar');
            Route::post('/sincronizar', [AcopioController::class, 'sync'])->name('sync');
        });
        Route::get('reportes', [ReporteController::class, 'options'])->name('reportes.opciones');
        Route::get('reportes/{type}/csv', [ReporteController::class, 'csv'])->middleware('throttle:20,1')->name('reportes.csv');
        Route::get('reportes/{type}', [ReporteController::class, 'show'])->name('reportes.show');
        Route::prefix('liquidaciones')->name('liquidaciones.')->group(function (): void {
            Route::get('/', [LiquidacionController::class, 'index'])->name('index');
            Route::get('periodos', [LiquidacionController::class, 'periods'])->name('periodos');
            Route::post('periodos', [LiquidacionController::class, 'createPeriod'])->name('periodos.store');
            Route::post('reglas', [LiquidacionController::class, 'configure'])->name('reglas');
            Route::post('periodos/{uuid}/{action}', [LiquidacionController::class, 'periodAction'])->whereUuid('uuid')->whereIn('action', ['cerrar', 'calcular', 'aprobar', 'anular'])->name('periodos.action');
            Route::post('ajustes/{uuid}/decidir', [LiquidacionController::class, 'decideAdjustment'])->whereUuid('uuid')->name('ajustes.decidir');
            Route::get('{uuid}/comprobante.pdf', [LiquidacionController::class, 'pdf'])->whereUuid('uuid')->name('pdf');
            Route::get('{uuid}', [LiquidacionController::class, 'show'])->whereUuid('uuid')->name('show');
            Route::post('{uuid}/ajustes', [LiquidacionController::class, 'adjustment'])->whereUuid('uuid')->name('ajustes');
            Route::post('{uuid}/pagar', [LiquidacionController::class, 'pay'])->whereUuid('uuid')->name('pagar');
        });
        Route::prefix('penalizaciones')->name('penalizaciones.')->middleware('can:administrar-penalizaciones')->group(function (): void {
            Route::get('/', [PenalizacionController::class, 'index'])->name('index');
            Route::post('reglas', [PenalizacionController::class, 'configuration'])->name('reglas');
            Route::post('productores/{productor}/recalcular', [PenalizacionController::class, 'recalculate'])->whereNumber('productor')->name('recalcular');
            Route::post('{uuid}/decidir', [PenalizacionController::class, 'decide'])->whereUuid('uuid')->name('decidir');
            Route::post('{uuid}/anular', [PenalizacionController::class, 'annul'])->whereUuid('uuid')->name('anular');
            Route::get('asistencias', [PenalizacionController::class, 'assistances'])->name('asistencias');
            Route::post('asistencias/{uuid}', [PenalizacionController::class, 'assistance'])->whereUuid('uuid')->name('asistencias.update');
        });
        Route::post('ranking/reglas', [RankingController::class, 'configure'])->name('ranking.reglas');
        Route::post('ranking/calcular', [RankingController::class, 'calculate'])->name('ranking.calcular');
        Route::prefix('traslados')->name('traslados.')->group(function (): void {
            Route::get('/', [TrasladoController::class, 'index'])->name('index');
            Route::post('/', [TrasladoController::class, 'store'])->name('store');
            Route::get('opciones', [TrasladoController::class, 'options'])->name('opciones');
            Route::post('configuracion', [TrasladoController::class, 'configuration'])->name('configuracion');
            Route::get('{uuid}', [TrasladoController::class, 'show'])->whereUuid('uuid')->name('show');
            Route::post('{uuid}/{action}', [TrasladoController::class, 'action'])->whereUuid('uuid')->whereIn('action', ['aprobar', 'rechazar', 'cancelar', 'aplicar'])->name('action');
        });
        Route::prefix('comunicados')->name('comunicados.')->group(function (): void {
            Route::get('/', [ComunicadoController::class, 'index'])->name('index');
            Route::get('{uuid}', [ComunicadoController::class, 'show'])->whereUuid('uuid')->name('show');
            Route::post('{uuid}/lectura', [ComunicadoController::class, 'read'])->whereUuid('uuid')->name('lectura');
            Route::post('/', [ComunicadoController::class, 'store'])->name('store');
            Route::put('{uuid}', [ComunicadoController::class, 'update'])->whereUuid('uuid')->name('update');
            Route::post('{uuid}/{action}', [ComunicadoController::class, 'action'])->whereUuid('uuid')->whereIn('action', ['publicar', 'anular'])->name('action');
            Route::post('cuentas/vincular', [ComunicadoController::class, 'link'])->name('vincular');
        });
        Route::prefix('ventas')->name('ventas.')->middleware('can:administrar-ventas')->group(function (): void {
            Route::get('/', [VentasController::class, 'index'])->name('index');
            Route::post('/', [VentasController::class, 'store'])->name('store');
            Route::get('opciones', [VentasController::class, 'options'])->name('opciones');
            Route::get('movimientos', [VentasController::class, 'movements'])->name('movimientos');
            Route::post('clientes', [VentasController::class, 'customer'])->name('clientes.store');
            Route::put('clientes/{id}', [VentasController::class, 'customer'])->whereNumber('id')->name('clientes.update');
            Route::post('tarifas', [VentasController::class, 'pricing'])->name('tarifas');
            Route::post('inventario/ajustes', [VentasController::class, 'adjust'])->name('inventario.ajustes');
            Route::get('{uuid}', [VentasController::class, 'show'])->whereUuid('uuid')->name('show');
            Route::post('{uuid}/{action}', [VentasController::class, 'action'])->whereUuid('uuid')->whereIn('action', ['confirmar', 'pagar', 'anular'])->name('action');
        });
        Route::prefix('produccion')->name('produccion.')->middleware('can:administrar-produccion')->group(function (): void {
            Route::get('lotes', [ProduccionController::class, 'index'])->name('index');
            Route::post('lotes', [ProduccionController::class, 'store'])->name('store');
            Route::get('lotes/{uuid}', [ProduccionController::class, 'show'])->whereUuid('uuid')->name('show');
            Route::post('lotes/{uuid}/{action}', [ProduccionController::class, 'action'])->whereUuid('uuid')->whereIn('action', ['iniciar', 'finalizar', 'ajustar', 'anular', 'corregir'])->name('action');
            Route::post('reglas', [ProduccionController::class, 'rule'])->name('reglas');
        });
        Route::prefix('calidad')->name('calidad.')->middleware('can:operar-calidad')->group(function (): void {
            Route::get('jornadas', [CalidadController::class, 'jornadas'])->name('jornadas');
            Route::get('productores', [CalidadController::class, 'producers'])->name('productores');
            Route::post('analisis', [CalidadController::class, 'store'])->name('analisis.store');
            Route::post('sincronizar', [CalidadController::class, 'sync'])->name('sync');
            Route::get('analisis/{uuid}', [CalidadController::class, 'show'])->whereUuid('uuid')->name('analisis.show');
        });
        Route::post('/recepciones/lecturas', [RecepcionController::class, 'store'])->name('recepciones.lecturas.store');
    });
});
