<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdjustStockRequest extends FormRequest
{

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'product_id' => ['required', 'exists:products,id'],
            'quantity_delta' => ['required', 'integer'],
            'reason' => ['required', 'string'],
        ];
    }
}
