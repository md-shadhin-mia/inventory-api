<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustStockRequest;
use App\Http\Requests\TransferStockRequest;
use App\Services\Contracts\InventoryServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(private readonly InventoryServiceInterface $inventory) {}

    public function adjust(AdjustStockRequest $request): JsonResponse
    {
        $inventory = $this->inventory->adjustStock(
            $request->user()->id,
            $request->validated('warehouse_id'),
            $request->validated('product_id'),
            $request->validated('quantity_delta'),
            $request->validated('reason'),
        );

        return response()->json([
            'data' => [
                'warehouse_id' => $inventory->warehouse_id,
                'product_id' => $inventory->product_id,
                'quantity' => $inventory->quantity,
            ],
        ]);
    }

    public function transfer(TransferStockRequest $request): JsonResponse
    {
        $result = $this->inventory->transferStock(
            $request->user()->id,
            $request->validated('source_warehouse_id'),
            $request->validated('target_warehouse_id'),
            $request->validated('product_id'),
            $request->validated('quantity'),
        );

        return response()->json([
            'data' => [
                'source_warehouse_id' => $result['source']->warehouse_id,
                'target_warehouse_id' => $result['target']->warehouse_id,
                'product_id' => $result['source']->product_id,
            ],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->inventory->getStockSummary(
                $request->filled('warehouse_id') ? (int) $request->query('warehouse_id') : null,
                $request->filled('product_id') ? (int) $request->query('product_id') : null,
            ),
        ]);
    }
}
