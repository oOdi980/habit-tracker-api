<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Tambah progres sebuah tugas. Dua bentuk (tepat salah satu):
 *   {"complete": true}  -> tombol ceklis: tercatat sebesar SISA target hari ini
 *   {"amount": 10}      -> angka sendiri (boleh dicicil, boleh melebihi target)
 */
class AddEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required_without:complete', 'prohibits:complete', 'integer', 'min:1'],
            // 'sometimes' penting: 'accepted' adalah aturan implisit (berjalan walau kolomnya tidak dikirim),
            // jadi tanpa 'sometimes' setiap permintaan {amount} gagal. "Tepat salah satu" dijaga oleh
            // amount: required_without:complete (keduanya kosong = gagal) dan prohibits:complete (keduanya ada = gagal).
            'complete' => ['sometimes', 'accepted'],
            // Gram. Hanya untuk tugas jenis 'count' (dicek di service karena butuh jenis tugasnya).
            'weight_g' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('habit.task.max_weight_g')],
            // Kunci idempotensi dari klien (mis. UUID). Kirim ulang dengan kunci sama = tidak dobel.
            'client_key' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            // Sama seperti check-in: ISO-8601 DENGAN zona, hanya petunjuk untuk antrean offline.
            'client_ts' => [
                'nullable',
                'string',
                'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/',
                'date',
            ],
        ];
    }
}
