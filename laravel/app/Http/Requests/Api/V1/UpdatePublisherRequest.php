<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Publisher;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePublisherRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Publisher|null $publisher */
        $publisher = $this->route('publisher');

        return $publisher && $this->user()?->can('update', $publisher);
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
