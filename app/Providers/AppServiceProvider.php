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

        \Filament\Support\Facades\FilamentView::registerRenderHook(
            'panels::auth.login.form.before',
            fn (): string => \Illuminate\Support\Facades\Blade::render('
                @php
                    $rfc = env("PUI_INSTITUTION_RFC", env("PUI_RFC"));
                    $company = env("PUI_INSTITUTION_NAME");
                @endphp
                @if($rfc && $company)
                    <div class="mb-6 text-center">
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">RFC:</p>
                        <p class="text-lg font-bold text-gray-950 dark:text-white mb-2">{{ $rfc }}</p>
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Empresa:</p>
                        <p class="text-md text-gray-950 dark:text-white">{{ $company }}</p>
                    </div>
                @endif
            ')
        );

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

                // FIX LIVEWIRE DUPLICATION BUG
                \Illuminate\Support\Facades\Event::listen(\Illuminate\Foundation\Http\Events\RequestHandled::class, function ($event) use ($path) {
                    $response = $event->response;
                    if (method_exists($response, 'getContent') && method_exists($response, 'setContent')) {
                        $content = $response->getContent();
                        if (is_string($content)) {
                            $duplicatedPath = $path . $path . '/livewire/';
                            $correctPath = $path . '/livewire/';
                            if (str_contains($content, $duplicatedPath)) {
                                $response->setContent(str_replace($duplicatedPath, $correctPath, $content));
                            }
                        }
                    }
                });
            }
        }
    }
}
