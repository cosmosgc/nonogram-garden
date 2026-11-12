<?php

namespace App\Http\Requests;

use App\Services\Nonogram\PuzzleImageProcessor;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class StorePuzzleDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->hasFile('source_image') !== $this->hasFile('reveal_image')) {
                $validator->errors()->add('source_image', 'Both the original image and reveal image are required together.');
                $validator->errors()->add('reveal_image', 'Both the original image and reveal image are required together.');
            }
        });
    }

    public function rules(): array
    {
        $imageProcessor = new PuzzleImageProcessor;

        return [
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
            'source_image' => [
                'sometimes',
                'required_with:reveal_image',
                'file',
                'max:10240',
                'mimes:jpg,jpeg,png,webp',
                'extensions:jpg,jpeg,png,webp',
                function (string $attribute, mixed $value, \Closure $fail) use ($imageProcessor): void {
                    if ($value instanceof UploadedFile && ! $imageProcessor->supportsSource($value)) {
                        $fail('The original image must be a valid PNG, JPEG, or WebP file no larger than 4 megapixels.');
                    }
                },
            ],
            'reveal_image' => [
                'sometimes',
                'required_with:source_image',
                'file',
                'max:10240',
                'mimes:png',
                'extensions:png',
                function (string $attribute, mixed $value, \Closure $fail) use ($imageProcessor): void {
                    if ($value instanceof UploadedFile && ! $imageProcessor->supportsRevealCopy($value)) {
                        $fail('The reveal image must be a valid PNG no larger than 4 megapixels.');
                    }
                },
            ],
        ];
    }
}
