<?php

namespace App\Providers;

use App\Domain\Acopios\AcopioRepository;
use App\Domain\Dashboard\DashboardRepository;
use App\Domain\Recepciones\RecepcionRepository;
use App\Domain\Rutas\RutaRepository;
use App\Domain\Usuarios\UsuarioRepository;
use App\Http\Middleware\EnsureActiveUser;
use App\Infrastructure\Acopios\EloquentAcopioRepository;
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
        Gate::define('administrar-usuarios', fn (User $user): bool => $user->isActiveAdministrator());
        Gate::define('administrar-rutas', fn (User $user): bool => $user->isActiveAdministrator());
        Gate::define('administrar-acopios', fn (User $user): bool => $user->isActiveAdministrator());
        Gate::define('administrar-recepciones', fn (User $user): bool => $user->isActiveAdministrator());
        Gate::define('registrar-recepcion', fn (User $user): bool => $user->active && ($user->isActiveAdministrator() || $user->hasRole('recolector', 'web')));
        Gate::define('ver-dashboard', fn (User $user): bool => $user->isActiveAdministrator());
        Livewire::addPersistentMiddleware([EnsureActiveUser::class]);
    }
}
