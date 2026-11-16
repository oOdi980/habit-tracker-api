<?php

namespace App\Services;

use App\Enums\HabitMode;
use App\Models\Circle;
use App\Models\Habit;
use App\Models\HabitDayNote;
use App\Models\HabitTask;
use App\Models\HabitTaskEntry;
use App\Models\User;
use App\Support\CircleClock;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Progres harian per tugas pada habit bermode 'checklist', plus catatan harian, papan, dan riwayat.
 *
 * Kunci rancangannya: baris di habit_logs TETAP berarti "hari ini lengkap". Setiap perubahan
 * progres menghitung ulang apakah SEMUA tugas yang berlaku hari itu sudah >= targetnya, lalu
 * membuat atau menghapus baris habit_logs (lewat CheckInService::ensureLog / dropLog). Evaluasi
 * mingguan, izin, dan laporan membaca habit_logs seperti sebelumnya, jadi tidak ada yang berubah di sana.
 *
 * Semua penulisan: kunci sesi lebih dulu (urutan kunci proyek), tanggal dihitung server, dan
 * aturan "boleh ditulis" persis sama dengan check-in biasa (CheckInService::assertWritable).
 * Hanya HARI INI yang bisa diubah (atau kemarin bila client_ts sah di dalam toleransi pada
 * pergantian hari), jadi hari yang sudah lewat otomatis terkunci.
 */
class TaskProgressService
{
    public function __construct(
        private readonly HabitService $habits,
        private readonly CheckInService $checkIns,
    ) {
    }

    // ---------------------------------------------------------------------------------------
    // Penulisan progres
    // ---------------------------------------------------------------------------------------

    /**
     * Tambah progres. $data: salah satu dari 'complete' (ceklis: tercatat sebesar SISA target)
     * atau 'amount' (angka sendiri); opsional 'weight_g', 'client_key', 'client_ts'.
     *
     * @return array{created: bool, day: array}
     */
    public function addEntry(User $user, int $taskId, array $data): array
    {
        return $this->withTask($user, $taskId, function (HabitTask $task, Habit $habit) use ($user, $data) {
            $clock = CircleClock::forCircle($habit->circle);
            $clientTs = $this->checkIns->parseClientTs($data['client_ts'] ?? null);
            $logDate = $clock->resolveLogDate($clientTs, (int) config('habit.checkin.client_ts_tolerance_minutes'));

            $this->checkIns->assertWritable($user, $habit, $logDate);
            $this->assertTaskInEffect($task, $logDate);

            $weightG = $this->checkedWeight($task, isset($data['weight_g']) ? (int) $data['weight_g'] : null);

            if (array_key_exists('amount', $data)) {
                $amount = (int) $data['amount'];
                $source = 'manual';

                if ($amount > $task->metric->maxAmount()) {
                    $this->reject('amount', 'Angka terlalu besar (maksimal '.$task->metric->maxAmount().').');
                }
            } else {
                // Ceklis: genapi sampai target. Kalau sudah tercapai, tidak ada yang ditambah
                // (ceklis dua kali tidak menggandakan angka).
                $amount = max($task->target - $this->taskTotal($task->id, $logDate), 0);
                $source = 'tap';
            }

            $created = false;

            if ($amount > 0) {
                $count = HabitTaskEntry::query()->where('task_id', $task->id)->where('log_date', $logDate)->count();

                if ($count >= (int) config('habit.task.max_entries_per_task_per_day')) {
                    $this->reject('task', 'Terlalu banyak catatan untuk tugas ini hari ini. Pakai "ubah angka" untuk menggantinya.');
                }

                // ON CONFLICT DO NOTHING pada (task, tanggal, client_key): kirim ulang dengan
                // kunci yang sama tidak menggandakan angka.
                $created = HabitTaskEntry::query()->insertOrIgnore([[
                    'task_id' => $task->id,
                    'habit_id' => $habit->id,
                    'user_id' => $user->id,
                    'log_date' => $logDate,
                    'amount' => $amount,
                    'weight_g' => $weightG,
                    'source' => $source,
                    'client_key' => $data['client_key'] ?? null,
                ]]) === 1;
            }

            $this->syncLog($habit, $user, $logDate, $clientTs);

            return ['created' => $created, 'day' => $this->day($habit, $user->id, $logDate)];
        });
    }

    /**
     * "Ubah angka": total tugas HARI INI diganti persis dengan $amount (satu entri 'manual').
     * 0 = kosongkan semua entri hari ini (batal ceklis). Bisa menurunkan total di bawah target,
     * dan kalau begitu check-in hari itu otomatis ikut hilang.
     *
     * @return array{day: array}
     */
    public function setProgress(User $user, int $taskId, int $amount, ?int $weightG): array
    {
        return $this->withTask($user, $taskId, function (HabitTask $task, Habit $habit) use ($user, $amount, $weightG) {
            $logDate = CircleClock::forCircle($habit->circle)->today();

            $this->checkIns->assertWritable($user, $habit, $logDate);
            $this->assertTaskInEffect($task, $logDate);

            if ($amount > $task->metric->maxAmount()) {
                $this->reject('amount', 'Angka terlalu besar (maksimal '.$task->metric->maxAmount().').');
            }

            $weightG = $amount > 0 ? $this->checkedWeight($task, $weightG) : null;

            HabitTaskEntry::query()->where('task_id', $task->id)->where('log_date', $logDate)->delete();

            if ($amount > 0) {
                HabitTaskEntry::query()->insert([
                    'task_id' => $task->id,
                    'habit_id' => $habit->id,
                    'user_id' => $user->id,
                    'log_date' => $logDate,
                    'amount' => $amount,
                    'weight_g' => $weightG,
                    'source' => 'manual',
                    'client_key' => null,
                ]);
            }

            $this->syncLog($habit, $user, $logDate);

            return ['day' => $this->day($habit, $user->id, $logDate)];
        });
    }

    /**
     * Catatan bebas hari ini. null atau kosong = hapus catatan.
     *
     * @return array{day: array}
     */
    public function setNote(User $user, int $habitId, ?string $note): array
    {
        return DB::transaction(function () use ($user, $habitId, $note) {
            $habit = $this->habits->findFor($user, $habitId);
            $this->assertChecklist($habit);

            Circle::query()->whereKey($habit->circle_id)->lockForUpdate()->first();

            $logDate = CircleClock::forCircle($habit->circle)->today();
            $this->checkIns->assertWritable($user, $habit, $logDate);

            $note = $note === null ? '' : trim($note);
            $key = ['habit_id' => $habit->id, 'user_id' => $user->id, 'log_date' => $logDate];

            if ($note === '') {
                HabitDayNote::query()->where($key)->delete();
            } else {
                // Aman dari balapan: seluruh penulisan progres/catatan serial lewat kunci sesi.
                HabitDayNote::query()->updateOrCreate($key, ['note' => $note]);
            }

            return ['day' => $this->day($habit, $user->id, $logDate)];
        });
    }

    // ---------------------------------------------------------------------------------------
    // Pembacaan
    // ---------------------------------------------------------------------------------------

    /**
     * Isi papan untuk habit checklist pada tanggal $date: per anggota yang ikut, daftar tugas
     * dengan total hari itu, status lengkap, dan catatan. Tiga query untuk SEMUA habit sekaligus.
     *
     * @param  Collection<int, Habit>  $habits  hanya habit bermode checklist
     * @param  int[]  $memberIds  semua anggota sesi (untuk habit bersama)
     * @return array<int, list<array{user_id: int, complete: bool, note: ?string, tasks: list<array>}>> dikunci per id habit
     */
    public function board(Collection $habits, array $memberIds, string $date): array
    {
        if ($habits->isEmpty()) {
            return [];
        }

        $habitIds = $habits->pluck('id')->all();

        $tasksByHabit = HabitTask::query()
            ->whereIn('habit_id', $habitIds)
            ->inEffectOn($date)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->groupBy('habit_id');

        // Total hari itu per tugas. SUM() dari Postgres bertipe bigint -> string di PHP, makanya dicast.
        // has_manual memakai 0/1 (bukan boolean) supaya tidak bergantung pada cara driver membaca bool.
        $totals = DB::table('habit_task_entries')
            ->whereIn('habit_id', $habitIds)
            ->where('log_date', $date)
            ->selectRaw("task_id, SUM(amount) AS total, MAX(weight_g) AS max_weight_g, MAX(CASE WHEN source = 'manual' THEN 1 ELSE 0 END) AS has_manual")
            ->groupBy('task_id')
            ->get()
            ->keyBy('task_id');

        $notes = HabitDayNote::query()
            ->whereIn('habit_id', $habitIds)
            ->where('log_date', $date)
            ->get(['habit_id', 'user_id', 'note'])
            ->mapWithKeys(fn (HabitDayNote $n) => [$n->habit_id.':'.$n->user_id => $n->note]);

        // "Lengkap" = ada baris habit_logs (sumber kebenaran yang sama dengan evaluasi mingguan).
        $complete = DB::table('habit_logs')
            ->whereIn('habit_id', $habitIds)
            ->where('log_date', $date)
            ->get(['habit_id', 'user_id'])
            ->mapWithKeys(fn ($row) => [$row->habit_id.':'.$row->user_id => true]);

        $result = [];

        foreach ($habits as $habit) {
            $tasksByUser = ($tasksByHabit->get($habit->id) ?? collect())->groupBy('user_id');
            $blocks = [];

            foreach ($habit->participantIds($memberIds) as $userId) {
                $tasks = ($tasksByUser->get($userId) ?? collect())->map(function (HabitTask $task) use ($totals) {
                    $row = $totals->get($task->id);

                    return $this->presentTask(
                        $task->id,
                        $task->name,
                        $task->metric->value,
                        $task->target,
                        $row !== null ? (int) $row->total : 0,
                        $row !== null && (int) $row->has_manual === 1,
                        $row !== null && $row->max_weight_g !== null ? (int) $row->max_weight_g : null,
                    );
                })->values()->all();

                $blocks[] = [
                    'user_id' => $userId,
                    'complete' => $complete->has($habit->id.':'.$userId),
                    'note' => $notes->get($habit->id.':'.$userId),
                    'tasks' => $tasks,
                ];
            }

            $result[$habit->id] = $blocks;
        }

        return $result;
    }

    /**
     * Riwayat per hari (terbaru dulu), dibaca sesuai permintaan, bukan ikut papan. Hanya hari yang
     * ada aktivitas atau catatannya. Target yang tampil adalah target SAAT ITU (versi tugasnya).
     * Kedua anggota terlihat (partner memantau).
     *
     * @return array{habit_id: int, days: list<array>, next_before: ?string}
     */
    public function history(User $user, int $habitId, ?string $before, ?int $limit): array
    {
        $habit = $this->habits->findFor($user, $habitId);
        $this->assertChecklist($habit);

        $today = CircleClock::forCircle($habit->circle)->today();
        $limit = max(1, min($limit ?? (int) config('habit.task.history_default_days'), (int) config('habit.task.history_max_days')));

        // Kursor eksklusif. Kosong = besok, supaya hari ini ikut.
        $before ??= CircleClock::addDays($today, 1);

        $dates = $this->activeDates($habit->id, $before, $limit);

        if ($dates === []) {
            return ['habit_id' => $habit->id, 'days' => [], 'next_before' => null];
        }

        $rows = DB::table('habit_task_entries AS e')
            ->join('habit_tasks AS t', 't.id', '=', 'e.task_id')
            ->where('e.habit_id', $habit->id)
            ->whereIn('e.log_date', $dates)
            ->groupBy('e.log_date', 'e.user_id', 't.id', 't.name', 't.metric', 't.target', 't.position')
            ->orderBy('t.position')
            ->orderBy('t.id')
            ->selectRaw("e.log_date, e.user_id, t.id AS task_id, t.name, t.metric, t.target, SUM(e.amount) AS total, MAX(e.weight_g) AS max_weight_g, MAX(CASE WHEN e.source = 'manual' THEN 1 ELSE 0 END) AS has_manual")
            ->get();

        $notes = HabitDayNote::query()
            ->where('habit_id', $habit->id)
            ->whereIn('log_date', $dates)
            ->get(['log_date', 'user_id', 'note']);

        $logs = DB::table('habit_logs')
            ->where('habit_id', $habit->id)
            ->whereIn('log_date', $dates)
            ->get(['log_date', 'user_id']);

        // days[tanggal][user_id] = blok anggota
        $days = array_fill_keys($dates, []);
        $block = function (string $date, int $userId) use (&$days): void {
            $days[$date][$userId] ??= ['user_id' => $userId, 'complete' => false, 'note' => null, 'tasks' => []];
        };

        foreach ($rows as $row) {
            $block($row->log_date, (int) $row->user_id);
            $days[$row->log_date][(int) $row->user_id]['tasks'][] = $this->presentTask(
                (int) $row->task_id,
                $row->name,
                $row->metric,
                (int) $row->target,
                (int) $row->total,
                (int) $row->has_manual === 1,
                $row->max_weight_g !== null ? (int) $row->max_weight_g : null,
            );
        }

        foreach ($notes as $note) {
            $block($note->log_date, (int) $note->user_id);
            $days[$note->log_date][(int) $note->user_id]['note'] = $note->note;
        }

        foreach ($logs as $log) {
            $block($log->log_date, (int) $log->user_id);
            $days[$log->log_date][(int) $log->user_id]['complete'] = true;
        }

        $list = [];

        foreach ($dates as $date) {
            ksort($days[$date]);
            $list[] = ['date' => $date, 'members' => array_values($days[$date])];
        }

        return [
            'habit_id' => $habit->id,
            'days' => $list,
            // Halaman penuh = mungkin masih ada yang lebih lama; kursor berikutnya = tanggal terakhir.
            'next_before' => count($dates) === $limit ? end($dates) : null,
        ];
    }

    // ---------------------------------------------------------------------------------------
    // Internal
    // ---------------------------------------------------------------------------------------

    /**
     * Jalankan $work di dalam transaksi dengan tugas yang sudah divalidasi dan sesi terkunci.
     *
     * @param  Closure(HabitTask, Habit): array  $work
     */
    private function withTask(User $user, int $taskId, Closure $work): array
    {
        return DB::transaction(function () use ($user, $taskId, $work) {
            $task = $this->findTask($user, $taskId);

            if ((int) $task->user_id !== $user->id) {
                abort(403, 'Tugas ini milik partnermu; kamu hanya bisa melihatnya.');
            }

            // Kunci sesi (sama seperti check-in dan job evaluasi), lalu BACA ULANG tugasnya:
            // edit daftar tugas oleh request lain bisa saja selesai tepat sebelum kunci kita didapat.
            Circle::query()->whereKey($task->habit->circle_id)->lockForUpdate()->first();
            $task = $this->findTask($user, $taskId);

            return $work($task, $task->habit);
        });
    }

    /** 404 untuk tugas yang tidak ada ATAU habit-nya bukan di sesi milik user (keberadaan tidak bocor). */
    private function findTask(User $user, int $taskId): HabitTask
    {
        return HabitTask::query()
            ->with('habit.circle')
            ->whereKey($taskId)
            ->whereHas('habit', fn ($q) => $q->whereIn(
                'circle_id',
                DB::table('circle_members')->where('user_id', $user->id)->select('circle_id')
            ))
            ->firstOrFail();
    }

    /**
     * Hitung ulang apakah semua tugas yang berlaku di $logDate sudah tercapai, lalu buat atau
     * hapus baris check-in. Dipanggil setelah SETIAP perubahan progres.
     */
    private function syncLog(Habit $habit, User $user, string $logDate, ?DateTimeImmutable $clientTs = null): void
    {
        $tasks = HabitTask::query()
            ->where('habit_id', $habit->id)
            ->where('user_id', $user->id)
            ->inEffectOn($logDate)
            ->get(['id', 'target']);

        $totals = DB::table('habit_task_entries')
            ->where('habit_id', $habit->id)
            ->where('user_id', $user->id)
            ->where('log_date', $logDate)
            ->selectRaw('task_id, SUM(amount) AS total')
            ->groupBy('task_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->task_id => (int) $row->total]);

        // Tanpa tugas sama sekali TIDAK pernah dianggap lengkap (every() pada kosong bernilai true).
        $complete = $tasks->isNotEmpty()
            && $tasks->every(fn (HabitTask $task) => $totals->get($task->id, 0) >= $task->target);

        if ($complete) {
            $this->checkIns->ensureLog($habit, $user, $logDate, $clientTs);
        } else {
            $this->checkIns->dropLog($habit, $user, $logDate);
        }
    }

    /** Respons standar setelah perubahan: keadaan hari itu untuk satu orang + progres minggu. */
    private function day(Habit $habit, int $userId, string $logDate): array
    {
        // Habit bersama: [$userId] membatasi blok ke orang ini saja. Habit personal: pemiliknya
        // (yang pasti sama dengan $userId, sudah dijaga di withTask).
        $block = $this->board(new Collection([$habit]), [$userId], $logDate)[$habit->id][0];

        return [
            'habit_id' => $habit->id,
            'log_date' => $logDate,
            'user_id' => $userId,
            'complete' => $block['complete'],
            'note' => $block['note'],
            'tasks' => $block['tasks'],
            'progress' => $this->checkIns->progressFor($habit, $logDate),
        ];
    }

    private function taskTotal(int $taskId, string $logDate): int
    {
        return (int) HabitTaskEntry::query()->where('task_id', $taskId)->where('log_date', $logDate)->sum('amount');
    }

    /** @return string[] tanggal beraktivitas (entri atau catatan) sebelum $before, terbaru dulu, maks $limit */
    private function activeDates(int $habitId, string $before, int $limit): array
    {
        $fromEntries = DB::table('habit_task_entries')
            ->where('habit_id', $habitId)
            ->where('log_date', '<', $before)
            ->select('log_date')->distinct()
            ->orderByDesc('log_date')
            ->limit($limit)
            ->pluck('log_date')
            ->all();

        $fromNotes = DB::table('habit_day_notes')
            ->where('habit_id', $habitId)
            ->where('log_date', '<', $before)
            ->select('log_date')->distinct()
            ->orderByDesc('log_date')
            ->limit($limit)
            ->pluck('log_date')
            ->all();

        $dates = array_values(array_unique(array_merge($fromEntries, $fromNotes)));
        rsort($dates, SORT_STRING); // 'Y-m-d' terurut sama dengan kronologis

        return array_slice($dates, 0, $limit);
    }

    private function presentTask(int $id, string $name, string $metric, int $target, int $total, bool $hasManual, ?int $maxWeightG): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'metric' => $metric,
            'target' => $target,
            'total' => $total,
            'done' => $total >= $target,
            'has_manual' => $hasManual,
            'max_weight_g' => $maxWeightG,
        ];
    }

    private function assertTaskInEffect(HabitTask $task, string $logDate): void
    {
        if (! $task->isInEffectOn($logDate)) {
            $this->reject('task', 'Tugas ini tidak berlaku pada hari itu.');
        }
    }

    private function checkedWeight(HabitTask $task, ?int $weightG): ?int
    {
        if ($weightG === null) {
            return null;
        }

        if (! $task->metric->allowsWeight()) {
            $this->reject('weight_g', 'Beban hanya bisa diisi untuk tugas berjenis jumlah.');
        }

        return $weightG;
    }

    private function assertChecklist(Habit $habit): void
    {
        if ($habit->mode !== HabitMode::Checklist) {
            $this->reject('habit', 'Habit ini bermode simpel dan tidak punya daftar tugas.');
        }
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
