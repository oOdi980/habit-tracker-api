<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class EvaluationIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Jumlah minggu terakhir yang ditampilkan.
            'limit' => ['nullable', 'integer', 'between:1,'.(int) config('habit.evaluation.max_list_weeks')],
        ];
    }
}
