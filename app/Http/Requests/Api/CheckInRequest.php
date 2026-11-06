<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class CheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Waktu ISO-8601 DENGAN zona (Z atau +07:00), mis. "2026-11-09T23:58:00+07:00".
            // Tanpa zona, waktunya ambigu dan ditolak. Server hanya memakainya sebagai petunjuk
            // (lihat CircleClock::resolveLogDate), tidak pernah mentah-mentah.
            'client_ts' => [
                'nullable',
                'string',
                'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/',
                'date',
            ],
        ];
    }
}
