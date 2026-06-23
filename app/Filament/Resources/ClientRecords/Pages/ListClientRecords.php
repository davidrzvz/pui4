<?php

namespace App\Filament\Resources\ClientRecords\Pages;

use App\Filament\Resources\ClientRecords\ClientRecordResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListClientRecords extends ListRecords
{
    protected static string $resource = ClientRecordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('importar_csv')
                ->label('Importar CSV existente')
                ->icon('heroicon-o-document-arrow-up')
                ->url(fn () => \App\Filament\Resources\CsvImportBatches\CsvImportBatchResource::getUrl('index'))
                ->visible(fn () => auth()->user()->hasAnyRole(['SUPER_ADMIN', 'ADMINISTRADOR', 'OPERADOR'])),
            CreateAction::make()
                ->label('Crear cliente manual'),
        ];
    }
}
