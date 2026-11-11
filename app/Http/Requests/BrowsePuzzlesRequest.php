<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BrowsePuzzlesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'width' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:30'],
            'height' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:30'],
            'creator' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/\A[a-zA-Z0-9_]+\z/'],
            'tag' => ['sometimes', 'nullable', 'string', 'max:24', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            'sort' => ['sometimes', 'nullable', Rule::in(['newest', 'oldest'])],
        ];
    }
}
