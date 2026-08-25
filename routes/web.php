<?php

use Illuminate\Support\Facades\Route;

/*
 * This project is a pure JSON REST API — every functional route lives in
 * routes/api.php. The only web route is `/`, which forwards browsers to the
 * Swagger UI rendered from openapi/openapi.yaml, replacing the stock Laravel
 * welcome page (which pulled in a Vite asset build the API has no use for).
 *
 * Use GET /api/v1/health for an application-level probe (it checks PostgreSQL
 * and the cache store), or GET /up for the framework's built-in liveness
 * endpoint.
 */

Route::redirect('/', '/api/documentation');
