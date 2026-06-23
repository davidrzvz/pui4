<?php

namespace App\Services;

use App\Models\PuiReport;
use App\Models\ClientRecord;
use App\Models\PuiReportMatchCheck;

class PuiMatchingService
{
    /**
     * Evaluates a single report against the client records.
     * Returns an array with previous and new status.
     *
     * @param PuiReport $report
     * @param string $triggerNote
     * @return array
     */
    public function evaluateReport(PuiReport $report, string $triggerNote = 'Reevaluación automática'): array
    {
        $previousStatus = $report->match_status;
        $previousClientId = $report->client_record_id;

        $clientRecord = ClientRecord::where('institution_id', $report->institution_id)
            ->where('curp', $report->curp)
            ->where('is_active', true)
            ->first();

        $newMatchStatus = $clientRecord ? 'COINCIDENCIA_SUGERIDA' : 'SIN_COINCIDENCIA_SUGERIDA';
        $newClientId = $clientRecord ? $clientRecord->id : null;

        // If nothing changed, we don't need to bloat the database history
        if ($previousStatus === $newMatchStatus && $previousClientId === $newClientId) {
            return [
                'changed' => false,
                'previous_status' => $previousStatus,
                'new_status' => $newMatchStatus,
            ];
        }

        $report->update([
            'match_status' => $newMatchStatus,
            'client_record_id' => $newClientId,
            'matched_csv_import_batch_id' => $clientRecord ? $clientRecord->csv_import_batch_id : null,
            'match_checked_at' => now(),
        ]);

        PuiReportMatchCheck::create([
            'pui_report_id' => $report->id,
            'institution_id' => $report->institution_id,
            'csv_import_batch_id' => $clientRecord ? $clientRecord->csv_import_batch_id : null,
            'client_record_id' => $newClientId,
            'match_status' => $newMatchStatus,
            'checked_at' => now(),
            'checked_by' => auth()->id(), // can be null if running in CLI or background
            'notes' => $triggerNote,
        ]);

        return [
            'changed' => true,
            'previous_status' => $previousStatus,
            'new_status' => $newMatchStatus,
        ];
    }

    /**
     * Recalculates matches for all pending reports for a given institution
     * or all pending reports globally if institution_id is null.
     *
     * @param string|null $institutionId
     * @return array
     */
    public function refreshPendingReports(?string $institutionId = null): array
    {
        $query = PuiReport::where('status', 'PENDIENTE_REVISION')
            ->where('government_status', '!=', 'ENVIADO');

        if ($institutionId) {
            $query->where('institution_id', $institutionId);
        }

        $reports = $query->get();

        $stats = [
            'total' => 0,
            'matched' => 0,
            'unmatched' => 0,
            'changed' => 0,
        ];

        foreach ($reports as $report) {
            $result = $this->evaluateReport($report, 'Reevaluación por cambio en padrón');
            
            $stats['total']++;
            if ($result['new_status'] === 'COINCIDENCIA_SUGERIDA') {
                $stats['matched']++;
            } else {
                $stats['unmatched']++;
            }

            if ($result['changed']) {
                $stats['changed']++;
            }
        }

        return $stats;
    }
}
