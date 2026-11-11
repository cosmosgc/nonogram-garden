<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncCollectionPuzzlesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'positions' => ['sometimes', 'array', 'max:100'],
            'positions.*' => ['required', 'integer', 'min:0', 'max:100'],
        ];
    }
}
