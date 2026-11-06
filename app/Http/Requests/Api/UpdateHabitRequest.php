<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateHabitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    // Sedikitnya salah satu harus ada. Scope, aturan, dan pemilik sengaja TIDAK bisa diubah:
    // itu mengubah arti habit. Hapus lalu buat baru.
    public function rules(): array
    {
        return [
            'title' => ['required_without:target_per_week', 'string', 'max:120'],
            'target_per_week' => ['required_without:title', 'integer', 'between:1,7'],
        ];
    }
}
