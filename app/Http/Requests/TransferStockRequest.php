<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransferStockRequest extends FormRequest
{

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_warehouse_id' => ['required', 'exists:warehouses,id'],
            'target_warehouse_id' => ['required', 'different:source_warehouse_id', 'exists:warehouses,id'],
            'product_id' => ['required', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
