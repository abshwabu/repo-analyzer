<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(): JsonResponse
    {
        $dbStatus = 'ok';
        $dbMessage = 'connected';

        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $dbStatus = 'unreachable';
            $dbMessage = $e->getMessage();
        }

        $healthy = ($dbStatus === 'ok');

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'service' => config('app.name', 'repo-analyzer'),
            'version' => 'v1',
            'timestamp' => now()->toIso8601String(),
            'checks' => [
                'database' => [
                    'status' => $dbStatus,
                    'message' => $dbMessage,
                ],
            ],
        ], $healthy ? 200 : 503);
    }
}
