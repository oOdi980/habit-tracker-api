<?php

namespace App\Support;

use App\Enums\TaskMetric;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Aturan validasi daftar tugas, dipakai bersama oleh pembuatan habit (StoreHabitRequest)
 * dan penggantian daftar tugas (PutTasksRequest) supaya keduanya tidak bisa berbeda.
 */
final class TaskInput
{
    /** Aturan per butir. Contoh: itemRules('tasks') menghasilkan aturan untuk tasks.*.name dst. */
    public static function itemRules(string $field): array
    {
        return [
            // Tanpa karakter kontrol. Flag /u sekaligus menolak teks yang bukan UTF-8 valid.
            "{$field}.*.name" => ['required', 'string', 'max:'.(int) config('habit.task.name_max'), 'regex:/^[^\x00-\x1F\x7F]+$/u'],
            "{$field}.*.metric" => ['required', Rule::enum(TaskMetric::class)],
            // Batas atas bergantung jenis ukuran, diperiksa di validateList().
            "{$field}.*.target" => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * Pemeriksaan yang butuh melihat seluruh daftar: batas target per jenis ukuran dan nama kembar.
     * Dipanggil dari after() di FormRequest, yang tetap berjalan walau aturan lain sudah gagal,
     * jadi semuanya diperiksa defensif.
     */
    public static function validateList(Validator $validator, string $field, mixed $items): void
    {
        if (! is_array($items)) {
            return;
        }

        $seen = [];

        foreach (array_values($items) as $i => $item) {
            if (! is_array($item)) {
                continue;
            }

            $metric = is_string($item['metric'] ?? null) ? TaskMetric::tryFrom($item['metric']) : null;
            $target = $item['target'] ?? null;

            if ($metric !== null && is_numeric($target) && (int) $target > $metric->maxAmount()) {
                $validator->errors()->add("{$field}.{$i}.target", 'Target terlalu besar untuk jenis ukuran ini (maksimal '.$metric->maxAmount().').');
            }

            if (is_string($item['name'] ?? null)) {
                $key = TaskName::key($item['name']);

                if ($key !== '' && isset($seen[$key])) {
                    $validator->errors()->add("{$field}.{$i}.name", 'Nama tugas tidak boleh kembar.');
                }

                $seen[$key] = true;
            }
        }
    }
}
