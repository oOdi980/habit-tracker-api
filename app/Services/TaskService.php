<?php

namespace App\Services;

use App\Enums\HabitMode;
use App\Enums\HabitScope;
use App\Models\Circle;
use App\Models\Habit;
use App\Models\HabitTask;
use App\Models\User;
use App\Support\CircleClock;
use App\Support\TaskRows;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Daftar tugas sebuah habit (definisinya, bukan progres harian). Aturan intinya sama dengan
 * habit dan targetnya: struktur tidak pernah berubah di tengah minggu.
 *
 *   habit belum mulai (menunggu / akan datang) -> daftar diganti langsung
 *   habit sudah berjalan                       -> perubahan berlaku SENIN DEPAN
 *   orang yang belum punya tugas berlaku sama  -> langsung berlaku hari ini (kalau tidak, mereka
 *                                                 tidak bisa check-in sama sekali sepanjang minggu ini)
 *
 * Tiap orang hanya mengubah daftarnya sendiri. Di habit bersama, kedua daftar boleh berbeda.
 */
class TaskService
{
    public function __construct(
        private readonly HabitService $habits,
        private readonly ProgressService $progress,
    ) {
    }

    /**
     * Daftar tugas untuk layar edit: per anggota, yang berlaku sekarang ('current') dan yang akan
     * berlaku mulai Senin depan ('next'). Bisa dilihat semua anggota sesi (partner memantau).
     */
    public function definitions(User $user, int $habitId): array
    {
        $habit = $this->habits->findFor($user, $habitId);
        $clock = CircleClock::forCircle($habit->circle);

        if ($habit->lifecycle($clock->today()) === Habit::ENDED) {
            abort(404);
        }

        $this->assertChecklist($habit);

        return $this->buildDefinitions($habit, $clock);
    }

    /**
     * Ganti SELURUH daftar tugas milik $user (daftar yang dikirim = daftar lengkap yang diinginkan).
     * Tugas dikenali lewat nama (huruf kecil, spasi dirapikan), bukan id, jadi form cukup
     * mengirim ulang daftarnya tanpa harus membawa id.
     *
     * @param  array<int, array{name: string, metric: string, target: int|string}>  $tasks
     */
    public function replaceOwn(User $user, int $habitId, array $tasks): array
    {
        return DB::transaction(function () use ($user, $habitId, $tasks) {
            $habit = $this->habits->findFor($user, $habitId);

            // Urutan kunci proyek ini: sesi dulu, baru baris di bawahnya. Kunci sesi juga
            // menyerialkan edit ini dengan pencatatan progres dan job evaluasi.
            Circle::query()->whereKey($habit->circle_id)->lockForUpdate()->first();
            $habit = $this->habits->findFor($user, $habitId, lock: true);

            $clock = CircleClock::forCircle($habit->circle);
            $today = $clock->today();
            $state = $habit->lifecycle($today);

            if ($state === Habit::ENDED) {
                abort(404);
            }

            $this->assertChecklist($habit);

            if ($habit->scope === HabitScope::Personal && (int) $habit->owner_id !== $user->id) {
                abort(403, 'Hanya pemilik habit yang boleh mengubah daftar tugasnya.');
            }

            $wanted = TaskRows::normalize($tasks);

            if ($state === Habit::PENDING_APPROVAL || $state === Habit::UPCOMING) {
                // Belum ada hari yang dihitung, jadi belum ada entri yang bisa menunjuk ke tugas lama:
                // aman diganti total.
                HabitTask::query()->where('habit_id', $habit->id)->where('user_id', $user->id)->delete();
                TaskRows::insert($habit->id, $user->id, $wanted, null);
            } else {
                $this->scheduleChange($habit, $user->id, $wanted, $clock);
            }

            return $this->buildDefinitions($habit, $clock);
        });
    }

    /**
     * Habit sudah berjalan: bandingkan daftar yang diinginkan dengan yang berlaku hari ini, lalu
     * jadwalkan selisihnya mulai Senin depan.
     *
     * @param  list<array{name: string, name_key: string, metric: string, target: int, position: int}>  $wanted
     */
    private function scheduleChange(Habit $habit, int $userId, array $wanted, CircleClock $clock): void
    {
        $today = $clock->today();
        $nextMonday = $clock->nextMonday();

        // 1. Buang perubahan yang dijadwalkan sebelumnya (baris yang baru berlaku di masa depan):
        //    daftar baru ini menggantikannya. Baris itu pasti belum punya entri karena entri hanya
        //    dibuat untuk tanggal <= hari ini.
        HabitTask::query()
            ->where('habit_id', $habit->id)
            ->where('user_id', $userId)
            ->whereNotNull('valid_from')
            ->where('valid_from', '>', $today)
            ->delete();

        $inEffect = HabitTask::query()
            ->where('habit_id', $habit->id)
            ->where('user_id', $userId)
            ->inEffectOn($today)
            ->get();

        // 2. Belum punya tugas yang berlaku sama sekali (mis. partner di habit bersama yang
        //    belum pernah mengisi): langsung berlaku hari ini. Tidak ada minggu yang "diadili" dengan
        //    daftar ini karena sebelumnya mereka memang tidak bisa check-in.
        if ($inEffect->isEmpty()) {
            TaskRows::insert($habit->id, $userId, $wanted, $today);

            return;
        }

        // Di antara baris yang berlaku hari ini, nama (kunci) tidak mungkin kembar: versi lama dan
        // baru tidak pernah berlaku bersamaan.
        $byKey = $inEffect->keyBy('name_key');

        $close = [];   // id baris yang berakhir Senin depan
        $reopen = [];  // id baris yang batal berakhir
        $insert = [];  // baris baru yang mulai berlaku Senin depan
        $seen = [];    // id baris yang masih ada di daftar baru

        foreach ($wanted as $item) {
            $row = $byKey->get($item['name_key']);

            if ($row === null) {
                $insert[] = $item;

                continue;
            }

            $seen[$row->id] = true;

            if ($row->metric->value === $item['metric'] && (int) $row->target === $item['target']) {
                // Hitungan tidak berubah. Kalau sebelumnya dijadwalkan berakhir, batalkan.
                if ($row->valid_until !== null) {
                    $reopen[] = $row->id;
                }

                // Tampilan nama dan urutan tidak memengaruhi hitungan, jadi boleh langsung.
                if ($row->name !== $item['name'] || (int) $row->position !== $item['position']) {
                    $row->forceFill(['name' => $item['name'], 'position' => $item['position']])->save();
                }
            } else {
                // Target atau jenis ukuran berubah: versi lama berakhir, versi baru mulai Senin depan.
                $close[] = $row->id;
                $insert[] = $item;
            }
        }

        // Tugas yang hilang dari daftar baru berakhir Senin depan (yang sudah dijadwalkan berakhir dibiarkan).
        foreach ($inEffect as $row) {
            if (! isset($seen[$row->id]) && $row->valid_until === null) {
                $close[] = $row->id;
            }
        }

        // Urutan penting untuk indeks unik "nama di antara tugas yang masih berlaku": tutup dulu,
        // buka lagi, baru tambah.
        if ($close !== []) {
            HabitTask::query()->whereIn('id', $close)->update(['valid_until' => $nextMonday]);
        }

        if ($reopen !== []) {
            HabitTask::query()->whereIn('id', $reopen)->update(['valid_until' => null]);
        }

        TaskRows::insert($habit->id, $userId, $insert, $nextMonday);
    }

    private function buildDefinitions(Habit $habit, CircleClock $clock): array
    {
        $today = $clock->today();
        $participants = $habit->participantIds($this->progress->memberIds($habit->circle_id));

        // Hanya yang masih berlaku atau akan berlaku. Versi lama yang sudah selesai tidak perlu.
        $byUser = HabitTask::query()
            ->where('habit_id', $habit->id)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', $today))
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->groupBy('user_id');

        $members = [];

        foreach ($participants as $userId) {
            $mine = $byUser->get($userId) ?? collect();

            $members[] = [
                'user_id' => $userId,
                // Berlaku hari ini (termasuk yang dijadwalkan berakhir Senin depan).
                'current' => $mine
                    ->filter(fn (HabitTask $t) => $t->valid_from === null || $t->valid_from <= $today)
                    ->map(fn (HabitTask $t) => $this->present($t))->values()->all(),
                // Daftar yang akan berlaku mulai Senin depan: yang tidak dijadwalkan berakhir.
                'next' => $mine
                    ->filter(fn (HabitTask $t) => $t->valid_until === null)
                    ->map(fn (HabitTask $t) => $this->present($t))->values()->all(),
                'has_pending_change' => $mine->contains(
                    fn (HabitTask $t) => ($t->valid_from !== null && $t->valid_from > $today) || $t->valid_until !== null
                ),
            ];
        }

        return [
            'habit_id' => $habit->id,
            'mode' => HabitMode::Checklist->value,
            'today' => $today,
            'next_monday' => $clock->nextMonday(),
            'members' => $members,
        ];
    }

    private function present(HabitTask $task): array
    {
        return [
            'id' => $task->id,
            'name' => $task->name,
            'metric' => $task->metric->value,
            'target' => $task->target,
            'position' => $task->position,
        ];
    }

    private function assertChecklist(Habit $habit): void
    {
        if ($habit->mode !== HabitMode::Checklist) {
            throw ValidationException::withMessages(['habit' => 'Habit ini bermode simpel dan tidak punya daftar tugas.']);
        }
    }
}
