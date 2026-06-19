<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Institution;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class PuiApiAuthMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = $request->bearerToken();

        if (!$bearerToken) {
            return response()->json(['error' => 'No token provided'], 401);
        }

        try {
            $jwtSecret = env('PUI_INBOUND_JWT_SECRET', env('APP_KEY'));
            $decoded = JWT::decode($bearerToken, new Key($jwtSecret, 'HS256'));
        } catch (\Exception $e) {
            return response()->json(['error' => 'Invalid or expired token'], 401);
        }

        $institution = Institution::find($decoded->tenant_id ?? null);

        if (!$institution || !$institution->is_active) {
            return response()->json(['error' => 'Institution inactive'], 403);
        }

        // Inyectar en el request
        $request->attributes->set('institution', $institution);

        return $next($request);
    }
}
