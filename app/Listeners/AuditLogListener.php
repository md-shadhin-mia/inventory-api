<?php

namespace App\Listeners;

use App\Events\StockLevelChangedEvent;
use App\Models\InventoryTransaction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class AuditLogListener implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * The queue connection the listener is pushed onto.
     *
     * @var string
     */
    public $connection = 'redis';

    /**
     * Write the immutable audit trail row for a stock level change.
     */
    public function handle(StockLevelChangedEvent $event): void
    {
        InventoryTransaction::create([
            'user_id' => $event->userId,
            'warehouse_id' => $event->warehouseId,
            'product_id' => $event->productId,
            'old_balance' => $event->oldBalance,
            'new_balance' => $event->newBalance,
            'quantity_delta' => $event->newBalance - $event->oldBalance,
            'type' => $event->type,
            'reason' => $event->reason,
            'created_at' => now(),
        ]);
    }
}
