<?php

use App\Http\Requests\TransferStockRequest;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Validator;

function validateTransfer(array $payload): \Illuminate\Validation\Validator
{
    return Validator::make($payload, (new TransferStockRequest)->rules());
}

function validTransferPayload(array $overrides = []): array
{
    return array_merge([
        'source_warehouse_id' => Warehouse::factory()->create()->id,
        'target_warehouse_id' => Warehouse::factory()->create()->id,
        'product_id' => Product::factory()->create()->id,
        'quantity' => 10,
    ], $overrides);
}

it('accepts a fully valid transfer payload', function () {
    expect(validateTransfer(validTransferPayload())->passes())->toBeTrue();
});

it('requires source_warehouse_id, target_warehouse_id, product_id and quantity', function () {
    $validator = validateTransfer([]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->keys())->toContain('source_warehouse_id')
        ->and($validator->errors()->keys())->toContain('target_warehouse_id')
        ->and($validator->errors()->keys())->toContain('product_id')
        ->and($validator->errors()->keys())->toContain('quantity');
});

it('rejects a source_warehouse_id that does not exist', function () {
    $validator = validateTransfer(validTransferPayload(['source_warehouse_id' => 999999]));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('source_warehouse_id'))->toBeTrue();
});

it('rejects a target_warehouse_id that does not exist', function () {
    $validator = validateTransfer(validTransferPayload(['target_warehouse_id' => 999999]));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('target_warehouse_id'))->toBeTrue();
});

it('rejects a product_id that does not exist', function () {
    $validator = validateTransfer(validTransferPayload(['product_id' => 999999]));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('product_id'))->toBeTrue();
});

it('rejects a transfer where source and target warehouse are the same', function () {
    $warehouse = Warehouse::factory()->create();

    $validator = validateTransfer(validTransferPayload([
        'source_warehouse_id' => $warehouse->id,
        'target_warehouse_id' => $warehouse->id,
    ]));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('target_warehouse_id'))->toBeTrue();
});

it('rejects a non-positive quantity', function (int $quantity) {
    $validator = validateTransfer(validTransferPayload(['quantity' => $quantity]));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('quantity'))->toBeTrue();
})->with([
    'zero' => [0],
    'negative' => [-5],
]);

it('rejects a non-integer quantity', function (mixed $quantity) {
    $validator = validateTransfer(validTransferPayload(['quantity' => $quantity]));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('quantity'))->toBeTrue();
})->with([
    'string' => ['ten'],
    'float' => [2.5],
]);

it('authorizes the request (authorization is handled by auth/role middleware)', function () {
    expect((new TransferStockRequest)->authorize())->toBeTrue();
});
