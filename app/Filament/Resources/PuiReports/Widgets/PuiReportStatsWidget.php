<?php

namespace App\Filament\Resources\PuiReports\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

use App\Models\PuiReport;

class PuiReportStatsWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $user = auth()->user();
        $query = PuiReport::query();

        if (!$user->hasRole('SUPER_ADMIN')) {
            $query->where('institution_id', $user->institution_id);
        }

        $pendientes = (clone $query)->where('status', 'PENDIENTE_REVISION')->count();
        $coincidencias = (clone $query)->where('match_status', 'COINCIDENCIA_SUGERIDA')->count();
        $sinCoincidencia = (clone $query)->where('match_status', 'SIN_COINCIDENCIA_SUGERIDA')->count();
        $enviados = (clone $query)->where('government_status', 'ENVIADO')->count();
        $errores = (clone $query)->where('government_status', 'ERROR_ENVIO')->count();

        return [
            Stat::make('Pendientes', $pendientes)
                ->description('Pendientes de revisión')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),
            Stat::make('Coincidencias pendientes', $coincidencias)
                ->description('Posibles positivos')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),
            Stat::make('Sin coincidencia', $sinCoincidencia)
                ->description('Evaluados como negativos')
                ->descriptionIcon('heroicon-m-x-circle')
                ->color('danger'),
            Stat::make('Enviados Gobierno', $enviados)
                ->description('Transmitidos exitosamente')
                ->descriptionIcon('heroicon-m-paper-airplane')
                ->color('success'),
            Stat::make('Errores Gobierno', $errores)
                ->description('Fallos de envío')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('danger'),
        ];
    }
}
