<?php

namespace App\Filament\Resources\CsvImportBatches\Pages;

use App\Filament\Resources\CsvImportBatches\CsvImportBatchResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use App\Services\CsvImportService;

class ManageCsvImportBatches extends ManageRecords
{
    protected static string $resource = CsvImportBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->mutateFormDataUsing(function (array $data): array {
                    $user = auth()->user();
                    $data['user_id'] = $user->id;
                    $data['status'] = 'PENDIENTE';
                    
                    if (!$user->hasRole('SUPER_ADMIN')) {
                        $data['institution_id'] = $user->institution_id;
                    }
                    
                    return $data;
                })
                ->after(function ($record) {
                    $service = new CsvImportService();
                    $service->processBatch($record);
                }),
        ];
    }
}
