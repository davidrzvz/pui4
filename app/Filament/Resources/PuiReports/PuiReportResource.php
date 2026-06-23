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
use App\Services\GovernmentApiService;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Placeholder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
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
                TextColumn::make('external_id')
                    ->label('ID Gobierno')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('curp')
                    ->label('Persona')
                    ->searchable()
                    ->formatStateUsing(fn ($state, $record) => $record->clientRecord?->name ?? 'Sin nombre registrado')
                    ->description(fn ($record) => $record->curp),
                IconColumn::make('is_test')
                    ->label('Tipo')
                    ->icon(fn ($state) => $state ? 'heroicon-o-beaker' : 'heroicon-o-check-circle')
                    ->color(fn ($state) => $state ? 'warning' : 'success')
                    ->tooltip(fn ($state) => $state ? 'Solicitud de prueba' : 'Solicitud real'),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match($state) {
                        'PENDIENTE_REVISION' => 'Pendiente de revisión',
                        'FINALIZADO' => 'Finalizado',
                        'DESACTIVADO' => 'Desactivado por Gobierno',
                        default => $state,
                    })
                    ->color(fn ($state) => match($state) {
                        'PENDIENTE_REVISION' => 'warning',
                        'FINALIZADO' => 'success',
                        'DESACTIVADO' => 'gray',
                        default => 'gray',
                    }),
                IconColumn::make('government_status')
                    ->label('Gobierno')
                    ->icon(fn ($state) => match($state) {
                        'PENDIENTE_ENVIO' => 'heroicon-o-clock',
                        'ENVIADO' => 'heroicon-o-check',
                        'ERROR_ENVIO' => 'heroicon-o-exclamation-triangle',
                        default => 'heroicon-o-minus',
                    })
                    ->color(fn ($state) => match($state) {
                        'PENDIENTE_ENVIO' => 'warning',
                        'ENVIADO' => 'success',
                        'ERROR_ENVIO' => 'danger',
                        default => 'gray',
                    })
                    ->tooltip(fn ($state, $record) => match($state) {
                        'PENDIENTE_ENVIO' => 'Pendiente de envío',
                        'ENVIADO' => 'Enviado correctamente',
                        'ERROR_ENVIO' => 'Error de envío: ' . ($record->government_error ?? ''),
                        default => $state ?? 'No reportado',
                    }),
                TextColumn::make('match_status')
                    ->label('Coincidencia')
                    ->badge()
                    ->formatStateUsing(fn ($state, $record) => match($state) {
                        'COINCIDENCIA_SUGERIDA' => 'Coincidencia: ' . ($record->clientRecord->internal_identifier ?? ''),
                        'SIN_COINCIDENCIA_SUGERIDA' => 'Sin coincidencia',
                        null => 'Pendiente',
                        default => $state,
                    })
                    ->color(fn ($state) => match($state) {
                        'COINCIDENCIA_SUGERIDA' => 'success',
                        'SIN_COINCIDENCIA_SUGERIDA' => 'danger',
                        null => 'warning',
                        default => 'gray',
                    })
                    ->tooltip(fn ($state) => match($state) {
                        'COINCIDENCIA_SUGERIDA' => 'El padrón indica una posible coincidencia para esta solicitud.',
                        'SIN_COINCIDENCIA_SUGERIDA' => 'No se encontró coincidencia en el padrón actual.',
                        null => 'Aún no se ha evaluado.',
                        default => '',
                    }),
                TextColumn::make('csvImportBatch.id')
                    ->label('Lote CSV')
                    ->limit(8)
                    ->default('N/A')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deactivated_at')
                    ->label('Fecha desactivación')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                SelectFilter::make('government_status')
                    ->label('Estado Gobierno')
                    ->options([
                        'PENDIENTE_ENVIO' => 'Pendiente envío',
                        'ENVIADO' => 'Enviado',
                        'ERROR_ENVIO' => 'Error envío',
                    ]),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Revisar')
                    ->button()
                    ->extraModalFooterActions([
                        Action::make('enviarCoincidencia')
                            ->label('Notificar coincidencia a Gobierno')
                            ->icon(Heroicon::OutlinedPaperAirplane)
                            ->color('success')
                            ->visible(fn(PuiReport $record) => !$record->isClosed() && $record->match_status === 'COINCIDENCIA_SUGERIDA' && $record->client_record_id && $record->government_status !== 'ENVIADO' && $record->status !== 'DESACTIVADO' && in_array(auth()->user()->roles->first()->name ?? '', ['SUPER_ADMIN', 'ADMINISTRADOR', 'OPERADOR']))
                            ->fillForm(function (PuiReport $record) {
                                $requestPayload = is_array($record->request_payload) ? $record->request_payload : json_decode($record->request_payload, true);
                                $client = $record->clientRecord;
                                
                                return [
                                    'id' => (string) Str::uuid(),
                                    'institucion_id' => $record->institution->rfc ?? '',
                                    'curp' => $record->curp,
                                    'lugar_nacimiento' => $requestPayload['lugar_nacimiento'] ?? null,
                                    'nombre' => $client->name ?? null,
                                ];
                            })
                            ->form([
                                Section::make('Datos principales')
                                    ->schema([
                                        TextInput::make('id')
                                            ->label('ID (UUID)')
                                            ->required()
                                            ->minLength(36)
                                            ->maxLength(75)
                                            ->disabled()
                                            ->dehydrated(true),
                                        TextInput::make('institucion_id')
                                            ->label('ID Institución')
                                            ->required()
                                            ->minLength(4)
                                            ->maxLength(13)
                                            ->regex('/^[A-Z0-9]{4,13}$/')
                                            ->disabled()
                                            ->dehydrated(true),
                                        TextInput::make('curp')
                                            ->label('CURP')
                                            ->required()
                                            ->regex('/^[A-Z0-9]{18}$/')
                                            ->disabled()
                                            ->dehydrated(true),
                                        Select::make('fase_busqueda')
                                            ->label('Fase de búsqueda')
                                            ->required()
                                            ->options([
                                                '1' => 'Fase 1 - Búsqueda por datos básicos',
                                                '2' => 'Fase 2 - Búsqueda histórica',
                                                '3' => 'Fase 3 - Búsqueda continua',
                                            ])
                                            ->default('1')
                                            ->rule('regex:/^[1-3]$/')
                                            ->helperText('La fase indica la etapa del proceso de búsqueda donde se detectó la coincidencia.'),
                                    ])->columns(2),

                                Section::make('Datos de la persona')
                                    ->schema([
                                        TextInput::make('nombre')
                                            ->label('Nombre(s)')
                                            ->maxLength(50),
                                        TextInput::make('primer_apellido')
                                            ->label('Primer Apellido')
                                            ->maxLength(50),
                                        TextInput::make('segundo_apellido')
                                            ->label('Segundo Apellido')
                                            ->maxLength(50),
                                        DatePicker::make('fecha_nacimiento')
                                            ->label('Fecha de Nacimiento')
                                            ->format('Y-m-d'),
                                        TextInput::make('lugar_nacimiento')
                                            ->label('Lugar de Nacimiento')
                                            ->required()
                                            ->maxLength(20),
                                        Select::make('sexo_asignado')
                                            ->label('Sexo asignado')
                                            ->options([
                                                'H' => 'Hombre',
                                                'M' => 'Mujer',
                                                'X' => 'No binario / Otro',
                                            ]),
                                        TextInput::make('telefono')
                                            ->label('Teléfono')
                                            ->maxLength(15),
                                        TextInput::make('correo')
                                            ->label('Correo Electrónico')
                                            ->email()
                                            ->maxLength(50),
                                    ])->columns(2)->collapsed(),

                                Section::make('Domicilio persona')
                                    ->schema([
                                        TextInput::make('direccion_persona')
                                            ->label('Dirección Completa')
                                            ->maxLength(500)
                                            ->columnSpanFull(),
                                        TextInput::make('calle_persona')
                                            ->label('Calle')
                                            ->maxLength(50),
                                        TextInput::make('numero_persona')
                                            ->label('Número')
                                            ->maxLength(20),
                                        TextInput::make('colonia_persona')
                                            ->label('Colonia')
                                            ->maxLength(50),
                                        TextInput::make('codigo_postal_persona')
                                            ->label('Código Postal')
                                            ->maxLength(5),
                                        TextInput::make('municipio_persona')
                                            ->label('Municipio o Alcaldía')
                                            ->maxLength(100),
                                        TextInput::make('entidad_persona')
                                            ->label('Entidad Federativa')
                                            ->maxLength(40),
                                    ])->columns(2)->collapsed(),

                                Section::make('Datos del evento')
                                    ->schema([
                                        TextInput::make('tipo_evento')
                                            ->label('Tipo de evento')
                                            ->maxLength(500),
                                        DatePicker::make('fecha_evento')
                                            ->label('Fecha del evento')
                                            ->format('Y-m-d'),
                                        Textarea::make('descripcion_lugar_evento')
                                            ->label('Descripción del lugar')
                                            ->maxLength(500)
                                            ->columnSpanFull(),
                                    ])->columns(2)->collapsed(),

                                Section::make('Domicilio del evento')
                                    ->schema([
                                        TextInput::make('direccion_evento_desc')
                                            ->label('Dirección Completa (Evento)')
                                            ->maxLength(500)
                                            ->columnSpanFull(),
                                        TextInput::make('calle_evento')
                                            ->label('Calle')
                                            ->maxLength(50),
                                        TextInput::make('numero_evento')
                                            ->label('Número')
                                            ->maxLength(20),
                                        TextInput::make('colonia_evento')
                                            ->label('Colonia')
                                            ->maxLength(50),
                                        TextInput::make('codigo_postal_evento')
                                            ->label('Código Postal')
                                            ->maxLength(5),
                                        TextInput::make('municipio_evento')
                                            ->label('Municipio o Alcaldía')
                                            ->maxLength(100),
                                        TextInput::make('entidad_evento')
                                            ->label('Entidad Federativa')
                                            ->maxLength(40),
                                    ])->columns(2)->collapsed(),

                                Section::make('Biométricos')
                                    ->schema([
                                        Textarea::make('fotos')
                                            ->label('Fotos (JSON)')
                                            ->helperText('Opcional. Datos en formato JSON o base64.'),
                                        TextInput::make('formato_fotos')
                                            ->label('Formato fotos')
                                            ->maxLength(20),
                                        Textarea::make('huellas')
                                            ->label('Huellas (JSON)')
                                            ->helperText('Opcional.'),
                                        TextInput::make('formato_huellas')
                                            ->label('Formato huellas')
                                            ->maxLength(50),
                                    ])->columns(2)->collapsed(),

                                Section::make('Confirmación final')
                                    ->schema([
                                        Placeholder::make('resumen_final')
                                            ->label('Confirmar envío oficial a Gobierno')
                                            ->content(function ($get) {
                                                $nombreCompleto = trim($get('nombre') . ' ' . $get('primer_apellido') . ' ' . $get('segundo_apellido'));
                                                return new \Illuminate\Support\HtmlString(
                                                    '<div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg text-sm space-y-2">' .
                                                    '<p class="text-warning-600 dark:text-warning-400 font-semibold mb-3">Está a punto de notificar una coincidencia mediante el endpoint 7.2. Verifique que la información sea correcta antes de continuar.</p>' .
                                                    '<p><strong>CURP:</strong> ' . ($get('curp') ?: 'N/A') . '</p>' .
                                                    '<p><strong>Nombre completo:</strong> ' . ($nombreCompleto ?: 'N/A') . '</p>' .
                                                    '<p><strong>ID reporte Gobierno:</strong> ' . ($get('id') ?: 'N/A') . '</p>' .
                                                    '<p><strong>Institución:</strong> ' . ($get('institucion_id') ?: 'N/A') . '</p>' .
                                                    '<p><strong>Fase búsqueda:</strong> Fase ' . ($get('fase_busqueda') ?: 'N/A') . '</p>' .
                                                    '</div>'
                                                );
                                            }),
                                        \Filament\Forms\Components\Checkbox::make('confirmar_envio')
                                            ->label('Confirmo que la información es correcta y deseo notificar a Gobierno.')
                                            ->accepted()
                                            ->required()
                                            ->dehydrated(false),
                                    ]),
                            ])
                            ->action(function (PuiReport $record, array $data) {
                                $service = app(GovernmentApiService::class);
                                $success = $service->sendCoincidence($record, auth()->id(), $data);
                                
                                if ($success) {
                                    Notification::make()
                                        ->title('Coincidencia enviada a Gobierno correctamente')
                                        ->success()
                                        ->send();
                                } else {
                                    Notification::make()
                                        ->title('Error al enviar coincidencia a Gobierno')
                                        ->body($record->government_error ?? 'Error desconocido')
                                        ->danger()
                                        ->send();
                                }
                            }),
                        Action::make('finalizarSinCoincidencia')
                            ->label('Finalizar sin coincidencia')
                            ->icon(Heroicon::OutlinedXCircle)
                            ->color('danger')
                            ->visible(fn(PuiReport $record) => !$record->isClosed() && in_array(auth()->user()->roles->first()->name ?? '', ['SUPER_ADMIN', 'ADMINISTRADOR', 'OPERADOR']))
                            ->requiresConfirmation()
                            ->modalDescription('Esta acción notificará al Gobierno que la solicitud finalizó sin coincidencia. Esta acción quedará registrada en auditoría.')
                            ->action(function (PuiReport $record) {
                                $service = app(GovernmentApiService::class);
                                $success = $service->finishSearch($record);
                                
                                if ($success) {
                                    Notification::make()
                                        ->title('Búsqueda finalizada y notificada a Gobierno correctamente')
                                        ->success()
                                        ->send();
                                } else {
                                    Notification::make()
                                        ->title('Error al finalizar búsqueda en Gobierno')
                                        ->body($record->government_error ?? 'Error desconocido')
                                        ->danger()
                                        ->send();
                                }
                            }),
                    ]),
            ])
            ->toolbarActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\BulkAction::make('finalizarSinCoincidenciaMasivo')
                    ->label('Finalizar seleccionadas sin coincidencia')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Finalizar solicitudes sin coincidencia')
                    ->modalDescription(fn (\Illuminate\Database\Eloquent\Collection $records) => new HtmlString(
                        'Esta acción notificará al Gobierno que las solicitudes seleccionadas finalizaron sin coincidencia. No se enviará información de coincidencia. <strong class="text-danger-600">Esta acción quedará registrada en auditoría.</strong><br><br>' .
                        '<strong>Total seleccionadas:</strong> ' . $records->count() . '<br>' .
                        '<strong>Válidas para finalizar:</strong> ' . $records->filter(fn ($r) => 
                            $r->match_status === 'SIN_COINCIDENCIA_SUGERIDA'
                            && $r->status === 'PENDIENTE_REVISION'
                            && $r->government_status !== 'ENVIADO'
                            && $r->status !== 'DESACTIVADO'
                        )->count() . '<br>' .
                        '<strong>No aplicables:</strong> ' . $records->filter(fn ($r) => 
                            $r->match_status !== 'SIN_COINCIDENCIA_SUGERIDA'
                            || $r->status !== 'PENDIENTE_REVISION'
                            || $r->government_status === 'ENVIADO'
                            || $r->status === 'DESACTIVADO'
                        )->count()
                    ))
                    ->modalSubmitActionLabel('Confirmar finalización')
                    ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                        $service = app(GovernmentApiService::class);
                        
                        $validRecords = $records->filter(fn ($record) => 
                            $record->match_status === 'SIN_COINCIDENCIA_SUGERIDA'
                            && $record->status === 'PENDIENTE_REVISION'
                            && $record->government_status !== 'ENVIADO'
                            && $record->status !== 'DESACTIVADO'
                        );
                        
                        $skippedRecords = $records->diff($validRecords);
                        $invalidCount = $skippedRecords->count();
                        
                        if ($validRecords->isEmpty()) {
                            Notification::make()
                                ->title('Ninguna solicitud procesada')
                                ->body('No hay solicitudes válidas para finalizar. Solo se pueden finalizar solicitudes sin coincidencia pendientes de envío.')
                                ->warning()
                                ->send();
                            return;
                        }

                        $successCount = 0;
                        $errorCount = 0;
                        $processedIds = [];
                        
                        foreach ($validRecords as $record) {
                            $success = $service->finishSearch($record);
                            if ($success) {
                                $successCount++;
                            } else {
                                $errorCount++;
                            }
                            $processedIds[] = $record->id;
                        }
                        
                        // Registrar auditoría
                        try {
                            \App\Models\AuditLog::create([
                                'institution_id' => auth()->user()->institution_id ?? null,
                                'user_id' => auth()->id(),
                                'action' => 'pui_reports_bulk_finalizar_sin_coincidencia',
                                'entity_type' => 'PuiReport',
                                'entity_id' => null,
                                'details' => [
                                    'total_selected' => $records->count(),
                                    'sent_count' => $successCount,
                                    'error_count' => $errorCount,
                                    'skipped_count' => $invalidCount,
                                    'report_ids' => $processedIds,
                                ],
                            ]);
                        } catch (\Exception $e) {
                            \Illuminate\Support\Facades\Log::error('Error registrando AuditLog bulk finalizar: ' . $e->getMessage());
                        }
                        
                        if ($successCount > 0 || $errorCount > 0) {
                            Notification::make()
                                ->title('Finalización procesada')
                                ->body("Procesadas: " . ($successCount + $errorCount) . ". Ignoradas: $invalidCount.")
                                ->success()
                                ->send();
                        }
                    })
                    ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->checkIfRecordIsSelectableUsing(fn (PuiReport $record): bool =>
                $record->match_status === 'SIN_COINCIDENCIA_SUGERIDA'
                && $record->status === 'PENDIENTE_REVISION'
                && $record->government_status !== 'ENVIADO'
                && $record->status !== 'DESACTIVADO'
            );
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePuiReports::route('/'),
        ];
    }
}
