<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Services\Profile\AvatarImageProcessor;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('username'))) {
            $this->merge(['username' => mb_strtolower(trim($this->input('username')))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $avatarImageProcessor = new AvatarImageProcessor;

        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'nullable',
                'string',
                'min:3',
                'max:30',
                'regex:/\\A[a-z0-9_]+\\z/',
                Rule::notIn(['admin', 'api', 'dashboard', 'editor', 'login', 'logout', 'profile', 'puzzles', 'register', 'support', 'system']),
                Rule::unique(User::class, 'username')->ignore($this->user()->id),
            ],
            'bio' => ['nullable', 'string', 'max:500'],
            'avatar' => [
                'nullable',
                'file',
                'max:2048',
                'extensions:jpg,jpeg,png,webp',
                function (string $attribute, mixed $value, \Closure $fail) use ($avatarImageProcessor): void {
                    if ($value instanceof UploadedFile && ! $avatarImageProcessor->supports($value)) {
                        $fail('The avatar must be a JPEG, PNG, or WebP image no larger than 1024 by 1024 pixels.');
                    }
                },
            ],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
        ];
    }
}
