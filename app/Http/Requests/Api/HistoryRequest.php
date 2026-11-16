<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class HistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Kursor: hanya hari SEBELUM tanggal ini (eksklusif). Kosong = mulai dari hari ini.
            'before' => ['nullable', 'date_format:Y-m-d'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('habit.task.history_max_days')],
        ];
    }
}
