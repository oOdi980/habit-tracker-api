<?php

namespace App\Http\Requests\Api;

use App\Enums\HabitMode;
use App\Enums\HabitScope;
use App\Support\TaskInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreHabitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $checklist = HabitMode::Checklist->value;

        return [
            'title' => ['required', 'string', 'max:120'],
            'scope' => ['required', Rule::enum(HabitScope::class)],
            // 7 = setiap hari. Maksimal 7 karena satu hari hanya bisa dicentang sekali.
            'target_per_week' => ['required', 'integer', 'between:1,7'],

            // Tanpa 'mode' = 'simple' (perilaku lama tetap jalan). 'checklist' wajib membawa daftar
            // tugas milik pembuat habit; 'simple' dilarang membawanya.
            'mode' => ['sometimes', Rule::enum(HabitMode::class)],
            'tasks' => [
                Rule::requiredIf(fn () => $this->input('mode') === $checklist),
                Rule::prohibitedIf(fn () => $this->input('mode', HabitMode::Simple->value) !== $checklist),
                'array',
                'min:1',
                'max:'.(int) config('habit.task.max_per_user_per_habit'),
            ],
        ] + TaskInput::itemRules('tasks');
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [
            fn (Validator $validator) => TaskInput::validateList($validator, 'tasks', $this->input('tasks')),
        ];
    }
}
