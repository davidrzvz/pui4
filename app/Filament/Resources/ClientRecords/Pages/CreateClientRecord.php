<?php

namespace App\Filament\Resources\ClientRecords\Pages;

use App\Filament\Resources\ClientRecords\ClientRecordResource;
use Filament\Resources\Pages\CreateRecord;

class CreateClientRecord extends CreateRecord
{
    protected static string $resource = ClientRecordResource::class;
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();
        if (!$user->hasRole('SUPER_ADMIN')) {
            $data['institution_id'] = $user->institution_id;
        }

        $data['curp'] = strtoupper($data['curp'] ?? '');
        $data['internal_identifier'] = strtoupper($data['internal_identifier'] ?? '');
        $data['name'] = strtoupper($data['name'] ?? '');

        return $data;
    }

    protected function getCreatedNotification(): ?\Filament\Notifications\Notification
    {
        return null;
    }

    protected function afterCreate(): void
    {
        \Filament\Notifications\Notification::make()
            ->title('Registro guardado')
            ->body('Se ha guardado el registro. PUI ejecutará el proceso interno de búsqueda de coincidencias sobre las solicitudes abiertas.')
            ->success()
            ->send();
    }
}
