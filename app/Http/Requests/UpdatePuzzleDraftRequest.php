<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePuzzleDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'title' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'tags' => ['sometimes', 'array', 'max:8'],
            'tags.*' => ['string', 'max:24', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', 'distinct'],
            'grid' => ['required', 'array:version,mode,rules_version,width,height,cells,palette'],
            'grid.version' => ['sometimes', 'integer', 'in:1'],
            'grid.mode' => ['sometimes', 'string', 'in:classic,color'],
            'grid.rules_version' => ['sometimes', 'integer', 'in:1'],
            'grid.width' => ['required', 'integer', 'between:1,30'],
            'grid.height' => ['required', 'integer', 'between:1,30'],
            'grid.cells' => ['required', 'string', 'max:900'],
            'grid.palette' => ['sometimes', 'array', 'min:1', 'max:8'],
            'grid.palette.*' => ['required', 'array:name,hex'],
            'grid.palette.*.name' => ['required', 'string', 'max:40'],
            'grid.palette.*.hex' => ['required', 'regex:/\A#[0-9A-F]{6}\z/'],
        ];
    }
}
