<?php

namespace App\Http\Requests\Api;

use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCircleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            // Harus nama zona IANA yang valid (mis. "Asia/Jakarta"). Zona ini dikunci setelah
            // sesi dibuat karena menentukan batas hari dan minggu untuk seluruh isi sesi.
            'timezone' => ['nullable', 'string', Rule::in(DateTimeZone::listIdentifiers())],
        ];
    }
}
