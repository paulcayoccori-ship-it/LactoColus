<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Productores\ProductorRepository;
use App\Infrastructure\Productores\EloquentProductorRepository;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class ProductorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProductorRepository::class, EloquentProductorRepository::class);
    }

    public function boot(): void
    {
        Gate::define('administrar-productores', fn (User $user): bool => $user->hasRole('administrador', 'web'));
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));
    }
}
