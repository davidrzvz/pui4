<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Institution;
use App\Models\PuiApiToken;
use App\Models\PuiReport;
use App\Models\PuiReportMatchCheck;
use App\Models\ClientRecord;
use App\Models\GovernmentApiLog;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Firebase\JWT\JWT;

class PuiApiController extends Controller
{
    private function logApiCall(Request $request, $statusCode, $responsePayload, $institutionId = null)
    {
        GovernmentApiLog::create([
            'institution_id' => $institutionId,
            'endpoint' => $request->path(),
            'method' => $request->method(),
            'ip_address' => $request->ip(),
            'status_code' => $statusCode,
            'request_data' => $request->except(['clave']), // Hide sensitive data
            'response_data' => $responsePayload,
        ]);
    }

    public function login(Request $request)
    {
        $request->validate([
            'usuario' => 'required|string',
            'clave' => 'required|string',
        ]);

        $usuario = $request->usuario;
        $clave = $request->clave;

        $expectedUser = env('PUI_INBOUND_USER', 'PUI');
        $expectedPass = env('PUI_INBOUND_PASSWORD');
        $jwtSecret = env('PUI_INBOUND_JWT_SECRET', env('APP_KEY'));

        if ($usuario !== $expectedUser || $clave !== $expectedPass) {
            $response = ['error' => 'Credenciales inválidas'];
            $this->logApiCall($request, 401, $response);
            return response()->json($response, 401);
        }

        // Obtener institución por defecto o contexto para JWT
        $institution = Institution::where('is_active', true)->first();

        $payload = [
            'tenant_id' => $institution ? $institution->id : null,
            'institution_id' => $usuario,
            'iat' => time(),
            'exp' => time() + 3600,
        ];

        $jwt = JWT::encode($payload, $jwtSecret, 'HS256');

        $response = [
            'token' => $jwt
        ];

        $this->logApiCall($request, 200, $response, $institution ? $institution->id : null);

        return response()->json($response, 200);
    }

    public function activarReporte(Request $request)
    {
        return $this->handleActivarReporte($request, false);
    }

    public function activarReportePrueba(Request $request)
    {
        return $this->handleActivarReporte($request, true);
    }

    private function handleActivarReporte(Request $request, bool $isTest)
    {
        $institution = $request->attributes->get('institution');

        if ($request->has('curp')) {
            $request->merge(['curp' => strtoupper(trim($request->curp))]);
        }

        $request->validate([
            'id' => 'required|string|min:36|max:75',
            'curp' => ['required', 'string', 'size:18', 'regex:/^[A-Z0-9]{18}$/'],
            'lugar_nacimiento' => 'present|nullable|string|max:20',
            'nombre' => 'nullable|string|max:50',
            'primer_apellido' => 'nullable|string|max:50',
            'segundo_apellido' => 'nullable|string|max:50',
            'fecha_nacimiento' => 'nullable|date_format:Y-m-d',
            'fecha_desaparicion' => 'nullable|date_format:Y-m-d',
            'sexo_asignado' => 'nullable|in:H,M,X',
            'telefono' => 'nullable|string|max:15',
            'correo' => 'nullable|email|max:50',
            'direccion' => 'nullable|string|max:500',
            'calle' => 'nullable|string|max:50',
            'numero' => 'nullable|string|max:20',
            'colonia' => 'nullable|string|max:50',
            'codigo_postal' => 'nullable|string|max:5',
            'municipio_o_alcaldia' => 'nullable|string|max:100',
            'entidad_federativa' => 'nullable|string|max:40',
        ], [
            'curp.regex' => 'CURP inválida. Debe contener exactamente 18 caracteres usando únicamente letras mayúsculas y números.',
        ]);

        $curp = $request->curp;

        try {
            $clientRecord = ClientRecord::where('institution_id', $institution->id)
                ->where('curp', $curp)
                ->first();

            $matchStatus = $clientRecord ? 'COINCIDENCIA_SUGERIDA' : 'SIN_COINCIDENCIA_SUGERIDA';

            $report = PuiReport::create([
                'institution_id' => $institution->id,
                'client_record_id' => $clientRecord ? $clientRecord->id : null,
                'curp' => $curp,
                'external_id' => $request->id,
                'status' => 'PENDIENTE_REVISION',
                'match_status' => $matchStatus,
                'matched_csv_import_batch_id' => $clientRecord ? $clientRecord->csv_import_batch_id : null,
                'request_payload' => $request->all(),
                'response_payload' => ['message' => 'La solicitud de activación del reporte de búsqueda se recibió correctamente.'],
                'activated_at' => now(),
                'is_test' => $isTest,
            ]);

            PuiReportMatchCheck::create([
                'pui_report_id' => $report->id,
                'institution_id' => $institution->id,
                'csv_import_batch_id' => $clientRecord ? $clientRecord->csv_import_batch_id : null,
                'client_record_id' => $clientRecord ? $clientRecord->id : null,
                'match_status' => $matchStatus,
                'checked_at' => now(),
                'checked_by' => null,
                'notes' => 'Evaluación inicial automática al recibir activación PUI.',
            ]);

            $response = [
                'message' => 'La solicitud de activación del reporte de búsqueda se recibió correctamente.'
            ];

            $this->logApiCall($request, 200, $response, $institution->id);

            return response()->json($response, 200);

        } catch (\Exception $e) {
            $response = ['error' => 'Error al procesar el reporte', 'message' => $e->getMessage()];
            $this->logApiCall($request, 500, $response, $institution->id);
            return response()->json($response, 500);
        }
    }

    public function desactivarReporte(Request $request)
    {
        $institution = $request->attributes->get('institution');

        $request->validate([
            'id' => 'required|string',
        ]);

        $externalId = $request->id;

        $report = PuiReport::where('institution_id', $institution->id)
            ->where('external_id', $externalId)
            ->latest()
            ->first();

        if (!$report) {
            $response = ['error' => 'Reporte activo no encontrado.'];
            $this->logApiCall($request, 404, $response, $institution->id);
            return response()->json($response, 404);
        }

        $report->update([
            'status' => 'DESACTIVADO',
            'deactivated_at' => now(),
            'request_payload' => array_merge($report->request_payload ?? [], ['desactivar_request' => $request->all()]),
        ]);

        $response = [
            'message' => 'La solicitud de desactivación del reporte de búsqueda se recibió correctamente.'
        ];

        $this->logApiCall($request, 200, $response, $institution->id);

        return response()->json($response, 200);
    }
}
