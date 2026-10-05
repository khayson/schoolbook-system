<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttachProductCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $product = Product::query()->find($this->integer('product_id'));

        return $product !== null
            ? ($this->user()?->can('update', $product) ?? false)
            : ($this->user()?->can('viewAny', Product::class) ?? false); // let validation report the bad id
    }

    public function rules(): array
    {
        return [
            // Printable, no control characters; spaces and hyphens are ignored when stored.
            'code' => ['required', 'string', 'min:4', 'max:64', 'regex:/^[A-Za-z0-9\- ]+$/'],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')],
        ];
    }

    public function messages(): array
    {
        return ['code.regex' => 'A code has letters, digits, spaces or hyphens only.'];
    }
}
