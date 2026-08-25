<?php

use App\Http\Requests\AdjustStockRequest;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Validator;

function validateAdjust(array $payload): \Illuminate\Validation\Validator
{
    return Validator::make($payload, (new AdjustStockRequest)->rules());
}

function validAdjustPayload(array $overrides = []): array
{
    return array_merge([
        'warehouse_id' => Warehouse::factory()->create()->id,
        'product_id' => Product::factory()->create()->id,
        'quantity_delta' => -7,
        'reason' => 'damaged goods write-off',
    ], $overrides);
}

it('accepts a fully valid adjust payload', function () {
    expect(validateAdjust(validAdjustPayload())->passes())->toBeTrue();
});

it('accepts a positive quantity_delta (stock increase)', function () {
    expect(validateAdjust(validAdjustPayload(['quantity_delta' => 25]))->passes())->toBeTrue();
});

it('requires warehouse_id, product_id, quantity_delta and reason', function () {
    $validator = validateAdjust([]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->keys())->toContain('warehouse_id')
        ->and($validator->errors()->keys())->toContain('product_id')
        ->and($validator->errors()->keys())->toContain('quantity_delta')
        ->and($validator->errors()->keys())->toContain('reason');
});

it('rejects a warehouse_id that does not exist in the warehouses table', function () {
    $validator = validateAdjust(validAdjustPayload(['warehouse_id' => 999999]));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('warehouse_id'))->toBeTrue();
});

it('rejects a product_id that does not exist in the products table', function () {
    $validator = validateAdjust(validAdjustPayload(['product_id' => 999999]));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('product_id'))->toBeTrue();
});

it('rejects a non-integer quantity_delta', function (mixed $delta) {
    $validator = validateAdjust(validAdjustPayload(['quantity_delta' => $delta]));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('quantity_delta'))->toBeTrue();
})->with([
    'string' => ['seven'],
    'float' => [3.5],
    'array' => [[7]],
]);

it('rejects a non-string reason', function () {
    $validator = validateAdjust(validAdjustPayload(['reason' => ['not', 'a', 'string']]));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('reason'))->toBeTrue();
});

it('authorizes the request (authorization is handled by auth/role middleware)', function () {
    expect((new AdjustStockRequest)->authorize())->toBeTrue();
});
