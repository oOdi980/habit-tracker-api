<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class NoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 'present' + 'nullable': kunci wajib dikirim, null/"" berarti hapus catatan.
            'note' => ['present', 'nullable', 'string', 'max:'.(int) config('habit.task.note_max')],
        ];
    }
}
