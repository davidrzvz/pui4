<?php

namespace App\Filament\Resources\ClientRecords\Pages;

use App\Filament\Resources\ClientRecords\ClientRecordResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditClientRecord extends EditRecord
{
    protected static string $resource = ClientRecordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['curp'] = strtoupper($data['curp'] ?? '');
        $data['internal_identifier'] = strtoupper($data['internal_identifier'] ?? '');
        $data['name'] = strtoupper($data['name'] ?? '');

        return $data;
    }

    protected function getSavedNotification(): ?\Filament\Notifications\Notification
    {
        return null;
    }

    protected function afterSave(): void
    {
        \Filament\Notifications\Notification::make()
            ->title('Registro actualizado')
            ->body('Se ha actualizado el registro. PUI ejecutará el proceso interno de búsqueda de coincidencias sobre las solicitudes abiertas.')
            ->success()
            ->send();
    }
}
