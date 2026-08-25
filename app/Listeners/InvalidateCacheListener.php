<?php

namespace App\Listeners;

use App\Events\StockLevelChangedEvent;
use App\Models\Inventory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

class InvalidateCacheListener implements ShouldQueue
{
    use InteractsWithQueue;

    public $connection = 'redis';

    public function handle(StockLevelChangedEvent $event): void
    {
        Cache::forget(Inventory::cacheKey($event->warehouseId, $event->productId));
    }
}
