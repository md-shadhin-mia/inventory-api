<?php

use App\Services\Contracts\InventoryServiceInterface;
use App\Services\InventoryService;

it('resolves InventoryServiceInterface to an InventoryService instance', function () {
    $service = app(InventoryServiceInterface::class);

    expect($service)->toBeInstanceOf(InventoryService::class);
});

it('resolves the interface consistently to the same concrete class on every resolution', function () {

    expect(app(InventoryServiceInterface::class))->toBeInstanceOf(InventoryService::class)
        ->and(app(InventoryServiceInterface::class))->toBeInstanceOf(InventoryService::class);
});

it('declares the full service contract on the interface', function () {
    $reflection = new ReflectionClass(InventoryServiceInterface::class);

    expect($reflection->isInterface())->toBeTrue()
        ->and($reflection->hasMethod('adjustStock'))->toBeTrue()
        ->and($reflection->hasMethod('transferStock'))->toBeTrue()
        ->and($reflection->hasMethod('getStockSummary'))->toBeTrue();
});

it('implements the interface on the concrete service', function () {
    expect(is_subclass_of(InventoryService::class, InventoryServiceInterface::class))->toBeTrue();
});
