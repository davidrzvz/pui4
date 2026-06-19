#!/bin/bash

cat << 'EOF' > tests/government_api_test_script.php
<?php

use Illuminate\Support\Facades\Http;
use App\Models\Institution;
use App\Models\PuiReport;
use App\Services\GovernmentApiService;
use Illuminate\Support\Facades\Crypt;

Http::fake([
    '*/login' => Http::response(['token' => 'fake_jwt_token_123'], 200),
    '*/notificar-coincidencia' => Http::response(['status' => 'success', 'message' => 'Coincidencia recibida'], 200),
    '*/busqueda-finalizada' => Http::response(['status' => 'success', 'message' => 'Búsqueda finalizada'], 200),
]);

$institution = Institution::firstOrCreate(
    ['rfc' => 'TEST010101'],
    [
        'name' => 'Institucion Test',
        'is_active' => true,
        'pui_credentials' => [
            'api_url' => 'https://api.gobierno.test',
            'pui_user' => 'testuser',
            'pui_password' => Crypt::encryptString('password123'),
        ]
    ]
);

$report = PuiReport::firstOrCreate(
    ['external_id' => 'REQ-TEST-001'],
    [
        'institution_id' => $institution->id,
        'curp' => 'TEST010101HDFXXX01',
        'is_test' => true,
        'status' => 'PENDIENTE_REVISION',
        'match_status' => 'COINCIDENCIA_SUGERIDA',
        'request_payload' => ['lugar_nacimiento' => 'CDMX'],
        'government_status' => 'PENDIENTE_ENVIO',
    ]
);

$service = app(GovernmentApiService::class);

echo "1. Probando Login...\n";
$token = $service->authenticate($institution);
echo $token ? "Token obtenido: $token\n" : "Fallo el login\n";

echo "\n2. Probando Enviar Coincidencia...\n";
$res1 = $service->sendCoincidence($report);
echo $res1 ? "Coincidencia enviada correctamente. Status: {$report->government_status}\n" : "Fallo envio de coincidencia\n";

$report->update(['government_status' => 'PENDIENTE_ENVIO', 'status' => 'PENDIENTE_REVISION']);

echo "\n3. Probando Finalizar Busqueda...\n";
$res2 = $service->finishSearch($report);
echo $res2 ? "Busqueda finalizada correctamente. Status: {$report->government_status}\n" : "Fallo envio de busqueda finalizada\n";

echo "\nRevisando Logs en government_api_logs...\n";
$logs = \App\Models\GovernmentApiLog::where('institution_id', $institution->id)->orderBy('created_at', 'desc')->take(3)->get();
foreach ($logs as $log) {
    echo "- Endpoint: {$log->endpoint} | Status: {$log->status_code}\n";
}

$report->delete();
$institution->delete();
EOF

php artisan tinker tests/government_api_test_script.php
rm tests/government_api_test_script.php
