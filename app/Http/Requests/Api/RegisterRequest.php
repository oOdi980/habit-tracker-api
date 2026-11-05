<?php

namespace App\Http\Requests\Api;

use App\Rules\PasswordMaxBytes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    // Email selalu disimpan huruf kecil. Kolom unik di database peka huruf besar/kecil,
    // jadi tanpa ini "Odi@x.com" dan "odi@x.com" bisa jadi dua akun.
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => Str::lower(trim($email))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8), new PasswordMaxBytes],
            'device_name' => ['nullable', 'string', 'max:100'],
            // Wajib untuk akun kedua dan seterusnya; aturannya dicek di AuthService
            // (di dalam kunci pendaftaran, supaya tidak ada celah balapan).
            'invite_code' => ['nullable', 'string', 'max:32'],
        ];
    }
}
