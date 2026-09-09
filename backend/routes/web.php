<?php

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
