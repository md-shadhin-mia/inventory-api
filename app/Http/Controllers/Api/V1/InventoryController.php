<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustStockRequest;
use App\Http\Requests\TransferStockRequest;
use App\Services\Contracts\InventoryServiceInterface;
use Illuminate\Http\JsonResponse;

class InventoryController extends Controller
{
    public function __construct(private readonly InventoryServiceInterface $inventory) {}

    /**
     * Adjust stock for a warehouse/product pair.
     */
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

    /**
     * Transfer stock between two warehouses atomically.
     */
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

    /**
     * Stock levels per warehouse and product.
     */
    public function summary(): JsonResponse
    {
        return response()->json(['data' => $this->inventory->getStockSummary()]);
    }
}
