<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ModeratePuzzleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) ($this->user()?->is_moderator || $this->user()?->is_admin);
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['approve', 'reject', 'remove'])],
            'note' => ['nullable', 'string', 'max:500', 'required_if:action,reject,remove'],
        ];
    }
}
