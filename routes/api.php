<?php

use App\Http\Controllers\Api\V1\AuditController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InventoryController;
use Illuminate\Support\Facades\Route;

// Public: an orchestrator probe must not need a token.
Route::get('v1/health', HealthController::class);

Route::prefix('v1/auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth-limiter');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::post('inventory/adjust', [InventoryController::class, 'adjust'])->middleware(['role:admin,warehouse_manager', 'throttle:inventory-mutation']);
    Route::post('inventory/transfer', [InventoryController::class, 'transfer'])->middleware(['role:admin,warehouse_manager', 'throttle:inventory-mutation']);
    Route::get('inventory/summary', [InventoryController::class, 'summary']);
    Route::get('audit/transactions', [AuditController::class, 'index'])->middleware('role:admin,auditor');
});
