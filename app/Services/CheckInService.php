<?php

namespace App\Services;

use App\Enums\HabitScope;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\HabitWeeklySummary;
use App\Models\User;
use App\Models\WeeklyEvaluation;
use App\Support\CircleClock;
use DateTimeImmutable;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Check-in dan undo. Aturan:
 *  - Tanggal dihitung SERVER (zona waktu sesi); jam HP hanya petunjuk dengan toleransi sempit.
 *  - Idempoten: menekan dua kali tidak membuat dua log (unique + ON CONFLICT DO NOTHING).
 *  - Habit hanya bisa di-check-in saat aktif (starts_on <= tanggal < ends_on).
 *  - Minggu yang sudah dievaluasi terkunci.
 *  - Undo hanya untuk hari ini.
 */
class CheckInService
{
    public function __construct(
        private readonly HabitService $habits,
        private readonly ProgressService $progress,
    ) {
    }

    /**
     * @return array{created: bool, habit_id: int, log_date: string, progress: array}
     */
    public function checkIn(User $user, int $habitId, ?string $clientTsRaw): array
    {
        $habit = $this->habits->findFor($user, $habitId);
        $this->assertCanCheckIn($user, $habit);

        $clock = CircleClock::forCircle($habit->circle);
        $clientTs = $this->parseClientTs($clientTsRaw);
        $logDate = $clock->resolveLogDate($clientTs, (int) config('habit.checkin.client_ts_tolerance_minutes'));

        $this->assertTrackable($habit, $logDate);
        $this->assertWeekOpen($habit, $user, $logDate);

        // INSERT ... ON CONFLICT DO NOTHING: balapan dua tap/dua perangkat aman, tanpa error.
        // completed_at diisi default database (waktu server).
        $inserted = HabitLog::query()->insertOrIgnore([[
            'habit_id' => $habit->id,
            'user_id' => $user->id,
            'log_date' => $logDate,
            'client_ts' => $clientTs?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ]]);

        return [
            'created' => $inserted === 1,
            'habit_id' => $habit->id,
            'log_date' => $logDate,
            'progress' => $this->progressFor($habit, $logDate),
        ];
    }

    /**
     * Batalkan check-in HARI INI saja. Log hari-hari sebelumnya terkunci.
     *
     * @return array{deleted: bool, habit_id: int, log_date: string, progress: array}
     */
    public function undo(User $user, int $habitId): array
    {
        $habit = $this->habits->findFor($user, $habitId);
        $this->assertCanCheckIn($user, $habit);

        $logDate = CircleClock::forCircle($habit->circle)->today();
        $this->assertWeekOpen($habit, $user, $logDate);

        $deleted = HabitLog::query()
            ->where('habit_id', $habit->id)
            ->where('user_id', $user->id)
            ->where('log_date', $logDate)
            ->delete();

        return [
            'deleted' => $deleted > 0,
            'habit_id' => $habit->id,
            'log_date' => $logDate,
            'progress' => $this->progressFor($habit, $logDate),
        ];
    }

    /** Habit personal hanya boleh di-check-in pemiliknya; partner hanya memantau. */
    private function assertCanCheckIn(User $user, Habit $habit): void
    {
        if ($habit->scope === HabitScope::Personal && (int) $habit->owner_id !== $user->id) {
            abort(403, 'Check-in hanya bisa dilakukan oleh pemilik habit.');
        }
    }

    private function assertTrackable(Habit $habit, string $logDate): void
    {
        if ($habit->starts_on === null) {
            $this->reject('Habit ini menunggu persetujuan partner.');
        }

        if ($habit->starts_on > $logDate) {
            $this->reject("Habit ini baru mulai dihitung {$habit->starts_on}.");
        }

        if ($habit->ends_on !== null && $habit->ends_on <= $logDate) {
            $this->reject('Habit ini sudah berakhir.');
        }
    }

    /** Minggu yang sudah dievaluasi tidak boleh berubah lagi. */
    private function assertWeekOpen(Habit $habit, User $user, string $logDate): void
    {
        $weekStart = CircleClock::weekStartOf($logDate);

        $summaryClosed = HabitWeeklySummary::query()
            ->where('habit_id', $habit->id)
            ->where('user_id', $user->id)
            ->where('week_start', $weekStart)
            ->where('status', '!=', 'open')
            ->exists();

        $evaluated = WeeklyEvaluation::query()
            ->where('circle_id', $habit->circle_id)
            ->where('user_id', $user->id)
            ->where('week_start', $weekStart)
            ->exists();

        if ($summaryClosed || $evaluated) {
            $this->reject('Minggu itu sudah dievaluasi, check-in tidak bisa diubah lagi.');
        }
    }

    private function progressFor(Habit $habit, string $logDate): array
    {
        $weekStart = CircleClock::weekStartOf($logDate);
        $p = $this->progress->forHabit($habit, $weekStart);

        return [
            'week_start' => $weekStart,
            'done' => $p['done'],
            'target' => $p['target'],
            'achieved' => $p['achieved'],
        ];
    }

    private function parseClientTs(?string $raw): ?DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (Throwable) {
            throw ValidationException::withMessages(['client_ts' => 'Format waktu tidak valid.']);
        }
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['habit' => $message]);
    }
}
