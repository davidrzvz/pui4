<?php

namespace App\Providers;

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
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Login::class, function (\Illuminate\Auth\Events\Login $event) {
            $event->user->last_login_at = now();
            $event->user->save();
        });

        $appUrl = config('app.url');
        if ($appUrl) {
            $path = parse_url($appUrl, PHP_URL_PATH);
            if ($path && $path !== '/') {
                $path = rtrim($path, '/');
                
                \Livewire\Livewire::setUpdateRoute(function ($handle) {
                    return \Illuminate\Support\Facades\Route::post('/livewire/update', $handle);
                });

                \Livewire\Livewire::setScriptRoute(function ($handle) {
                    return \Illuminate\Support\Facades\Route::get('/livewire/livewire.js', $handle);
                });

                \Illuminate\Support\Facades\URL::forceRootUrl($appUrl);
                
                if (!config('app.asset_url')) {
                    config(['app.asset_url' => $appUrl]);
                }
            }
        }
    }
}
