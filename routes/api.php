<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PuiApiController;
use App\Http\Middleware\PuiApiAuthMiddleware;

Route::prefix('v1/pui')->group(function () {
    Route::post('/login', [PuiApiController::class, 'login']);

    Route::middleware(PuiApiAuthMiddleware::class)->group(function () {
        Route::post('/activar-reporte', [PuiApiController::class, 'activarReporte']);
        Route::post('/activar-reporte-prueba', [PuiApiController::class, 'activarReportePrueba']);
        Route::post('/desactivar-reporte', [PuiApiController::class, 'desactivarReporte']);
    });
});
