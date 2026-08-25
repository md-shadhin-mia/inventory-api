<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $services = [
            'database' => $this->probe(fn () => DB::connection()->getPdo()),
            'cache' => $this->probe(fn () => Cache::store()->get('health-probe')),
        ];

        $healthy = ! in_array('error', $services, true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'services' => $services,
        ], $healthy ? 200 : 503);
    }

    private function probe(callable $check): string
    {
        try {
            $check();

            return 'ok';
        } catch (Throwable) {
            return 'error';
        }
    }
}
