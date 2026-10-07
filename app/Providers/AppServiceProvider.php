<?php

namespace App\Providers;

use App\Models\User;
use App\Models\Weighing;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('admin', fn (User $user): bool => $user->isAdmin());

        // Badge jumlah timbangan hari ini di sidebar (semua halaman layouts/app).
        View::composer('layouts.app', function ($view): void {
            $view->with('todayCount', Weighing::whereDate('weighed_at', today())->count());
        });
    }
}
