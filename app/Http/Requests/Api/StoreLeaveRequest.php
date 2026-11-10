<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Klien memilih "minggu ini" atau "minggu depan", bukan mengirim tanggal: server yang
        // menghitung Senin-nya (zona waktu sesi), jadi tidak ada tanggal salah/jebakan dari klien.
        return [
            'week' => ['required', 'in:current,next'],
        ];
    }
}
