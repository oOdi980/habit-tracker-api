<?php

namespace App\Http\Requests\Api;

use App\Enums\HabitScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHabitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'scope' => ['required', Rule::enum(HabitScope::class)],
            // 7 = setiap hari. Maksimal 7 karena satu hari hanya bisa dicentang sekali.
            'target_per_week' => ['required', 'integer', 'between:1,7'],
        ];
    }
}
