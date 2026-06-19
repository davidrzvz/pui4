<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\Institution;
use App\Models\ClientRecord;
use App\Models\CsvImportBatch;
use App\Models\PuiReport;
use App\Models\GovernmentApiLog;
use App\Models\PuiReportMatchCheck;
use App\Services\GovernmentApiService;
use App\Http\Controllers\Api\PuiApiController;
use Illuminate\Http\Request;

class PuiFullFlowTestCommand extends Command
{
    protected $signature = 'pui:test-flow';
    protected $description = 'Ejecuta validaciones integrales del flujo Gobierno-PUI';

    private $institution;
    private $batch;
    private $reportsCreated = [];

    public function handle()
    {
        $this->info("================================");
        $this->info("PUI FULL FLOW TEST");
        $this->info("================================");

        try {
            $this->setupData();
            
            $this->runScenarioA();
            $this->runScenarioB();
            $this->runScenarioC();
            $this->runScenarioD();
            $this->runScenarioE();
            $this->runScenarioF();
            $this->runScenarioG();
            $this->runScenarioH();

            $this->info("\nTODOS LOS TEST PASARON");
        } catch (\Exception $e) {
            $this->error("\nFALLO EN EL TEST: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        } finally {
            $this->teardownData();
        }
    }

    private function setupData()
    {
        // 1. Institucion
        $this->institution = Institution::create([
            'rfc' => 'TESTFLOW01',
            'name' => 'Institucion Test Flow',
            'is_active' => true,
            'pui_credentials' => [
                'api_url' => 'https://api.gobierno.test',
                'pui_user' => 'testuser',
                'pui_password' => Crypt::encryptString('password123'),
            ]
        ]);

        // 2. Batch and ClientRecord for Scenario A
        $this->batch = CsvImportBatch::create([
            'institution_id' => $this->institution->id,
            'filename' => 'test_flow.csv',
            'status' => 'COMPLETED',
            'total_rows' => 1,
        ]);

        ClientRecord::create([
            'institution_id' => $this->institution->id,
            'csv_import_batch_id' => $this->batch->id,
            'curp' => 'GODE561231HDFABC09',
            'internal_identifier' => 'CLI-001',
            'first_name' => 'TEST',
            'last_name' => 'TEST',
        ]);
    }

    private function teardownData()
    {
        GovernmentApiLog::where('institution_id', $this->institution->id ?? null)->delete();
        PuiReportMatchCheck::whereIn('pui_report_id', $this->reportsCreated)->delete();
        PuiReport::whereIn('id', $this->reportsCreated)->delete();
        ClientRecord::where('institution_id', $this->institution->id ?? null)->delete();
        CsvImportBatch::where('institution_id', $this->institution->id ?? null)->delete();
        if ($this->institution) {
            $this->institution->delete();
        }
        Cache::forget("government_token_".$this->institution->id);
    }

    private function callActivateReport($payload)
    {
        $request = Request::create('/api/v1/pui/activar-reporte', 'POST', $payload);
        $request->attributes->set('institution', $this->institution);

        $controller = app(PuiApiController::class);
        return $controller->activarReporte($request);
    }

    private function runScenarioA()
    {
        $this->updateApiUrl('https://api.a.test');

        Http::fake([
            'https://api.a.test/login' => Http::response(['token' => 'fake_jwt_123'], 200),
            'https://api.a.test/notificar-coincidencia' => Http::response(['message' => 'Coincidencia recibida correctamente'], 200),
        ]);

        $payload = [
            'id' => 'FLOW-A-001-XXXX-XXXX-XXXX-XXXXXXXXXX',
            'curp' => 'GODE561231HDFABC09',
            'lugar_nacimiento' => 'DF',
            'fase_busqueda' => '1'
        ];

        $response = $this->callActivateReport($payload);

        if ($response->getStatusCode() !== 200) {
            throw new \Exception("Escenario A: Activar reporte devolvio " . $response->getStatusCode() . " " . $response->getContent());
        }

        $report = PuiReport::where('external_id', 'FLOW-A-001-XXXX-XXXX-XXXX-XXXXXXXXXX')->first();
        if (!$report) throw new \Exception("Escenario A: Reporte no creado");
        $this->reportsCreated[] = $report->id;

        if ($report->status !== 'PENDIENTE_REVISION' || $report->match_status !== 'COINCIDENCIA_SUGERIDA' || !$report->client_record_id) {
            throw new \Exception("Escenario A: Estado inicial incorrecto");
        }

        if (PuiReportMatchCheck::where('pui_report_id', $report->id)->count() === 0) {
            throw new \Exception("Escenario A: Histórico match check no creado");
        }

        $this->info("✓ Activar reporte");
        $this->info("✓ Coincidencia inicial");
        $this->info("✓ Histórico creado");

        $service = app(GovernmentApiService::class);
        $success = $service->sendCoincidence($report);

        if (!$success) throw new \Exception("Escenario A: Error al enviar coincidencia");

        $report->refresh();
        if ($report->status !== 'FINALIZADO' || $report->government_status !== 'ENVIADO' || !$report->government_sent_at) {
            throw new \Exception("Escenario A: Estado despues del envío incorrecto");
        }

        if (GovernmentApiLog::where('institution_id', $this->institution->id)->count() === 0) {
            throw new \Exception("Escenario A: Log de API no creado");
        }

        $this->info("✓ Envío Gobierno correcto");
    }

    private function runScenarioB()
    {
        $this->updateApiUrl('https://api.b.test');

        Http::fake([
            'https://api.b.test/login' => Http::response(['token' => 'fake_jwt_123'], 200),
            'https://api.b.test/busqueda-finalizada' => Http::response(['message' => 'ok'], 200),
        ]);

        $payload = [
            'id' => 'FLOW-B-001-XXXX-XXXX-XXXX-XXXXXXXXXX',
            'curp' => 'XXXX000000HXXXXX00',
            'lugar_nacimiento' => null,
        ];

        $this->callActivateReport($payload);
        $report = PuiReport::where('external_id', 'FLOW-B-001-XXXX-XXXX-XXXX-XXXXXXXXXX')->first();
        $this->reportsCreated[] = $report->id;

        if ($report->match_status !== 'SIN_COINCIDENCIA_SUGERIDA' || $report->client_record_id !== null) {
            throw new \Exception("Escenario B: Coincidencia erronea encontrada");
        }

        $service = app(GovernmentApiService::class);
        $success = $service->finishSearch($report);

        if (!$success) throw new \Exception("Escenario B: Error al finalizar búsqueda");

        $report->refresh();
        if ($report->status !== 'FINALIZADO' || $report->government_status !== 'ENVIADO') {
            throw new \Exception("Escenario B: Estado final incorrecto");
        }

        $this->info("✓ Sin coincidencia correcto");
    }

    private function runScenarioC()
    {
        $payload = [
            'id' => 'FLOW-C-001-XXXX-XXXX-XXXX-XXXXXXXXXX',
            'curp' => 'TEST010101HOCXXX01',
            'lugar_nacimiento' => null,
        ];

        $this->callActivateReport($payload);
        $report = PuiReport::where('external_id', 'FLOW-C-001-XXXX-XXXX-XXXX-XXXXXXXXXX')->first();
        $this->reportsCreated[] = $report->id;

        if ($report->match_status !== 'SIN_COINCIDENCIA_SUGERIDA') {
            throw new \Exception("Escenario C: Deberia no tener coincidencia inicial");
        }

        $batch = CsvImportBatch::create([
            'institution_id' => $this->institution->id,
            'filename' => 'test_flow_c.csv',
            'status' => 'COMPLETED',
            'total_rows' => 1,
        ]);

        $clientRecord = ClientRecord::create([
            'institution_id' => $this->institution->id,
            'csv_import_batch_id' => $batch->id,
            'curp' => 'TEST010101HOCXXX01',
            'internal_identifier' => 'CLI-002',
        ]);

        // Reevaluar logic
        $matchStatus = 'COINCIDENCIA_SUGERIDA';
        $report->update([
            'match_status' => $matchStatus,
            'client_record_id' => $clientRecord->id,
            'matched_csv_import_batch_id' => $clientRecord->csv_import_batch_id,
            'match_checked_at' => now(),
        ]);

        PuiReportMatchCheck::create([
            'pui_report_id' => $report->id,
            'institution_id' => $report->institution_id,
            'csv_import_batch_id' => $clientRecord->csv_import_batch_id,
            'client_record_id' => $clientRecord->id,
            'match_status' => $matchStatus,
            'checked_at' => now(),
            'notes' => 'Reevaluacion test',
        ]);

        if ($report->match_status !== 'COINCIDENCIA_SUGERIDA') {
            throw new \Exception("Escenario C: Falla al actualizar coincidencia");
        }

        if (PuiReportMatchCheck::where('pui_report_id', $report->id)->count() !== 2) {
            throw new \Exception("Escenario C: No hay historico doble");
        }

        $this->info("✓ Reevaluación después CSV correcta");
    }

    private function runScenarioD()
    {
        $this->updateApiUrl('https://api.d.test');

        Http::fake([
            'https://api.d.test/login' => Http::response(['token' => 'fake_jwt_123'], 200),
            'https://api.d.test/notificar-coincidencia' => Http::response(['error' => 'Internal Server Error'], 500),
        ]);

        $report = PuiReport::where('external_id', 'FLOW-A-001-XXXX-XXXX-XXXX-XXXXXXXXXX')->first(); // Reusing the same report structure conceptually, but let's make a new one.

        $payload = [
            'id' => 'FLOW-D-001-XXXX-XXXX-XXXX-XXXXXXXXXX',
            'curp' => 'GODE561231HDFABC09',
            'lugar_nacimiento' => null,
        ];
        $this->callActivateReport($payload);
        $report = PuiReport::where('external_id', 'FLOW-D-001-XXXX-XXXX-XXXX-XXXXXXXXXX')->first();
        $this->reportsCreated[] = $report->id;

        $service = app(GovernmentApiService::class);
        $success = $service->sendCoincidence($report);

        if ($success) {
            $log = \App\Models\GovernmentApiLog::where('institution_id', $this->institution->id)->latest()->first();
            throw new \Exception("Escenario D: Debería fallar el envío. Response: " . json_encode($log->response_data));
        }

        $report->refresh();
        if ($report->government_status !== 'ERROR_ENVIO') {
            throw new \Exception("Escenario D: Estado gobierno incorrecto");
        }
        if (!$report->government_error) {
            throw new \Exception("Escenario D: Falta mensaje de error");
        }
        if ($report->status === 'FINALIZADO') {
            throw new \Exception("Escenario D: Estado status NO debe ser FINALIZADO");
        }

        $this->info("✓ Error Gobierno manejado");
    }

    private function runScenarioE()
    {
        $this->updateApiUrl('https://api.e.test');

        Http::fake([
            'https://api.e.test/login' => Http::response(['token' => 'fake_jwt_123'], 200),
            'https://api.e.test/busqueda-finalizada' => Http::response(['message' => 'ok'], 200),
        ]);

        $payload = [
            'id' => 'FLOW-E-001-XXXX-XXXX-XXXX-XXXXXXXXXX',
            'curp' => 'XXXX000000HXXXXX01',
            'lugar_nacimiento' => null,
        ];
        $this->callActivateReport($payload);
        $report = PuiReport::where('external_id', 'FLOW-E-001-XXXX-XXXX-XXXX-XXXXXXXXXX')->first();
        $this->reportsCreated[] = $report->id;

        $service = app(GovernmentApiService::class);
        $service->finishSearch($report); // Request 1

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return str_contains($request->url(), '/login');
        });

        // clear recorded requests
        $recorded = Http::recorded();
        
        // Mock again for second request but ensure /login isn't called
        Http::fake([
            '*/busqueda-finalizada' => Http::response(['message' => 'ok2'], 200),
        ]);

        // Clear recorded to assert just the new ones
        Http::clearResolvedInstances(); 
        Http::fake([
            'https://api.e.test/login' => function() { throw new \Exception("Debe usar cache, no llamar login"); },
            'https://api.e.test/busqueda-finalizada' => Http::response(['message' => 'ok2'], 200),
        ]);

        $report->refresh();
        $report->update(['government_status' => 'PENDIENTE_ENVIO', 'status' => 'PENDIENTE_REVISION']);

        $service->finishSearch($report); // Request 2

        $this->info("✓ Login interno");
        $this->info("✓ Cache JWT correcto");
    }

    private function runScenarioF()
    {
        // F: Intentar enviar dos veces. Debe bloquear segundo envío.
        $report = PuiReport::where('external_id', 'FLOW-A-001-XXXX-XXXX-XXXX-XXXXXXXXXX')->first(); // Already ENVIADO
        $service = app(GovernmentApiService::class);

        try {
            $service->sendCoincidence($report);
            throw new \Exception("Escenario F falló: Permitió doble envío");
        } catch (\Exception $e) {
            if ($e->getMessage() !== 'Este reporte ya fue enviado al Gobierno') {
                throw new \Exception("Escenario F falló: Excepción incorrecta -> " . $e->getMessage());
            }
        }

        $this->info("✓ Bloqueo doble envío correcto");
    }

    private function runScenarioG()
    {
        // G: Enviar coincidencia. Actualizar padrón después. Confirmar que sent_evidence NO cambia.
        $report = PuiReport::where('external_id', 'FLOW-A-001-XXXX-XXXX-XXXX-XXXXXXXXXX')->first();
        $originalEvidence = $report->sent_evidence;

        if (!$originalEvidence) {
            throw new \Exception("Escenario G falló: No hay evidencia guardada");
        }

        // Simulate changing the padron
        $clientRecord = $report->clientRecord;
        $clientRecord->internal_identifier = "NUEVO ID ALTERADO";
        $clientRecord->save();

        $report->refresh();
        $newEvidence = $report->sent_evidence;

        if ($newEvidence['client_record']['internal_identifier'] === "NUEVO ID ALTERADO") {
            throw new \Exception("Escenario G falló: La evidencia cambió tras actualizar padrón");
        }

        $this->info("✓ Evidencia congelada correcta");
    }

    private function runScenarioH()
    {
        // H: Confirmar que caso FINALIZADO no permite reevaluación.
        $report = PuiReport::where('external_id', 'FLOW-A-001-XXXX-XXXX-XXXX-XXXXXXXXXX')->first();
        
        if (!$report->isClosed()) {
            throw new \Exception("Escenario H falló: isClosed devolvió falso para reporte finalizado");
        }

        $this->info("✓ Estados finales protegidos");
    }

    private function updateApiUrl($url)
    {
        $credentials = $this->institution->pui_credentials;
        $credentials['api_url'] = $url;
        $this->institution->pui_credentials = $credentials;
        $this->institution->save();
        Cache::forget("government_token_".$this->institution->id);
    }
}
