<?php

namespace App\Filament\Resources\ClientRecords\Pages;

use App\Filament\Resources\ClientRecords\ClientRecordResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewClientRecord extends ViewRecord
{
    protected static string $resource = ClientRecordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
