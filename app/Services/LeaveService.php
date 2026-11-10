<?php

namespace App\Services;

use App\Enums\HabitScope;
use App\Models\Circle;
use App\Models\Habit;
use App\Models\HabitLeave;
use App\Models\User;
use App\Support\CircleClock;
use App\Support\WeekScore;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Izin (libur habit per minggu). Aturannya:
 *  - Diajukan untuk minggu BERJALAN atau minggu DEPAN saja (tidak bisa mundur, tidak bisa menimbun).
 *  - Baru berlaku setelah partner menyetujui; pengaju tidak bisa menyetujui sendiri.
 *  - Habit berizin dikeluarkan dari pembagi persentase minggu itu (status ringkasan 'excused').
 *  - Kuota per ORANG per BULAN (bawaan 4), boleh dipakai kapan saja, termasuk semuanya di satu
 *    minggu. Bulannya mengikuti aturan hari Kamis (WeekScore::monthOfWeek).
 *  - Izin habit personal hanya bisa diajukan pemiliknya; izin habit bersama oleh salah satu
 *    anggota (kuota milik pengaju) dan berlaku untuk keduanya.
 *  - Minggu yang sudah lewat terkunci: tidak bisa diajukan, disetujui, maupun dibatalkan.
 *
 * Semua perubahan mengunci baris sesi LEBIH DULU, sama seperti job evaluasi, jadi persetujuan
 * yang masuk bersamaan dengan evaluasi tidak bisa membuat hasilnya setengah-setengah.
 */
class LeaveService
{
    public function __construct(private readonly HabitService $habits)
    {
    }

    /**
     * @param  string  $which  'current' atau 'next'
     */
    public function request(User $user, int $habitId, string $which): HabitLeave
    {
        return DB::transaction(function () use ($user, $habitId, $which) {
            $habit = $this->habits->findFor($user, $habitId); // 404 bila bukan anggota

            // KUNCI SESI DULU. Zona waktu dibaca dari baris yang terkunci (tidak pernah berubah, tapi murah).
            $circle = Circle::query()->whereKey($habit->circle_id)->lockForUpdate()->firstOrFail();
            $clock = CircleClock::forCircle($circle);
            $weekStart = $which === 'next' ? $clock->nextMonday() : $clock->weekStart();

            if ($habit->scope === HabitScope::Personal && (int) $habit->owner_id !== $user->id) {
                abort(403, 'Izin habit personal hanya bisa diajukan pemiliknya.');
            }

            $this->assertCountedInWeek($habit, $weekStart);

            if (HabitLeave::query()->where('habit_id', $habit->id)->where('week_start', $weekStart)->exists()) {
                throw ValidationException::withMessages(['habit' => 'Izin untuk habit ini di minggu itu sudah diajukan.']);
            }

            $quotaMonth = WeekScore::monthOfWeek($weekStart).'-01';
            $limit = (int) config('habit.leave.per_month');
            $used = HabitLeave::query()
                ->where('circle_id', $circle->id)
                ->where('requested_by', $user->id)
                ->where('quota_month', $quotaMonth)
                ->count();

            if ($used >= $limit) {
                throw ValidationException::withMessages(['leave' => "Kuota izin bulan itu sudah habis ({$limit})."]);
            }

            return HabitLeave::create([
                'circle_id' => $circle->id,
                'habit_id' => $habit->id,
                'week_start' => $weekStart,
                'quota_month' => $quotaMonth,
                'requested_by' => $user->id,
            ]);
        });
    }

    /** Partner (bukan pengaju) menyetujui. Idempoten. */
    public function approve(User $user, int $leaveId): HabitLeave
    {
        return DB::transaction(function () use ($user, $leaveId) {
            [$leave, $circle] = $this->lockedMemberLeave($user, $leaveId);

            if ((int) $leave->requested_by === $user->id) {
                abort(403, 'Izin ini kamu ajukan sendiri; partner yang harus menyetujuinya.');
            }

            if ($leave->approved_at !== null) {
                return $leave;
            }

            $this->assertWeekNotClosed($leave, $circle);

            $leave->forceFill(['approved_at' => now(), 'approved_by' => $user->id])->save();

            return $leave;
        });
    }

    /**
     * Batalkan izin.
     *   - belum disetujui : pengaju membatalkan atau partner menolak (hapus).
     *   - sudah disetujui : hanya pengaju yang boleh membatalkan (kuotanya kembali).
     * Izin yang tidak ada (atau milik sesi orang lain) = 404, tanpa membedakan keduanya.
     */
    public function cancel(User $user, int $leaveId): void
    {
        DB::transaction(function () use ($user, $leaveId) {
            [$leave, $circle] = $this->lockedMemberLeave($user, $leaveId);

            $this->assertWeekNotClosed($leave, $circle);

            if ($leave->approved_at !== null && (int) $leave->requested_by !== $user->id) {
                abort(403, 'Izin yang sudah disetujui hanya bisa dibatalkan oleh pengajunya.');
            }

            $leave->delete();
        });
    }

    /**
     * Izin minggu berjalan + minggu depan, dan sisa kuota user untuk bulan-bulan yang relevan.
     *
     * @return array{leaves: Collection, quota: array<string, array{used: int, limit: int}>, week_start: string, next_week_start: string}
     */
    public function list(User $user, int $circleId): array
    {
        $circle = $user->circles()->whereKey($circleId)->firstOrFail();
        $clock = CircleClock::forCircle($circle);
        $weeks = [$clock->weekStart(), $clock->nextMonday()];

        $leaves = HabitLeave::query()
            ->where('circle_id', $circle->id)
            ->whereIn('week_start', $weeks)
            ->orderBy('week_start')
            ->orderBy('id')
            ->get();

        // Satu query untuk kuota (bulan minggu ini dan minggu depan bisa berbeda di akhir bulan).
        $months = array_values(array_unique(array_map(fn (string $w) => WeekScore::monthOfWeek($w).'-01', $weeks)));
        $usedByMonth = HabitLeave::query()
            ->where('circle_id', $circle->id)
            ->where('requested_by', $user->id)
            ->whereIn('quota_month', $months)
            ->selectRaw('quota_month, COUNT(*) AS used')
            ->groupBy('quota_month')
            ->pluck('used', 'quota_month');

        $quota = [];
        foreach ($months as $month) {
            $quota[substr($month, 0, 7)] = [
                'used' => (int) ($usedByMonth[$month] ?? 0),
                'limit' => (int) config('habit.leave.per_month'),
            ];
        }

        return ['leaves' => $leaves, 'quota' => $quota, 'week_start' => $weeks[0], 'next_week_start' => $weeks[1]];
    }

    /** Habit harus benar-benar dihitung di minggu itu (sudah mulai dan belum berakhir). */
    private function assertCountedInWeek(Habit $habit, string $weekStart): void
    {
        $counted = $habit->starts_on !== null
            && $habit->starts_on <= $weekStart
            && ($habit->ends_on === null || $habit->ends_on > $weekStart);

        if (! $counted) {
            throw ValidationException::withMessages(['habit' => 'Habit ini tidak dihitung di minggu itu.']);
        }
    }

    /** Minggu yang sudah lewat terkunci (sudah/segera dievaluasi). */
    private function assertWeekNotClosed(HabitLeave $leave, Circle $circle): void
    {
        if ($leave->week_start < CircleClock::forCircle($circle)->weekStart()) {
            throw ValidationException::withMessages(['leave' => 'Minggu itu sudah lewat, izin tidak bisa diubah lagi.']);
        }
    }

    /**
     * Izin milik sesi user + sesinya, terkunci. 404 bila bukan anggota.
     * Urutan kunci: sesi dulu, baru izin.
     *
     * @return array{0: HabitLeave, 1: Circle}
     */
    private function lockedMemberLeave(User $user, int $leaveId): array
    {
        $circleId = HabitLeave::query()
            ->whereKey($leaveId)
            ->whereIn('circle_id', DB::table('circle_members')->where('user_id', $user->id)->select('circle_id'))
            ->value('circle_id');

        if ($circleId === null) {
            abort(404);
        }

        $circle = Circle::query()->whereKey($circleId)->lockForUpdate()->firstOrFail();
        $leave = HabitLeave::query()->whereKey($leaveId)->lockForUpdate()->first();

        // Dihapus oleh request lain di antara dua query di atas: sekarang sudah tidak ada.
        if ($leave === null) {
            abort(404);
        }

        return [$leave, $circle];
    }
}
