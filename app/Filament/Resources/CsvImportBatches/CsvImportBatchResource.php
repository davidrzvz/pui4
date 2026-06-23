<?php

namespace App\Filament\Resources\CsvImportBatches;

use App\Filament\Resources\CsvImportBatches\Pages;
use App\Models\CsvImportBatch;
use App\Services\CsvImportService;
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
use Illuminate\Database\Eloquent\Model;

class CsvImportBatchResource extends Resource
{
    protected static ?string $model = CsvImportBatch::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-document-arrow-up';
    protected static ?string $navigationLabel = 'Importación de CURP';
    protected static ?string $modelLabel = 'Lote de Importación';
    protected static ?string $pluralModelLabel = 'Lotes de Importación';

    public static function canAccess(): bool
    {
        return auth()->user()->hasAnyRole(['SUPER_ADMIN', 'ADMINISTRADOR', 'OPERADOR', 'AUDITOR']);
    }

    public static function canCreate(): bool
    {
        return auth()->user()->hasAnyRole(['SUPER_ADMIN', 'ADMINISTRADOR', 'OPERADOR']);
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
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
        $user = auth()->user();

        return $schema
            ->components([
                Forms\Components\Select::make('institution_id')
                    ->label('Institución')
                    ->relationship('institution', 'name')
                    ->required(fn () => $user->hasRole('SUPER_ADMIN'))
                    ->visible(fn () => $user->hasRole('SUPER_ADMIN'))
                    ->disabled(fn () => !$user->hasRole('SUPER_ADMIN'))
                    ->dehydrated(),
                
                Forms\Components\Radio::make('import_mode')
                    ->label('Tipo de carga')
                    ->options([
                        'append' => 'Aumentar padrón actual',
                        'replace' => 'Reemplazar padrón completo',
                    ])
                    ->descriptions([
                        'append' => 'Agrega nuevos clientes y actualiza existentes. No desactiva registros previos.',
                        'replace' => 'Este archivo será considerado el padrón completo vigente. Los clientes que no estén en el CSV quedarán inactivos.',
                    ])
                    ->default('append')
                    ->required()
                    ->live(),

                Forms\Components\Checkbox::make('confirm_replace')
                    ->label('Confirmo que este archivo representa el 100% del padrón actual.')
                    ->required(fn ($get) => $get('import_mode') === 'replace')
                    ->visible(fn ($get) => $get('import_mode') === 'replace')
                    ->dehydrated(false),

                Forms\Components\FileUpload::make('filename')
                    ->label('Archivo CSV')
                    ->acceptedFileTypes(['text/csv', 'text/plain'])
                    ->disk('public')
                    ->directory('csv_imports')
                    ->required()
                    ->columnSpanFull()
                    ->helperText('Al finalizar la importación, PUI analizará nuevamente las solicitudes abiertas del Gobierno.'),
                    
                Forms\Components\KeyValue::make('error_summary')
                    ->label('Resumen de Errores')
                    ->visibleOn('view')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha de Carga')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('institution.name')
                    ->label('Institución')
                    ->sortable()
                    ->placeholder('N/A'),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Usuario')
                    ->sortable(),
                Tables\Columns\TextColumn::make('filename')
                    ->label('Archivo')
                    ->formatStateUsing(fn ($state) => basename($state)),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('Estado')
                    ->colors([
                        'warning' => 'PENDIENTE',
                        'info' => 'PROCESANDO',
                        'success' => 'COMPLETADO',
                        'danger' => 'ERROR',
                    ]),
                Tables\Columns\BadgeColumn::make('import_mode')
                    ->label('Tipo de carga')
                    ->colors([
                        'primary' => 'append',
                        'danger' => 'replace',
                    ])
                    ->formatStateUsing(fn ($state) => $state === 'append' ? 'Aumentar' : 'Reemplazar'),
                Tables\Columns\TextColumn::make('total_records')
                    ->label('Total'),
                Tables\Columns\TextColumn::make('processed_records')
                    ->label('Exitosos')
                    ->color('success'),
                Tables\Columns\TextColumn::make('failed_records')
                    ->label('Errores')
                    ->color('danger'),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                //
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageCsvImportBatches::route('/'),
        ];
    }
}
