<?php

namespace App\Filament\Resources\PuiReports;

use App\Filament\Resources\PuiReports\Pages\ManagePuiReports;
use App\Models\PuiReport;
use App\Models\ClientRecord;
use App\Models\PuiReportMatchCheck;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
// No Infolist imports needed for v5

class PuiReportResource extends Resource
{
    protected static ?string $model = PuiReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;
    protected static ?string $navigationLabel = 'Solicitudes PUI';
    protected static ?string $pluralModelLabel = 'Solicitudes PUI';
    protected static ?string $modelLabel = 'Solicitud PUI';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getRecordTitle(?\Illuminate\Database\Eloquent\Model $record): string
    {
        return $record ? "Solicitud PUI | CURP: {$record->curp} | ID Gobierno: {$record->external_id}" : parent::getRecordTitle($record);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        if (!auth()->user()->hasRole('SUPER_ADMIN')) {
            $query->where('institution_id', auth()->user()->institution_id);
        }
        return $query->orderByRaw("
            CASE 
                WHEN status = 'PENDIENTE_REVISION' THEN 1 
                WHEN status = 'DESACTIVADO' THEN 2 
                ELSE 3 
            END
        ")->orderBy('activated_at', 'desc');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                //
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                \Filament\Schemas\Components\View::make('filament.resources.pui-report.detail')
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('activated_at')->label('Fecha solicitud')->dateTime()->sortable(),
                TextColumn::make('institution.name')->label('Institución')->visible(fn() => auth()->user()->hasRole('SUPER_ADMIN')),
                TextColumn::make('external_id')->label('ID Gobierno')->searchable(),
                TextColumn::make('curp')->label('CURP')->searchable(),
                TextColumn::make('is_test')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'Solicitud de prueba' : 'Solicitud real')
                    ->color(fn ($state) => $state ? 'warning' : 'success'),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match($state) {
                        'PENDIENTE_REVISION' => 'Pendiente de revisión',
                        'DESACTIVADO' => 'Desactivado por Gobierno',
                        default => $state,
                    })
                    ->color(fn ($state) => match($state) {
                        'PENDIENTE_REVISION' => 'warning',
                        'DESACTIVADO' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('match_status')
                    ->label('Coincidencia')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match($state) {
                        'COINCIDENCIA_SUGERIDA' => 'Coincidencia sugerida',
                        'SIN_COINCIDENCIA_SUGERIDA' => 'Sin coincidencia sugerida',
                        null => 'Pendiente de evaluación',
                        default => $state,
                    })
                    ->color(fn ($state) => match($state) {
                        'COINCIDENCIA_SUGERIDA' => 'success',
                        'SIN_COINCIDENCIA_SUGERIDA' => 'danger',
                        null => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('clientRecord.internal_identifier')->label('Cliente interno')->default('N/A'),
                TextColumn::make('csvImportBatch.id')->label('Lote CSV')->limit(8)->default('N/A'),
                TextColumn::make('deactivated_at')->label('Fecha desactivación')->dateTime(),
            ])
            ->filters([
                SelectFilter::make('is_test')
                    ->label('Tipo')
                    ->options([
                        '1' => 'Solicitud de prueba',
                        '0' => 'Solicitud real',
                    ]),
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        'PENDIENTE_REVISION' => 'Pendiente de revisión',
                        'DESACTIVADO' => 'Desactivado por Gobierno',
                    ]),
                SelectFilter::make('match_status')
                    ->label('Coincidencia')
                    ->options([
                        'COINCIDENCIA_SUGERIDA' => 'Coincidencia sugerida',
                        'SIN_COINCIDENCIA_SUGERIDA' => 'Sin coincidencia sugerida',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('reevaluar')
                    ->label('Re-evaluar coincidencia')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('warning')
                    ->visible(fn(PuiReport $record) => !auth()->user()->hasRole('AUDITOR') && $record->status !== 'DESACTIVADO')
                    ->action(function (PuiReport $record) {
                        $clientRecord = ClientRecord::where('institution_id', $record->institution_id)
                            ->where('curp', $record->curp)
                            ->first();

                        $matchStatus = $clientRecord ? 'COINCIDENCIA_SUGERIDA' : 'SIN_COINCIDENCIA_SUGERIDA';

                        $record->update([
                            'match_status' => $matchStatus,
                            'client_record_id' => $clientRecord ? $clientRecord->id : null,
                            'matched_csv_import_batch_id' => $clientRecord ? $clientRecord->csv_import_batch_id : null,
                            'match_checked_at' => now(),
                        ]);

                        PuiReportMatchCheck::create([
                            'pui_report_id' => $record->id,
                            'institution_id' => $record->institution_id,
                            'csv_import_batch_id' => $clientRecord ? $clientRecord->csv_import_batch_id : null,
                            'client_record_id' => $clientRecord ? $clientRecord->id : null,
                            'match_status' => $matchStatus,
                            'checked_at' => now(),
                            'checked_by' => auth()->id(),
                            'notes' => 'Reevaluación manual desde panel',
                        ]);

                        Notification::make()
                            ->title('Re-evaluación completada')
                            ->success()
                            ->send();
                    })
            ])
            ->toolbarActions([
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePuiReports::route('/'),
        ];
    }
}
