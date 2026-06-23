<?php

namespace App\Filament\Widgets;

use App\Models\GovernmentApiLog;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class LatestGovernmentLogs extends BaseWidget
{
    protected static ?int $sort = 3;
    protected int | string | array $columnSpan = 'full';
    protected static ?string $heading = 'Última comunicación con Gobierno';

    public function table(Table $table): Table
    {
        return $table
            ->query(GovernmentApiLog::query()->latest()->limit(5))
            ->columns([
                Tables\Columns\IconColumn::make('http_status_icon')
                    ->label('')
                    ->icon(fn ($record) => $record->http_status >= 200 && $record->http_status < 300 ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle')
                    ->color(fn ($record) => $record->http_status >= 200 && $record->http_status < 300 ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('endpoint')
                    ->label('Endpoint'),
                Tables\Columns\TextColumn::make('method')
                    ->label('Método')
                    ->badge(),
                Tables\Columns\TextColumn::make('http_status')
                    ->label('HTTP'),
                Tables\Columns\TextColumn::make('response_time_ms')
                    ->label('Ms'),
            ])
            ->paginated(false);
    }
}
