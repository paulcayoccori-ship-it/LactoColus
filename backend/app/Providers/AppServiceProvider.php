<?php

namespace App\Providers;

use App\Domain\Acopios\AcopioRepository;
use App\Domain\Calidad\CalidadRepository;
use App\Domain\Calidad\CorrectorDensidad;
use App\Domain\Dashboard\DashboardRepository;
use App\Domain\Recepciones\RecepcionRepository;
use App\Domain\Rutas\RutaRepository;
use App\Domain\Usuarios\UsuarioRepository;
use App\Http\Middleware\EnsureActiveUser;
use App\Infrastructure\Acopios\EloquentAcopioRepository;
use App\Infrastructure\Calidad\DensidadSinFormula;
use App\Infrastructure\Calidad\EloquentCalidadRepository;
use App\Infrastructure\Dashboard\EloquentDashboardRepository;
use App\Infrastructure\Recepciones\EloquentRecepcionRepository;
use App\Infrastructure\Rutas\EloquentRutaRepository;
use App\Infrastructure\Usuarios\EloquentUsuarioRepository;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(CalidadRepository::class, EloquentCalidadRepository::class);
        $this->app->bind(CorrectorDensidad::class, DensidadSinFormula::class);
        $this->app->bind(AcopioRepository::class, EloquentAcopioRepository::class);
        $this->app->bind(RutaRepository::class, EloquentRutaRepository::class);
        $this->app->bind(RecepcionRepository::class, EloquentRecepcionRepository::class);
        $this->app->bind(UsuarioRepository::class, EloquentUsuarioRepository::class);
        $this->app->bind(DashboardRepository::class, EloquentDashboardRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('administrar-liquidaciones', fn (User $user): bool => $user->isActiveAdministrator());
        Gate::define('operar-liquidaciones', fn (User $user): bool => $user->isActiveAdministrator() || User::whereKey($user->id)->where('active', true)->whereHas('roles', fn ($q) => $q->where('name', 'contador')->where('guard_name', 'web'))->exists());
        Gate::define('administrar-penalizaciones', fn (User $user): bool => $user->isActiveAdministrator());
        Gate::define('administrar-ranking', fn (User $user): bool => $user->isActiveAdministrator());
        Gate::define('administrar-traslados', fn (User $user): bool => $user->isActiveAdministrator());
        Gate::define('administrar-comunicados', fn (User $user): bool => $user->isActiveAdministrator());
        Gate::define('api-productores-existente', fn (User $user): bool => ! $user->hasRole('productor', 'web') || $user->hasAnyRole(['administrador', 'supervisor', 'recolector', 'contador', 'calidad']));
        Gate::define('administrar-ventas', fn (User $user): bool => $user->isActiveAdministrator());
        Gate::define('administrar-produccion', fn (User $user): bool => $user->isActiveAdministrator());
        Gate::define('administrar-calidad', fn (User $user): bool => $user->isActiveAdministrator());
        Gate::define('operar-calidad', fn (User $user): bool => $user->isActiveAdministrator() || User::whereKey($user->id)->where('active', true)->role('calidad', 'web')->exists());
        Gate::define('administrar-usuarios', fn (User $user): bool => $user->isActiveAdministrator());
        Gate::define('administrar-rutas', fn (User $user): bool => $user->isActiveAdministrator());
        Gate::define('administrar-acopios', fn (User $user): bool => $user->isActiveAdministrator());
        Gate::define('administrar-recepciones', fn (User $user): bool => $user->isActiveAdministrator());
        Gate::define('registrar-recepcion', fn (User $user): bool => $user->active && ($user->isActiveAdministrator() || $user->hasRole('recolector', 'web')));
        Gate::define('ver-dashboard', fn (User $user): bool => $user->isActiveAdministrator());
        Livewire::addPersistentMiddleware([EnsureActiveUser::class]);
    }
}
