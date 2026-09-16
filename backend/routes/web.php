<?php

use App\Http\Controllers\Api\V1\LiquidacionController;
use App\Http\Controllers\Api\V1\ReporteController;
use App\Http\Controllers\Auth\SessionController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('admin.dashboard'));
Route::get('/admin', fn () => redirect()->route('admin.dashboard'));
Route::middleware('guest')->group(function (): void {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:login')->name('login.store');
});
Route::post('/logout', [SessionController::class, 'destroy'])->middleware('auth')->name('logout');
Route::livewire('/admin/dashboard', 'pages::dashboard')->middleware(['auth', 'can:ver-dashboard'])->name('admin.dashboard');
Route::livewire('/admin/usuarios', 'pages::usuarios.index')->middleware(['auth', 'can:administrar-usuarios'])->name('admin.usuarios');
Route::livewire('/admin/productores', 'pages::productores.index')->middleware(['auth', 'can:administrar-productores'])->name('admin.productores');

Route::livewire('/admin/rutas', 'pages::rutas.index')->middleware(['auth', 'can:administrar-rutas'])->name('admin.rutas');
Route::livewire('/admin/acopios', 'pages::acopios.index')->middleware(['auth', 'can:administrar-acopios'])->name('admin.acopios');
Route::livewire('/admin/recepciones', 'pages::recepciones.index')->middleware(['auth', 'can:administrar-recepciones'])->name('admin.recepciones');

Route::livewire('/admin/calidad', 'pages::calidad.index')->middleware(['auth', 'can:operar-calidad'])->name('admin.calidad');
Route::livewire('/admin/calidad/perfiles', 'pages::calidad.perfiles')->middleware(['auth', 'can:administrar-calidad'])->name('admin.calidad.perfiles');

Route::livewire('/admin/produccion', 'pages::produccion.index')->middleware(['auth', 'can:administrar-produccion'])->name('admin.produccion');

Route::livewire('/admin/ventas', 'pages::ventas.index')->middleware(['auth', 'can:administrar-ventas'])->name('admin.ventas');

Route::livewire('/admin/comunicados', 'pages::comunicados.index')->middleware(['auth', 'can:administrar-comunicados'])->name('admin.comunicados');

Route::livewire('/admin/traslados', 'pages::traslados.index')->middleware(['auth', 'can:administrar-traslados'])->name('admin.traslados');

Route::livewire('/admin/ranking', 'pages::ranking.index')->middleware(['auth', 'can:administrar-ranking'])->name('admin.ranking');
Route::livewire('/muro-de-honor', 'pages::ranking.honor')->middleware('throttle:60,1')->name('ranking.honor');

Route::livewire('/admin/penalizaciones', 'pages::penalizaciones.index')->middleware(['auth', 'can:administrar-penalizaciones'])->name('admin.penalizaciones');

Route::livewire('/admin/liquidaciones', 'pages::liquidaciones.index')->middleware(['auth', 'can:operar-liquidaciones'])->name('admin.liquidaciones');

Route::get('/admin/liquidaciones/{uuid}/comprobante', [LiquidacionController::class, 'print'])->middleware(['auth', 'can:operar-liquidaciones'])->whereUuid('uuid')->name('admin.liquidaciones.comprobante');
Route::get('/admin/liquidaciones/{uuid}/pdf', [LiquidacionController::class, 'pdf'])->middleware(['auth', 'can:operar-liquidaciones'])->whereUuid('uuid')->name('admin.liquidaciones.pdf');

Route::livewire('/admin/reportes', 'pages::reportes.index')->middleware(['auth', 'can:operar-liquidaciones'])->name('admin.reportes');
Route::get('/admin/reportes/{type}/csv', [ReporteController::class, 'csv'])->middleware(['auth', 'can:operar-liquidaciones', 'throttle:20,1'])->name('admin.reportes.csv');
