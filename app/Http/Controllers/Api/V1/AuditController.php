<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryTransaction;
use Illuminate\Http\JsonResponse;

class AuditController extends Controller
{

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => InventoryTransaction::query()->latest('id')->get(),
        ]);
    }
}
