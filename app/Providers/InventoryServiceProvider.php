<?php

namespace App\Providers;

use App\Services\Contracts\InventoryServiceInterface;
use App\Services\InventoryService;
use Illuminate\Support\ServiceProvider;

class InventoryServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(InventoryServiceInterface::class, InventoryService::class);
    }
}
