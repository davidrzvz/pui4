@php
    $record = $record ?? (isset($getRecord) && is_callable($getRecord) ? $getRecord() : null);
    $isTest = $record->is_test ? 'Solicitud de prueba' : 'Solicitud real';
    $isTestColor = $record->is_test ? 'text-warning-600' : 'text-success-600';
    
    $matchStatus = match($record->match_status) {
        'COINCIDENCIA_SUGERIDA' => 'Coincidencia sugerida',
        'SIN_COINCIDENCIA_SUGERIDA' => 'Sin coincidencia sugerida',
        null => 'Pendiente de evaluación',
        default => $record->match_status,
    };
    $matchColor = match($record->match_status) {
        'COINCIDENCIA_SUGERIDA' => 'text-success-600 font-bold',
        'SIN_COINCIDENCIA_SUGERIDA' => 'text-danger-600 font-bold',
        null => 'text-warning-600 font-bold',
        default => 'text-gray-600',
    };
    
    $clientLabel = $record->clientRecord ? $record->clientRecord->internal_identifier : 'No se encontró coincidencia en el padrón actual';
    $batchLabel = $record->csvImportBatch ? $record->csvImportBatch->id : '';
    $evaluatedAt = $record->match_checked_at ? $record->match_checked_at->format('d/m/Y H:i') : '';

    // Timeline events
    $timeline = [];

    // 1. Recepción
    if ($record->created_at) {
        $timeline[] = [
            'date' => $record->created_at,
            'icon' => '📥',
            'title' => 'Reporte recibido desde Gobierno',
            'description' => 'ID: ' . $record->external_id,
        ];
    }

    // 2. Búsqueda
    if ($record->match_checked_at) {
        $timeline[] = [
            'date' => $record->match_checked_at,
            'icon' => '🤖',
            'title' => 'Motor PUI ejecutó búsqueda automática',
            'description' => 'Resultado: ' . ($record->match_status === 'COINCIDENCIA_SUGERIDA' ? 'COINCIDENCIA' : 'SIN COINCIDENCIA'),
        ];
    }

    // 3. Comunicación con Gobierno
    $govLogs = $record->governmentApiLogs()->latest()->get();
    foreach ($govLogs as $log) {
        $icon = $log->http_status >= 200 && $log->http_status < 300 ? '✅' : '⚠️';
        $timeline[] = [
            'date' => $log->created_at,
            'icon' => '📤',
            'title' => 'Comunicación con Gobierno: ' . $log->endpoint,
            'description' => 'HTTP ' . $log->http_status . ' ' . $icon . ' - ' . $log->method,
        ];
    }

    // 4. Auditoría (Acciones manuales)
    if (\Illuminate\Support\Facades\Schema::hasColumn('audit_logs', 'auditable_type')) {
        $auditLogs = \App\Models\AuditLog::where('auditable_type', \App\Models\PuiReport::class)
            ->where('auditable_id', $record->id)
            ->with('user')
            ->get();
            
        foreach ($auditLogs as $log) {
            $timeline[] = [
                'date' => $log->created_at,
                'icon' => '👤',
                'title' => 'Acción manual: ' . ($log->event ?? 'Desconocida'),
                'description' => 'Por: ' . ($log->user ? $log->user->name : 'Sistema'),
            ];
        }
    }

    // 5. Desactivación
    if ($record->status === 'DESACTIVADO' && $record->deactivated_at) {
        $timeline[] = [
            'date' => $record->deactivated_at,
            'icon' => '🔒',
            'title' => 'Gobierno desactivó reporte',
            'description' => 'El registro fue marcado como inactivo.',
        ];
    }

    usort($timeline, function($a, $b) {
        return $a['date'] <=> $b['date'];
    });
@endphp

<div class="space-y-6">
    <!-- Datos de Gobierno -->
    <div class="p-6 bg-white dark:bg-gray-900 rounded-xl shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10">
        <h2 class="text-lg font-medium tracking-tight text-gray-950 dark:text-white mb-4">Datos de Gobierno</h2>
        <div class="space-y-4">
            <div>
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Tipo:</span>
                <span class="ml-2 font-semibold {{ $isTestColor }}">{{ $isTest }}</span>
            </div>
            
            <div>
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400 block mb-2">Payload Completo (JSON):</span>
                <pre class="bg-gray-100 dark:bg-gray-800 p-4 rounded-lg overflow-x-auto text-sm text-gray-900 dark:text-gray-100"><code>{{ is_array($record->request_payload) ? json_encode($record->request_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $record->request_payload }}</code></pre>
            </div>
            
            <div>
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400 block mb-2">Respuesta API (JSON):</span>
                @if($record->response_payload)
                    <pre class="bg-gray-100 dark:bg-gray-800 p-4 rounded-lg overflow-x-auto text-sm text-gray-900 dark:text-gray-100"><code>{{ is_array($record->response_payload) ? json_encode($record->response_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $record->response_payload }}</code></pre>
                @else
                    <p class="text-sm text-gray-500 dark:text-gray-400">Sin respuesta</p>
                @endif
            </div>
        </div>
    </div>

    <!-- Evaluación Interna -->
    <div class="p-6 bg-white dark:bg-gray-900 rounded-xl shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10">
        <h2 class="text-lg font-medium tracking-tight text-gray-950 dark:text-white mb-4">Evaluación Interna</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400 block">Estatus del Cruce:</span>
                <span class="mt-1 block {{ $matchColor }}">{{ $matchStatus }}</span>
            </div>
            <div>
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400 block">Cliente Encontrado:</span>
                <span class="mt-1 block text-gray-900 dark:text-white">{{ $clientLabel }}</span>
            </div>
            <div>
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400 block">Lote CSV Asociado:</span>
                <span class="mt-1 block text-gray-900 dark:text-white">{{ $batchLabel }}</span>
            </div>
            <div>
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400 block">Fecha de Evaluación:</span>
                <span class="mt-1 block text-gray-900 dark:text-white">{{ $evaluatedAt }}</span>
            </div>
        </div>
    </div>

    <!-- Historial de Revisiones -->
    <div class="p-6 bg-white dark:bg-gray-900 rounded-xl shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10">
        <h2 class="text-lg font-medium tracking-tight text-gray-950 dark:text-white mb-4">Historial de Revisiones</h2>
        
        @if(!$record->matchChecks || $record->matchChecks->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">Sin historial de revisiones.</p>
        @else
            <div class="space-y-4">
                @foreach($record->matchChecks as $check)
                    @php
                        $date = $check->checked_at ? $check->checked_at->format('d/m/Y H:i') : 'N/A';
                        $checker = $check->checker ? $check->checker->name : 'Sistema Automático';
                        $statusText = $check->match_status == 'COINCIDENCIA_SUGERIDA' ? 'Coincidencia sugerida' : 'Sin coincidencia sugerida';
                        $statusClass = $check->match_status == 'COINCIDENCIA_SUGERIDA' ? 'text-success-600 font-bold' : 'text-danger-600 font-bold';
                    @endphp
                    <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg text-sm">
                        <p><strong>Fecha:</strong> {{ $date }} &nbsp;|&nbsp; <strong>Revisor:</strong> {{ $checker }} &nbsp;|&nbsp; <strong>Resultado:</strong> <span class="{{ $statusClass }}">{{ $statusText }}</span></p>
                        @if($check->clientRecord)
                            <p class="mt-1"><strong>Cliente:</strong> {{ $check->clientRecord->internal_identifier }}</p>
                        @endif
                        @if($check->notes)
                            <p class="mt-2 text-gray-600 dark:text-gray-300"><strong>Notas:</strong> {{ $check->notes }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <!-- Timeline de Auditoría -->
    <div class="p-6 bg-white dark:bg-gray-900 rounded-xl shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10">
        <h2 class="text-lg font-medium tracking-tight text-gray-950 dark:text-white mb-4">Historial de actividad (Auditoría PUI)</h2>
        <div class="relative border-l border-gray-200 dark:border-gray-700 ml-3">
            @foreach($timeline as $event)
                <div class="mb-6 ml-6">
                    <span class="absolute flex items-center justify-center w-8 h-8 bg-gray-100 rounded-full -left-4 ring-4 ring-white dark:ring-gray-900 dark:bg-gray-700 text-lg">
                        {{ $event['icon'] }}
                    </span>
                    <h3 class="flex items-center mb-1 text-md font-semibold text-gray-900 dark:text-white">{{ $event['title'] }}</h3>
                    <time class="block mb-2 text-sm font-normal leading-none text-gray-400 dark:text-gray-500">{{ $event['date']->format('d/m/Y H:i:s') }}</time>
                    <p class="mb-4 text-sm font-normal text-gray-500 dark:text-gray-400">{!! nl2br(e($event['description'])) !!}</p>
                </div>
            @endforeach
        </div>
    </div>
</div>
