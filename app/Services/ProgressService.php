<?php

namespace App\Services;

use App\Enums\HabitScope;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Support\CircleClock;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Progres minggu berjalan, SELALU dihitung langsung dari habit_logs (tidak ada angka tersimpan
 * yang bisa meleset dari log). Satu query untuk semua habit sekaligus.
 */
class ProgressService
{
    /**
     * @param  Collection<int, Habit>  $habits
     * @param  int[]  $memberIds  semua anggota sesi (menentukan kapan sebuah hari "together" lengkap)
     * @return array<int, array{done: int, target: int, achieved: bool, dates_by_user: array<int, string[]>}> dikunci per id habit
     */
    public function weekProgress(Collection $habits, array $memberIds, string $weekStart): array
    {
        if ($habits->isEmpty()) {
            return [];
        }

        $weekEnd = CircleClock::addDays($weekStart, 6);

        $logsByHabit = HabitLog::query()
            ->whereIn('habit_id', $habits->pluck('id'))
            ->whereBetween('log_date', [$weekStart, $weekEnd])
            ->get(['habit_id', 'user_id', 'log_date'])
            ->groupBy('habit_id');

        $result = [];

        foreach ($habits as $habit) {
            $datesByUser = ($logsByHabit->get($habit->id) ?? collect())
                ->groupBy('user_id')
                ->map(fn ($rows) => $rows->pluck('log_date')->unique()->values()->all())
                ->all();

            if ($habit->scope === HabitScope::Shared) {
                $done = $this->togetherDays($datesByUser, $memberIds);
            } else {
                $done = count($datesByUser[(int) $habit->owner_id] ?? []);
            }

            $target = $habit->targetFor($weekStart);

            $result[$habit->id] = [
                'done' => $done,
                'target' => $target,
                'achieved' => $done >= $target,
                'dates_by_user' => $datesByUser,
            ];
        }

        return $result;
    }

    /** Progres satu habit (dipakai respons check-in). */
    public function forHabit(Habit $habit, string $weekStart): array
    {
        $memberIds = $this->memberIds($habit->circle_id);

        return $this->weekProgress(new Collection([$habit]), $memberIds, $weekStart)[$habit->id];
    }

    /** @return int[] */
    public function memberIds(int $circleId): array
    {
        return DB::table('circle_members')
            ->where('circle_id', $circleId)
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Habit bersama ("together"): sebuah hari hanya dihitung kalau SEMUA anggota check-in di
     * hari itu. Satu orang saja yang check-in = 0 hari.
     *
     * @param  array<int, string[]>  $datesByUser
     * @param  int[]  $memberIds
     */
    private function togetherDays(array $datesByUser, array $memberIds): int
    {
        if ($memberIds === []) {
            return 0;
        }

        $perDate = [];
        foreach ($memberIds as $userId) {
            foreach ($datesByUser[$userId] ?? [] as $date) {
                $perDate[$date] = ($perDate[$date] ?? 0) + 1;
            }
        }

        return count(array_filter($perDate, fn (int $count) => $count >= count($memberIds)));
    }
}
