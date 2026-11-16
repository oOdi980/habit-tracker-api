<?php

namespace App\Services;

use App\Enums\CompletionRule;
use App\Enums\HabitMode;
use App\Enums\HabitScope;
use App\Models\Circle;
use App\Models\Habit;
use App\Models\User;
use App\Support\CircleClock;
use App\Support\TaskRows;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Siklus hidup habit. Aturan intinya: struktur habit tidak pernah berubah di tengah minggu.
 *   - habit baru        : mulai dihitung Senin depan (habit bersama: Senin setelah partner setuju)
 *   - target berubah    : ditahan (pending) sampai Senin depan
 *   - diarsipkan        : tetap dihitung sampai Minggu ini selesai, berakhir Senin depan
 * Judul berubah langsung karena tidak memengaruhi hitungan.
 *
 * Semua tanggal berasal dari CircleClock (zona waktu sesi), tidak pernah dari klien.
 */
class HabitService
{
    /** 404 untuk sesi yang tidak ada ATAU bukan milik user. */
    public function memberCircle(User $user, int $circleId): Circle
    {
        return $user->circles()->whereKey($circleId)->firstOrFail();
    }

    /**
     * Habit yang boleh dilihat user (anggota sesinya), lengkap dengan relasi circle.
     * 404 bila habit tidak ada atau sesinya bukan milik user (keberadaan tidak bocor).
     * $lock = true mengunci barisnya (untuk perubahan); wajib dipanggil di dalam transaksi.
     */
    public function findFor(User $user, int $habitId, bool $lock = false): Habit
    {
        $query = Habit::query()
            ->with('circle')
            ->whereKey($habitId)
            ->whereIn('circle_id', DB::table('circle_members')->where('user_id', $user->id)->select('circle_id'));

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    /**
     * Habit sesi yang belum berakhir (termasuk yang menunggu persetujuan dan akan datang).
     *
     * @return array{today: string, habits: Collection}
     */
    public function list(User $user, int $circleId): array
    {
        $circle = $this->memberCircle($user, $circleId);
        $today = CircleClock::forCircle($circle)->today();

        $habits = Habit::query()
            ->where('circle_id', $circle->id)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>', $today))
            ->orderBy('id')
            ->get();

        // Relasi circle diisi manual: HabitResource membutuhkannya, dan ini mencegah N+1.
        $habits->each(fn (Habit $habit) => $habit->setRelation('circle', $circle));

        return ['today' => $today, 'habits' => $habits];
    }

    /**
     * @param array{title: string, scope: string, target_per_week: int, mode?: string, tasks?: array} $data
     *        'tasks' (habit checklist) = daftar tugas MILIK PEMBUAT; partner mengisi daftarnya sendiri
     *        lewat TaskService::replaceOwn.
     */
    public function create(User $user, int $circleId, array $data): Habit
    {
        return DB::transaction(function () use ($user, $circleId, $data) {
            $circle = $this->memberCircle($user, $circleId);

            // Kunci sesi: dua request bersamaan tidak bisa sama-sama lolos cek batas jumlah habit.
            // (Urutan kunci proyek ini: sesi lebih dulu, lalu baris di bawahnya.)
            Circle::whereKey($circle->id)->lockForUpdate()->first();

            $clock = CircleClock::forCircle($circle);
            $today = $clock->today();
            $isShared = $data['scope'] === HabitScope::Shared->value;
            $mode = HabitMode::from($data['mode'] ?? HabitMode::Simple->value);

            $open = Habit::query()
                ->where('circle_id', $circle->id)
                ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>', $today))
                ->count();

            if ($open >= (int) config('habit.max_habits_per_circle')) {
                throw ValidationException::withMessages(['circle' => 'Jumlah habit di sesi ini sudah mencapai batas.']);
            }

            if ($isShared) {
                $members = DB::table('circle_members')->where('circle_id', $circle->id)->count();
                if ($members < (int) config('habit.max_circle_members')) {
                    throw ValidationException::withMessages(['scope' => 'Habit bersama butuh partner di sesi ini.']);
                }
            }

            $habit = Habit::create([
                'circle_id' => $circle->id,
                'owner_id' => $isShared ? null : $user->id,
                'created_by' => $user->id,
                'title' => $data['title'],
                'scope' => $isShared ? HabitScope::Shared : HabitScope::Personal,
                'completion_rule' => $isShared ? CompletionRule::Together : CompletionRule::Individual,
                'target_per_week' => (int) $data['target_per_week'],
                // Eksplisit (bukan mengandalkan default database) supaya model hasilnya langsung benar.
                'mode' => $mode,
                // Habit personal langsung pasti, mulai Senin depan. Habit bersama menunggu
                // persetujuan partner dulu (starts_on diisi saat disetujui).
                'starts_on' => $isShared ? null : $clock->nextMonday(),
            ]);

            // Tugas milik pembuat, berlaku "sejak habit dibuat" (valid_from kosong). Satu transaksi
            // dengan habitnya: tidak mungkin ada habit checklist tanpa tugas pembuatnya.
            if ($mode === HabitMode::Checklist) {
                TaskRows::insert($habit->id, $user->id, TaskRows::normalize($data['tasks'] ?? []), null);
            }

            return $habit->setRelation('circle', $circle);
        });
    }

    /** @param array{title?: string, target_per_week?: int} $data */
    public function update(User $user, int $habitId, array $data): Habit
    {
        return DB::transaction(function () use ($user, $habitId, $data) {
            $habit = $this->findFor($user, $habitId, lock: true);
            $clock = CircleClock::forCircle($habit->circle);
            $state = $habit->lifecycle($clock->today());

            if ($state === Habit::ENDED) {
                abort(404);
            }

            $this->assertCanManage($user, $habit);

            // Habit bersama yang masih menunggu: hanya pembuatnya yang boleh mengubah (partner
            // sedang diminta setuju, bukan mengubah isinya).
            if ($state === Habit::PENDING_APPROVAL && (int) $habit->created_by !== $user->id) {
                abort(403, 'Habit ini menunggu persetujuanmu; hanya pembuatnya yang bisa mengubah.');
            }

            if (array_key_exists('title', $data)) {
                $habit->title = $data['title'];
            }

            if (array_key_exists('target_per_week', $data)) {
                // Hanya sentuh target (dan perubahan tertahan) bila memang target yang diedit.
                // Ganti judul tidak pernah menggeser target.
                $this->settleDuePending($habit, $clock);
                $this->applyTarget($habit, (int) $data['target_per_week'], $state, $clock);
            }

            $habit->save();

            return $habit;
        });
    }

    /**
     * Hapus/arsipkan. Hasil bergantung pada tahap habit:
     *   menunggu persetujuan / akan datang -> dihapus permanen (belum punya log atau ringkasan);
     *   aktif                              -> berakhir Senin depan, diembalikan Habit;
     *   sedang diarsipkan                  -> tidak berubah (idempoten).
     * Untuk habit bersama yang menunggu, ini juga jalur MENOLAK (partner) / MEMBATALKAN (pembuat).
     *
     * @return Habit|null null bila habit dihapus permanen
     */
    public function archive(User $user, int $habitId): ?Habit
    {
        return DB::transaction(function () use ($user, $habitId) {
            $habit = $this->findFor($user, $habitId, lock: true);
            $clock = CircleClock::forCircle($habit->circle);
            $state = $habit->lifecycle($clock->today());

            if ($state === Habit::ENDED) {
                abort(404);
            }

            $this->assertCanManage($user, $habit);

            if ($state === Habit::PENDING_APPROVAL || $state === Habit::UPCOMING) {
                $habit->delete();

                return null;
            }

            if ($state === Habit::ACTIVE) {
                $habit->forceFill(['ends_on' => $clock->nextMonday(), 'archived_at' => now()])->save();
            }

            return $habit;
        });
    }

    /** Batalkan arsip selama habit masih dalam masa "ending". */
    public function restore(User $user, int $habitId): Habit
    {
        return DB::transaction(function () use ($user, $habitId) {
            $habit = $this->findFor($user, $habitId, lock: true);
            $clock = CircleClock::forCircle($habit->circle);
            $state = $habit->lifecycle($clock->today());

            if ($state === Habit::ENDED) {
                abort(404);
            }

            $this->assertCanManage($user, $habit);

            if ($state !== Habit::ENDING) {
                throw ValidationException::withMessages(['habit' => 'Habit ini tidak sedang diarsipkan.']);
            }

            $habit->forceFill(['ends_on' => null, 'archived_at' => null])->save();

            return $habit;
        });
    }

    /** Partner (bukan pembuat) menyetujui habit bersama. Mulai dihitung Senin depan dari saat disetujui. */
    public function accept(User $user, int $habitId): Habit
    {
        return DB::transaction(function () use ($user, $habitId) {
            $habit = $this->findFor($user, $habitId, lock: true);
            $clock = CircleClock::forCircle($habit->circle);
            $state = $habit->lifecycle($clock->today());

            if ($state === Habit::ENDED) {
                abort(404);
            }

            if ($habit->scope !== HabitScope::Shared) {
                throw ValidationException::withMessages(['habit' => 'Hanya habit bersama yang perlu persetujuan.']);
            }

            if ((int) $habit->created_by === $user->id) {
                abort(403, 'Habit ini kamu buat sendiri; partner yang harus menyetujuinya.');
            }

            // Sudah disetujui sebelumnya (mis. tombol ditekan dua kali): kembalikan apa adanya.
            // starts_on TIDAK dihitung ulang, supaya tanggal mulainya tidak bergeser.
            if ($habit->accepted_at !== null) {
                return $habit;
            }

            $habit->forceFill(['accepted_at' => now(), 'starts_on' => $clock->nextMonday()])->save();

            return $habit;
        });
    }

    /** Habit personal hanya boleh diubah pemiliknya; habit bersama oleh kedua anggota. */
    private function assertCanManage(User $user, Habit $habit): void
    {
        if ($habit->scope === HabitScope::Personal && (int) $habit->owner_id !== $user->id) {
            abort(403, 'Hanya pemilik habit yang boleh mengubahnya.');
        }
    }

    /** Terapkan target baru sesuai tahap: habit yang belum jalan berubah langsung, yang sudah jalan ditahan. */
    private function applyTarget(Habit $habit, int $new, string $state, CircleClock $clock): void
    {
        $hasStarted = $state === Habit::ACTIVE || $state === Habit::ENDING;

        if (! $hasStarted) {
            $habit->target_per_week = $new;
            $habit->pending_target_per_week = null;
            $habit->pending_target_from = null;

            return;
        }

        if ($new === (int) $habit->target_per_week) {
            // Kembali ke target berjalan = membatalkan perubahan yang tertahan.
            $habit->pending_target_per_week = null;
            $habit->pending_target_from = null;

            return;
        }

        $habit->pending_target_per_week = $new;
        $habit->pending_target_from = $clock->nextMonday();
    }

    /**
     * Jika perubahan tertahan sudah jatuh tempo (<= awal minggu ini), jadikan target berjalan.
     *
     * Hanya boleh bila minggu LALU sudah selesai dievaluasi. Kalau belum (Senin dini hari, atau
     * job sedang mengejar ketertinggalan), memindahkannya sekarang akan membuat minggu lalu
     * dinilai dengan target minggu ini. Job evaluasi yang memindahkannya pada waktunya
     * (EvaluationService::markEvaluated); sampai saat itu edit target ditolak sementara.
     * Tampilan tidak terganggu karena membaca lewat Habit::targetFor().
     */
    private function settleDuePending(Habit $habit, CircleClock $clock): void
    {
        $weekStart = $clock->weekStart();

        if ($habit->pending_target_from === null || $habit->pending_target_from > $weekStart) {
            return;
        }

        $through = $habit->circle->evaluated_through;
        if ($through === null || $through < CircleClock::addDays($weekStart, -7)) {
            throw ValidationException::withMessages([
                'target_per_week' => 'Evaluasi minggu lalu sedang diproses. Coba ubah target lagi beberapa menit lagi.',
            ]);
        }

        $habit->target_per_week = (int) $habit->pending_target_per_week;
        $habit->pending_target_per_week = null;
        $habit->pending_target_from = null;
    }
}
