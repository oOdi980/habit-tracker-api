<?php

namespace App\Support;

use App\Models\HabitTask;

/**
 * Pembantu baris tugas tanpa dependensi lain (sengaja tidak memakai service) supaya HabitService
 * dan TaskService sama-sama bisa memakainya tanpa saling bergantung.
 */
final class TaskRows
{
    /**
     * Ubah input tervalidasi [{name, metric, target}, ...] menjadi baris siap simpan.
     * Urutan input menjadi urutan tampil (position).
     *
     * @param  array<int, array{name: string, metric: string, target: int|string}>  $tasks
     * @return list<array{name: string, name_key: string, metric: string, target: int, position: int}>
     */
    public static function normalize(array $tasks): array
    {
        $rows = [];

        foreach (array_values($tasks) as $position => $task) {
            $name = TaskName::clean((string) $task['name']);

            $rows[] = [
                'name' => $name,
                'name_key' => TaskName::key($name),
                'metric' => (string) $task['metric'],
                'target' => (int) $task['target'],
                'position' => $position,
            ];
        }

        return $rows;
    }

    /**
     * Simpan baris hasil normalize() untuk satu orang. $validFrom = null berarti "sejak habit dibuat".
     * Satu INSERT untuk semuanya (maksimal 10 baris).
     *
     * @param  list<array{name: string, name_key: string, metric: string, target: int, position: int}>  $rows
     */
    public static function insert(int $habitId, int $userId, array $rows, ?string $validFrom): void
    {
        if ($rows === []) {
            return;
        }

        $now = now();

        HabitTask::query()->insert(array_map(fn (array $row) => [
            'habit_id' => $habitId,
            'user_id' => $userId,
            'name' => $row['name'],
            'name_key' => $row['name_key'],
            'metric' => $row['metric'],
            'target' => $row['target'],
            'position' => $row['position'],
            'valid_from' => $validFrom,
            'valid_until' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows));
    }
}
