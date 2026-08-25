<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Public health probe.
 *
 * Deliberately checks the dependencies the API cannot work without, so a green
 * result means "this instance can serve requests" rather than merely "PHP is
 * running". Used by the docker-compose healthcheck.
 */
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

    /** Never let a failing dependency turn the health endpoint itself into a 500. */
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
