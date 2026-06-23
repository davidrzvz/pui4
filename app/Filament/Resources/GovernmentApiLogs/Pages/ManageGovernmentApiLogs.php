<?php

namespace App\Filament\Resources\GovernmentApiLogs\Pages;

use App\Filament\Resources\GovernmentApiLogs\GovernmentApiLogResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageGovernmentApiLogs extends ManageRecords
{
    protected static string $resource = GovernmentApiLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
