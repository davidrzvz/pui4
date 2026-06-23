<?php

namespace App\Services;

use App\Models\CsvImportBatch;
use App\Models\ClientRecord;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Exception;

class CsvImportService
{
    /**
     * Procesar archivo CSV
     */
    public function processBatch(CsvImportBatch $batch): void
    {
        $batch->update(['status' => 'PROCESANDO']);

        $filePath = Storage::disk('public')->path($batch->filename);
        if (!file_exists($filePath)) {
            $batch->update([
                'status' => 'ERROR',
                'error_summary' => ['message' => 'Archivo no encontrado.'],
            ]);
            return;
        }

        $totalRecords = 0;
        $processedRecords = 0;
        $createdRecords = 0;
        $updatedRecords = 0;
        $failedRecords = 0;
        $duplicateRecords = 0;
        $deactivatedRecords = 0;
        $errors = [];
        $validCurps = [];

        $fileHandle = fopen($filePath, 'r');
        $header = fgetcsv($fileHandle);
        if (!$header) {
            $batch->update([
                'status' => 'ERROR',
                'error_summary' => ['message' => 'El archivo está vacío o tiene un formato incorrecto.'],
            ]);
            fclose($fileHandle);
            return;
        }

        // Limpiar cabeceras
        $header = array_map(function ($col) {
            return strtolower(trim(preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $col)));
        }, $header);

        $curpIndex = array_search('curp', $header);
        $idIndex = array_search('identificador_interno', $header);

        if ($curpIndex === false || $idIndex === false) {
            $batch->update([
                'status' => 'ERROR',
                'error_summary' => ['message' => 'Faltan columnas requeridas: curp, identificador_interno.'],
            ]);
            fclose($fileHandle);
            return;
        }

        $processedCurpsInFile = []; // Para detectar duplicados dentro del mismo CSV

        DB::beginTransaction();
        try {
            while (($row = fgetcsv($fileHandle)) !== false) {
                $totalRecords++;
                
                $curp = isset($row[$curpIndex]) ? strtoupper(trim($row[$curpIndex])) : '';
                $internalId = isset($row[$idIndex]) ? trim($row[$idIndex]) : '';

                // Validaciones
                if (empty($curp) || empty($internalId)) {
                    $failedRecords++;
                    if (count($errors) < 100) {
                        $errors[] = "Fila {$totalRecords}: Faltan datos requeridos.";
                    }
                    continue;
                }

                // Regex CURP
                if (!preg_match('/^[A-Z0-9]{18}$/', $curp)) {
                    $failedRecords++;
                    if (count($errors) < 100) {
                        $errors[] = "Fila {$totalRecords}: CURP inválida ($curp). Debe contener exactamente 18 caracteres usando únicamente letras mayúsculas y números.";
                    }
                    continue;
                }

                // Detectar duplicados dentro del mismo archivo
                if (isset($processedCurpsInFile[$curp])) {
                    $duplicateRecords++;
                    if (count($errors) < 100) {
                        $errors[] = "Fila {$totalRecords}: CURP duplicada en el archivo ($curp).";
                    }
                    continue;
                }
                $processedCurpsInFile[$curp] = true;
                $validCurps[] = $curp;

                // Buscar en BD
                $existing = ClientRecord::where('institution_id', $batch->institution_id)
                                        ->where('curp', $curp)
                                        ->first();

                if ($existing) {
                    $existing->update([
                        'internal_identifier' => $internalId,
                        'csv_import_batch_id' => $batch->id,
                        'is_active' => true,
                    ]);
                    $updatedRecords++;
                    $processedRecords++;
                } else {
                    ClientRecord::create([
                        'institution_id' => $batch->institution_id,
                        'curp' => $curp,
                        'internal_identifier' => $internalId,
                        'csv_import_batch_id' => $batch->id,
                        'is_active' => true,
                    ]);
                    $createdRecords++;
                    $processedRecords++;
                }
            }

            if ($batch->import_mode === 'replace' && $processedRecords > 0) {
                $deactivatedRecords = ClientRecord::where('institution_id', $batch->institution_id)
                    ->whereNotIn('curp', $validCurps)
                    ->where('is_active', true)
                    ->update(['is_active' => false]);
            }

            DB::commit();

            $batch->update([
                'status' => 'COMPLETADO',
                'total_records' => $totalRecords,
                'processed_records' => $processedRecords,
                'created_records' => $createdRecords,
                'updated_records' => $updatedRecords,
                'failed_records' => $failedRecords,
                'duplicate_records' => $duplicateRecords,
                'deactivated_records' => $deactivatedRecords,
                'error_summary' => !empty($errors) ? $errors : null,
            ]);

            // Crear registro en auditoría
            AuditLog::create([
                'institution_id' => $batch->institution_id,
                'user_id' => $batch->user_id,
                'event' => 'CARGA_CSV',
                'auditable_type' => CsvImportBatch::class,
                'auditable_id' => $batch->id,
                'old_values' => null,
                'new_values' => [
                    'import_mode' => $batch->import_mode,
                    'total' => $totalRecords,
                    'created' => $createdRecords,
                    'updated' => $updatedRecords,
                    'failed' => $failedRecords,
                    'duplicates' => $duplicateRecords,
                    'deactivated' => $deactivatedRecords,
                    'file' => $batch->filename,
                ],
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            $batch->update([
                'status' => 'ERROR',
                'error_summary' => ['message' => 'Error durante el procesamiento: ' . $e->getMessage()],
            ]);
        } finally {
            fclose($fileHandle);
        }

        if ($batch->status === 'COMPLETADO') {
            $service = app(\App\Services\PuiMatchingService::class);
            $service->refreshPendingReports($batch->institution_id);

            if (request()->routeIs('filament.*') || request()->is('admin/*')) {
                \Filament\Notifications\Notification::make()
                    ->title('Carga completada')
                    ->body('El padrón fue actualizado. PUI ejecutará el proceso interno de búsqueda de coincidencias sobre las solicitudes abiertas.')
                    ->success()
                    ->send();
            }
        }
    }
}
