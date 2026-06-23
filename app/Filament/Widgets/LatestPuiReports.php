<?php

namespace App\Filament\Widgets;

use App\Models\PuiReport;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class LatestPuiReports extends BaseWidget
{
    protected static ?int $sort = 2;
    protected int | string | array $columnSpan = 'full';
    protected static ?string $heading = 'Últimas solicitudes recibidas';

    public function table(Table $table): Table
    {
        return $table
            ->query(PuiReport::query()->latest()->limit(5))
            ->columns([
                Tables\Columns\TextColumn::make('activated_at')
                    ->label('Fecha')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('curp')
                    ->label('CURP'),
                Tables\Columns\TextColumn::make('match_status')
                    ->label('Resultado motor')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match($state) {
                        'COINCIDENCIA_SUGERIDA' => 'Coincidencia',
                        'SIN_COINCIDENCIA_SUGERIDA' => 'Sin coincidencia',
                        null => 'Pendiente',
                        default => $state,
                    })
                    ->color(fn ($state) => match($state) {
                        'COINCIDENCIA_SUGERIDA' => 'success',
                        'SIN_COINCIDENCIA_SUGERIDA' => 'danger',
                        null => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match($state) {
                        'PENDIENTE_REVISION' => 'Pendiente revisión',
                        'FINALIZADO' => 'Finalizado',
                        'DESACTIVADO' => 'Desactivado',
                        default => $state,
                    })
                    ->color(fn ($state) => match($state) {
                        'PENDIENTE_REVISION' => 'warning',
                        'FINALIZADO' => 'success',
                        'DESACTIVADO' => 'gray',
                        default => 'gray',
                    }),
            ])
            ->paginated(false);
    }
}
