<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $product = $this->route('product');
        $storeId = $product ? $product->store_id : optional($this->user())->store_id;
        $productId = $product ? $product->id : null;

        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'barcode' => [
                'sometimes',
                'nullable',
                'string',
                'max:64',
                Rule::unique('products', 'barcode')
                    ->where(fn ($q) => $q->where('store_id', $storeId))
                    ->ignore($productId),
            ],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'buying_price' => ['sometimes', 'numeric', 'min:0'],
            'stock' => ['sometimes', 'integer', 'min:0'],
            'expiry_date' => ['sometimes', 'nullable', 'date'],
            'category_id' => [
                'sometimes',
                'nullable',
                Rule::exists('categories', 'id')->where(fn ($q) => $q->where('store_id', $storeId)),
            ],
            'supplier_id' => [
                'sometimes',
                'nullable',
                Rule::exists('suppliers', 'id')->where(fn ($q) => $q->where('store_id', $storeId)),
            ],
            'image' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
