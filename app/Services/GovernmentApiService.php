<?php

namespace App\Services;

use App\Models\Institution;
use App\Models\PuiReport;
use App\Models\GovernmentApiLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class GovernmentApiService
{
    /**
     * Authenticate with the government API for the given institution.
     */
    public function authenticate(Institution $institution, ?string $operatorUserId = null): ?string
    {
        $cacheKey = "government_token_{$institution->id}";

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $credentials = $institution->pui_credentials;

        if (!$credentials || empty($credentials['api_url']) || empty($credentials['pui_password'])) {
            return null;
        }

        $apiUrl = rtrim($credentials['api_url'], '/');
        $endpoint = "{$apiUrl}/login";

        try {
            $password = Crypt::decryptString($credentials['pui_password']);
        } catch (\Exception $e) {
            $this->logApiCall($institution->id, $endpoint, 'POST', null, ['error' => 'Failed to decrypt password'], 500, $operatorUserId);
            return null;
        }

        $payload = [
            'institucion_id' => $institution->rfc,
            'clave' => $password,
        ];

        try {
            $response = Http::timeout(10)->post($endpoint, $payload);
            
            $this->logApiCall($institution->id, $endpoint, 'POST', $payload, $response->json() ?? ['raw' => $response->body()], $response->status(), $operatorUserId);

            if ($response->successful() && $response->json('token')) {
                $token = $response->json('token');
                // Cache for 45 minutes
                Cache::put($cacheKey, $token, now()->addMinutes(45));
                return $token;
            }

            return null;
        } catch (\Exception $e) {
            $this->logApiCall($institution->id, $endpoint, 'POST', $payload, ['error' => $e->getMessage()], 500, $operatorUserId);
            return null;
        }
    }

    /**
     * Send coincidence notification to government API.
     */
    public function sendCoincidence(PuiReport $report, ?string $operatorUserId = null): bool
    {
        if ($report->government_status === 'ENVIADO') {
            throw new \Exception('Este reporte ya fue enviado al Gobierno');
        }

        $operatorUserId = $operatorUserId ?? \Illuminate\Support\Facades\Auth::id();

        $institution = $report->institution;
        $token = $this->authenticate($institution, $operatorUserId);

        if (!$token) {
            $this->markReportError($report, 'Authentication failed');
            return false;
        }

        $credentials = $institution->pui_credentials;
        $apiUrl = rtrim($credentials['api_url'], '/');
        $endpoint = "{$apiUrl}/notificar-coincidencia";

        $clientData = $report->clientRecord;
        $requestPayload = is_array($report->request_payload) ? $report->request_payload : json_decode($report->request_payload, true);

        // Mandatory fields from manual
        $payload = [
            'id' => $report->external_id,
            'curp' => $report->curp,
            'institucion_id' => $institution->rfc,
            'lugar_nacimiento' => $requestPayload['lugar_nacimiento'] ?? null,
            'fase_busqueda' => '1',
        ];

        // Add client data if exists
        if ($clientData) {
            $payload = array_merge($payload, [
                'nombre' => $clientData->first_name ?? null,
                'primer_apellido' => $clientData->last_name ?? null,
                'segundo_apellido' => $clientData->second_last_name ?? null,
                'telefono' => $clientData->phone ?? null,
                'correo' => $clientData->email ?? null,
                'direccion' => $clientData->address ?? null,
            ]);
        }

        try {
            $response = Http::withToken($token)->timeout(15)->post($endpoint, $payload);
            $responseData = $response->json() ?? ['raw' => $response->body()];
            
            $this->logApiCall($institution->id, $endpoint, 'POST', $payload, $responseData, $response->status(), $operatorUserId);

            if ($response->successful()) {
                $report->update([
                    'government_status' => 'ENVIADO',
                    'status' => 'FINALIZADO',
                    'government_sent_at' => now(),
                    'government_sent_by' => $operatorUserId,
                    'government_response' => $responseData,
                    'government_error' => null,
                    'sent_evidence' => [
                        'decision' => 'COINCIDENCIA',
                        'curp' => $report->curp,
                        'match_status' => $report->match_status,
                        'client_record' => $report->clientRecord ? $report->clientRecord->toArray() : null,
                        'csv_batch' => ($report->clientRecord && $report->clientRecord->csvBatch) ? $report->clientRecord->csvBatch->toArray() : null,
                        'operator' => $operatorUserId ? \App\Models\User::find($operatorUserId)?->toArray() : null,
                        'sent_at' => now()->toIso8601String(),
                        'external_id' => $report->external_id,
                        'institution_id' => $institution->rfc,
                        'government_status' => 'ENVIADO',
                        'government_response' => $responseData,
                        'request_payload' => $requestPayload,
                    ],
                ]);
                return true;
            } else {
                $this->markReportError($report, 'Government API returned error: ' . $response->status() . ' - ' . json_encode($responseData));
                return false;
            }
        } catch (\Exception $e) {
            $this->logApiCall($institution->id, $endpoint, 'POST', $payload, ['error' => $e->getMessage()], 500, $operatorUserId);
            $this->markReportError($report, 'Exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Finish search without coincidence.
     */
    public function finishSearch(PuiReport $report, ?string $operatorUserId = null): bool
    {
        if ($report->government_status === 'ENVIADO') {
            throw new \Exception('Este reporte ya fue enviado al Gobierno');
        }

        $operatorUserId = $operatorUserId ?? \Illuminate\Support\Facades\Auth::id();

        $institution = $report->institution;
        $token = $this->authenticate($institution, $operatorUserId);

        if (!$token) {
            $this->markReportError($report, 'Authentication failed');
            return false;
        }

        $credentials = $institution->pui_credentials;
        $apiUrl = rtrim($credentials['api_url'], '/');
        $endpoint = "{$apiUrl}/busqueda-finalizada";

        $payload = [
            'id' => $report->external_id,
            'institucion_id' => $institution->rfc,
        ];

        try {
            $response = Http::withToken($token)->timeout(15)->post($endpoint, $payload);
            $responseData = $response->json() ?? ['raw' => $response->body()];

            $this->logApiCall($institution->id, $endpoint, 'POST', $payload, $responseData, $response->status(), $operatorUserId);

            if ($response->successful()) {
                $report->update([
                    'government_status' => 'ENVIADO',
                    'status' => 'FINALIZADO',
                    'government_sent_at' => now(),
                    'government_sent_by' => $operatorUserId,
                    'government_response' => $responseData,
                    'government_error' => null,
                    'sent_evidence' => [
                        'decision' => 'SIN_COINCIDENCIA',
                        'curp' => $report->curp,
                        'match_status' => $report->match_status,
                        'client_record' => null,
                        'csv_batch' => null,
                        'operator' => $operatorUserId ? \App\Models\User::find($operatorUserId)?->toArray() : null,
                        'sent_at' => now()->toIso8601String(),
                        'external_id' => $report->external_id,
                        'institution_id' => $institution->rfc,
                        'government_status' => 'ENVIADO',
                        'government_response' => $responseData,
                        'request_payload' => is_array($report->request_payload) ? $report->request_payload : json_decode($report->request_payload, true),
                    ],
                ]);
                return true;
            } else {
                $this->markReportError($report, 'Government API returned error: ' . $response->status() . ' - ' . json_encode($responseData));
                return false;
            }
        } catch (\Exception $e) {
            $this->logApiCall($institution->id, $endpoint, 'POST', $payload, ['error' => $e->getMessage()], 500, $operatorUserId);
            $this->markReportError($report, 'Exception: ' . $e->getMessage());
            return false;
        }
    }

    private function markReportError(PuiReport $report, string $errorMessage)
    {
        // DO NOT change status to FINALIZADO on error.
        $report->update([
            'government_status' => 'ERROR_ENVIO',
            'government_error' => $errorMessage,
        ]);
    }

    private function logApiCall($institutionId, $endpoint, $method, $requestData, $responseData, $statusCode, $operatorUserId = null)
    {
        try {
            GovernmentApiLog::create([
                'institution_id' => $institutionId,
                'endpoint' => $endpoint,
                'method' => $method,
                'request_data' => $requestData,
                'response_data' => $responseData,
                'status_code' => $statusCode,
                'ip_address' => request()->ip(),
                'operator_user_id' => $operatorUserId,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to log government API call: ' . $e->getMessage());
        }
    }
}
