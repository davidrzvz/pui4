<?php

namespace App\Filament\Resources\PuiReports\Pages;

use App\Filament\Resources\PuiReports\PuiReportResource;
use Filament\Resources\Pages\ManageRecords;

class ManagePuiReports extends ManageRecords
{
    protected static string $resource = PuiReportResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Resources\PuiReports\Widgets\PuiReportStatsWidget::class,
        ];
    }
}
