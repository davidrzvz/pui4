<?php

namespace App\Filament\Resources\ClientRecords;

use App\Filament\Resources\ClientRecords\Pages\CreateClientRecord;
use App\Filament\Resources\ClientRecords\Pages\EditClientRecord;
use App\Filament\Resources\ClientRecords\Pages\ListClientRecords;
use App\Filament\Resources\ClientRecords\Schemas\ClientRecordForm;
use App\Filament\Resources\ClientRecords\Tables\ClientRecordsTable;
use App\Models\ClientRecord;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ClientRecordResource extends Resource
{
    protected static ?string $model = ClientRecord::class;

    protected static ?string $navigationLabel = 'Padrón interno';
    protected static ?string $modelLabel = 'Cliente';
    protected static ?string $pluralModelLabel = 'Padrón de Clientes';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    public static function canAccess(): bool
    {
        return auth()->user()->hasAnyRole(['SUPER_ADMIN', 'ADMINISTRADOR', 'OPERADOR', 'AUDITOR']);
    }

    public static function canCreate(): bool
    {
        return auth()->user()->hasAnyRole(['SUPER_ADMIN', 'ADMINISTRADOR', 'OPERADOR']);
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return auth()->user()->hasAnyRole(['SUPER_ADMIN', 'ADMINISTRADOR', 'OPERADOR']);
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return auth()->user()->hasAnyRole(['SUPER_ADMIN', 'ADMINISTRADOR']);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getEloquentQuery();
        
        if (app()->has('current_institution')) {
            $institution = app('current_institution');
            return $query->where('institution_id', $institution->id);
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return ClientRecordForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClientRecordsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClientRecords::route('/'),
            'create' => CreateClientRecord::route('/create'),
            'view' => Pages\ViewClientRecord::route('/{record}'),
            'edit' => EditClientRecord::route('/{record}/edit'),
        ];
    }
}
