<?php

namespace App\Filament\Resources\GovernmentApiLogs;

use App\Filament\Resources\GovernmentApiLogs\Pages\ManageGovernmentApiLogs;
use App\Models\GovernmentApiLog;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Illuminate\Database\Eloquent\Builder;

class GovernmentApiLogResource extends Resource
{
    protected static ?string $model = GovernmentApiLog::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-server-stack';
    protected static ?string $navigationLabel = 'Logs API Gobierno';
    protected static ?string $modelLabel = 'Log de API';
    protected static ?string $pluralModelLabel = 'Logs API Gobierno';
    protected static string|\UnitEnum|null $navigationGroup = 'Auditoría';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->latest();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Información General')
                    ->components([
                        Grid::make(3)
                            ->components([
                                TextEntry::make('created_at')->label('Fecha')->dateTime(),
                                TextEntry::make('endpoint')->label('Endpoint'),
                                TextEntry::make('method')->label('Método')->badge(),
                                TextEntry::make('http_status')
                                    ->label('HTTP Status')
                                    ->badge()
                                    ->color(fn ($state) => $state >= 200 && $state < 300 ? 'success' : 'danger'),
                                TextEntry::make('response_time_ms')->label('Tiempo de respuesta (ms)'),
                                TextEntry::make('pui_report_id')->label('ID Reporte PUI'),
                            ])
                    ]),
                Section::make('Payload Request (Enviado)')
                    ->components([
                        TextEntry::make('request_payload')
                            ->label('')
                            ->formatStateUsing(fn ($state) => '<pre style="white-space: pre-wrap; word-break: break-all; background: #111827; color: #fff; padding: 10px; border-radius: 8px;">' . json_encode(is_string($state) ? json_decode($state, true) ?? $state : $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>')
                            ->html()
                            ->copyable()
                            ->copyMessage('Copiado al portapapeles')
                            ->copyMessageDuration(1500)
                    ]),
                Section::make('Payload Response (Recibido)')
                    ->components([
                        TextEntry::make('response_payload')
                            ->label('')
                            ->formatStateUsing(fn ($state) => $state ? '<pre style="white-space: pre-wrap; word-break: break-all; background: #111827; color: #fff; padding: 10px; border-radius: 8px;">' . json_encode(is_string($state) ? json_decode($state, true) ?? $state : $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>' : 'Sin respuesta')
                            ->html()
                            ->copyable()
                            ->copyMessage('Copiado al portapapeles')
                            ->copyMessageDuration(1500)
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                IconColumn::make('http_status_icon')
                    ->label('')
                    ->icon(fn ($record) => $record->http_status >= 200 && $record->http_status < 300 ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle')
                    ->color(fn ($record) => $record->http_status >= 200 && $record->http_status < 300 ? 'success' : 'danger'),
                TextColumn::make('created_at')->label('Fecha')->dateTime()->sortable(),
                TextColumn::make('endpoint')->label('Endpoint')->searchable(),
                TextColumn::make('method')->label('Método')->badge(),
                TextColumn::make('http_status')->label('HTTP')->sortable(),
                TextColumn::make('response_time_ms')->label('Ms')->sortable(),
                TextColumn::make('pui_report_id')->label('Reporte PUI')->searchable()->limit(8),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageGovernmentApiLogs::route('/'),
        ];
    }
}
