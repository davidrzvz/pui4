<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages;
use App\Models\AuditLog;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Illuminate\Database\Eloquent\Builder;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationLabel = 'Auditoría';
    protected static ?string $modelLabel = 'Registro de Auditoría';
    protected static ?string $pluralModelLabel = 'Registros de Auditoría';

    public static function canAccess(): bool
    {
        return auth()->user()->hasAnyRole(['SUPER_ADMIN', 'AUDITOR']);
    }

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
        $query = parent::getEloquentQuery();
        
        $user = auth()->user();
        if ($user->hasRole('SUPER_ADMIN')) {
            return $query;
        }

        return $query->where('institution_id', $user->institution_id);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('event')
                    ->label('Evento'),
                Forms\Components\TextInput::make('auditable_type')
                    ->label('Modelo afectado'),
                Forms\Components\KeyValue::make('old_values')
                    ->label('Valores anteriores'),
                Forms\Components\KeyValue::make('new_values')
                    ->label('Valores nuevos'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('institution.name')
                    ->label('Institución')
                    ->sortable()
                    ->placeholder('Global'),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Usuario')
                    ->sortable(),
                Tables\Columns\TextColumn::make('event')
                    ->label('Evento')
                    ->badge(),
                Tables\Columns\TextColumn::make('auditable_type')
                    ->label('Modelo Afectado')
                    ->searchable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                // Read only
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageAuditLogs::route('/'),
        ];
    }
}
