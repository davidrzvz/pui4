<?php

namespace App\Filament\Widgets;

use App\Models\PuiReport;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Carbon\Carbon;

class PuiStatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;
    protected int | string | array $columnSpan = 'full';

    protected function getStats(): array
    {
        return [
            Stat::make('Solicitudes recibidas hoy', PuiReport::whereDate('created_at', Carbon::today())->count())
                ->description('Total de peticiones')
                ->descriptionIcon('heroicon-m-arrow-down-tray')
                ->color('info'),
            Stat::make('Coincidencias pendientes', PuiReport::where('match_status', 'COINCIDENCIA_SUGERIDA')->where(function($q) {
                    $q->where('government_status', 'PENDIENTE_ENVIO')->orWhereNull('government_status');
                })->where('status', '!=', 'DESACTIVADO')->count())
                ->description('Esperando validación')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),
            Stat::make('Sin coincidencia pendientes', PuiReport::where('match_status', 'SIN_COINCIDENCIA_SUGERIDA')->where(function($q) {
                    $q->where('government_status', 'PENDIENTE_ENVIO')->orWhereNull('government_status');
                })->where('status', '!=', 'DESACTIVADO')->count())
                ->description('Listas para finalizar masivo')
                ->descriptionIcon('heroicon-m-x-circle')
                ->color('danger'),
            Stat::make('Esperando desactivación', PuiReport::where('government_status', 'ENVIADO')->where('status', '!=', 'DESACTIVADO')->count())
                ->description('Enviadas a Gobierno')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),
            Stat::make('Errores envío Gobierno', PuiReport::where('government_status', 'ERROR_ENVIO')->where('status', '!=', 'DESACTIVADO')->count())
                ->description('Requieren revisión manual')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('danger'),
        ];
    }
}
