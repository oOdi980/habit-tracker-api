<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/** "Ubah angka": total hari ini diganti persis dengan angka ini. 0 = kosongkan (batal ceklis). */
class SetProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:0'],
            'weight_g' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('habit.task.max_weight_g')],
        ];
    }
}
