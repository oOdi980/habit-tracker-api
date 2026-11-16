<?php

namespace App\Http\Requests\Api;

use App\Support\TaskInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Mengganti SELURUH daftar tugas milik pengguna di sebuah habit (daftar baru = daftar lengkap). */
class PutTasksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tasks' => ['required', 'array', 'min:1', 'max:'.(int) config('habit.task.max_per_user_per_habit')],
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
