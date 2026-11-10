<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Format value bergantung pada period. Rentang tahun dibatasi 2000-2099 supaya input
        // asal-asalan tidak sampai ke query.
        $valueRule = $this->input('period') === 'year'
            ? 'regex:/^20\d{2}$/'
            : 'regex:/^20\d{2}-(0[1-9]|1[0-2])$/';

        return [
            'period' => ['required', 'in:month,year'],
            'value' => ['nullable', 'string', $valueRule],
        ];
    }
}
