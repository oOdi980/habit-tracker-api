<?php

namespace App\Services;

use App\Enums\EvaluationResult;
use App\Enums\HabitScope;
use App\Enums\SummaryStatus;
use App\Models\Circle;
use App\Models\Consequence;
use App\Models\Habit;
use App\Models\HabitLeave;
use App\Models\User;
use App\Models\WeeklyEvaluation;
use App\Support\CircleClock;
use App\Support\WeekScore;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Evaluasi mingguan: mengubah log check-in minggu lalu menjadi hasil beku
 * (ringkasan per habit + evaluasi per orang + reward/punishment).
 *
 * ATURAN KUNCI (tidak boleh dilanggar, ini yang menjaga job tetap benar):
 *  1. Satu transaksi PER MINGGU PER SESI, dan baris `circles` DIKUNCI PERTAMA. Check-in, izin,
 *     dan persetujuan juga mengunci sesi lebih dulu, jadi semuanya berjalan bergantian, tidak
 *     pernah bersamaan, dan tidak ada urutan kunci terbalik (deadlock).
 *  2. Idempoten: `circles.evaluated_through` hanya maju di dalam transaksi yang sama dengan
 *     penulisan hasilnya. Job yang dijalankan dua kali, terlambat, atau bertumpuk tidak
 *     menghasilkan data ganda (dijaga juga oleh UNIQUE di database).
 *  3. Mengejar ketertinggalan: kalau server mati beberapa minggu, minggu-minggu itu dievaluasi
 *     berurutan dari yang tertua, masing-masing dengan target dan izin yang berlaku DI MINGGU ITU.
 *  4. "Sekarang" selalu dihitung per sesi dengan zona waktunya (CircleClock), bukan satu cron
 *     global berbasis UTC.
 */
class EvaluationService
{
    public function __construct(private readonly ProgressService $progress)
    {
    }

    /** Grace efektif: tidak pernah lebih kecil dari toleransi client_ts + 1 menit. */
    public function graceMinutes(): int
    {
        return max(
            (int) config('habit.evaluation.grace_minutes'),
            (int) config('habit.checkin.client_ts_tolerance_minutes') + 1,
        );
    }

    /**
     * Dipanggil scheduler tiap beberapa menit. Murah: sesi yang tidak punya pekerjaan dilewati
     * hanya dengan membandingkan dua tanggal di memori (tanpa query tambahan per sesi).
     *
     * @return array{circles: int, weeks: int, failed: int}
     */
    public function evaluateDueCircles(?DateTimeInterface $now = null): array
    {
        $stats = ['circles' => 0, 'weeks' => 0, 'failed' => 0];
        $grace = $this->graceMinutes();

        Circle::query()
            ->select(['id', 'timezone', 'evaluated_through'])
            ->chunkById(200, function ($circles) use (&$stats, $now, $grace) {
                foreach ($circles as $circle) {
                    $lastClosed = CircleClock::forCircle($circle, $now)->lastClosedWeekStart($grace);

                    // Perbandingan string 'Y-m-d' = perbandingan kronologis.
                    if ($circle->evaluated_through !== null && $circle->evaluated_through >= $lastClosed) {
                        continue;
                    }

                    try {
                        $stats['weeks'] += $this->evaluateCircleUpTo($circle->id, $lastClosed);
                        $stats['circles']++;
                    } catch (Throwable $e) {
                        // Satu sesi bermasalah tidak boleh menghentikan sesi lain. Transaksi minggu
                        // yang gagal sudah rollback, jadi percobaan berikutnya mengulang dari awal.
                        $stats['failed']++;
                        Log::error("Evaluasi mingguan gagal untuk sesi {$circle->id}", ['exception' => $e]);
                    }
                }
            });

        return $stats;
    }

    /**
     * Evaluasi semua minggu yang tertinggal sampai $upTo (Senin, inklusif).
     *
     * @return int jumlah minggu yang dievaluasi
     */
    public function evaluateCircleUpTo(int $circleId, string $upTo): int
    {
        $evaluated = 0;

        // Batas atas pengaman (10 tahun): loop selalu berhenti walau ada data aneh.
        for ($i = 0; $i < 520; $i++) {
            $week = DB::transaction(function () use ($circleId, $upTo) {
                // KUNCI SESI LEBIH DULU. Data sesi dibaca ulang di bawah kunci: job lain bisa
                // saja sudah memajukan evaluated_through sejak query awal.
                $circle = Circle::query()->whereKey($circleId)->lockForUpdate()->first();
                if ($circle === null) {
                    return null; // sesi sudah dihapus
                }

                $week = $this->nextWeekToEvaluate($circle, $upTo);
                if ($week === null) {
                    return null;
                }

                $this->evaluateWeek($circle, $week);
                $this->markEvaluated($circle->id, $week);

                return $week;
            });

            if ($week === null) {
                break;
            }
            $evaluated++;
        }

        return $evaluated;
    }

    /** Minggu berikutnya yang harus dievaluasi, atau null bila sudah beres sampai $upTo. */
    private function nextWeekToEvaluate(Circle $circle, string $upTo): ?string
    {
        $through = $circle->evaluated_through;

        if ($through !== null) {
            return $through >= $upTo ? null : CircleClock::addDays($through, 7);
        }

        // Evaluasi pertama: mulai dari minggu habit tertua yang sudah dimulai (starts_on selalu Senin).
        $first = Habit::query()
            ->where('circle_id', $circle->id)
            ->whereNotNull('starts_on')
            ->min('starts_on');

        if ($first === null || $first > $upTo) {
            // Belum ada habit yang pernah dihitung sampai $upTo. Majukan penanda supaya sesi ini
            // dilewati scheduler sampai minggu depan. Aman: habit baru selalu mulai Senin DEPAN,
            // jadi tidak mungkin ada habit yang starts_on-nya <= $upTo muncul belakangan.
            $this->markEvaluated($circle->id, $upTo);

            return null;
        }

        return $first;
    }

    private function markEvaluated(int $circleId, string $week): void
    {
        DB::table('circles')->where('id', $circleId)->update([
            'evaluated_through' => $week,
            'updated_at' => now(),
        ]);

        // Perubahan target yang tertahan (pending) boleh jadi target berjalan hanya setelah SEMUA
        // minggu sebelum tanggal berlakunya selesai dievaluasi. Kalau dipindah lebih awal,
        // minggu lalu akan dinilai dengan target minggu depan. Karena itu penggeseran ini ada di
        // sini, di transaksi yang sama dengan penanda evaluasi, dan bukan di cron terpisah.
        // `pending_target_from <= week + 7`: semua minggu sebelum itu sudah (<= $week) beres.
        DB::table('habits')
            ->where('circle_id', $circleId)
            ->whereNotNull('pending_target_from')
            ->where('pending_target_from', '<=', CircleClock::addDays($week, 7))
            ->update([
                // Di UPDATE Postgres, sisi kanan membaca nilai LAMA baris, jadi urutan kolom aman.
                'target_per_week' => DB::raw('pending_target_per_week'),
                'pending_target_per_week' => null,
                'pending_target_from' => null,
                'updated_at' => now(),
            ]);
    }

    /** Menulis ringkasan per habit dan evaluasi per orang untuk SATU minggu. Dipanggil di bawah kunci sesi. */
    private function evaluateWeek(Circle $circle, string $week): void
    {
        $memberIds = $this->progress->memberIds($circle->id);

        // Habit yang dihitung di minggu ini: sudah mulai (starts_on <= week) dan belum berakhir
        // (ends_on kosong atau > week). starts_on/ends_on selalu Senin, jadi cukup bandingkan dengan Senin minggu itu.
        $habits = Habit::query()
            ->where('circle_id', $circle->id)
            ->whereNotNull('starts_on')
            ->where('starts_on', '<=', $week)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>', $week))
            ->orderBy('id')
            ->get();

        // Hanya izin yang SUDAH disetujui yang berlaku. Izin yang masih menunggu dianggap tidak ada.
        $excusedHabitIds = array_flip(
            HabitLeave::query()
                ->where('circle_id', $circle->id)
                ->where('week_start', $week)
                ->whereNotNull('approved_at')
                ->pluck('habit_id')
                ->map(fn ($id) => (int) $id)
                ->all()
        );

        $progress = $this->progress->weekProgress($habits, $memberIds, $week);
        $nowString = now()->toDateTimeString();

        $totals = array_fill_keys($memberIds, ['total' => 0, 'achieved' => 0]);
        $summaryRows = [];

        foreach ($habits as $habit) {
            $p = $progress[$habit->id];
            $excused = isset($excusedHabitIds[$habit->id]);

            $status = $excused
                ? SummaryStatus::Excused
                : ($p['achieved'] ? SummaryStatus::Achieved : SummaryStatus::Missed);

            // Habit bersama dicatat untuk kedua anggota dengan hasil yang sama ("together":
            // satu gagal = dua-duanya gagal). Habit personal hanya untuk pemiliknya.
            $affected = $habit->scope === HabitScope::Shared ? $memberIds : [(int) $habit->owner_id];

            foreach ($affected as $userId) {
                if (! isset($totals[$userId])) {
                    continue; // pemilik sudah bukan anggota sesi
                }

                $summaryRows[] = [
                    'habit_id' => $habit->id,
                    'user_id' => $userId,
                    'week_start' => $week,
                    'target_snapshot' => $p['target'],   // target yang berlaku DI MINGGU ITU (dibekukan)
                    'done_count' => $p['done'],
                    'status' => $status->value,
                    'evaluated_at' => $nowString,
                    'created_at' => $nowString,
                    'updated_at' => $nowString,
                ];

                if (! $excused) {
                    $totals[$userId]['total']++;
                    $totals[$userId]['achieved'] += $p['achieved'] ? 1 : 0;
                }
            }
        }

        if ($summaryRows !== []) {
            DB::table('habit_weekly_summaries')->insert($summaryRows);
        }

        // Item hanya boleh ikut spin bila sudah disetujui SEBELUM minggu yang dievaluasi dimulai.
        // Ini yang mencegah orang menyelipkan hukuman receh (atau reward bagus) setelah tahu
        // hasilnya hampir pasti.
        // Format 'P' menyertakan offset (+00:00), jadi perbandingan di Postgres tidak bergantung pada
        // zona waktu sesi koneksi. Tanpa offset, string ini ditafsirkan memakai zona sesi dan
        // batasnya bisa bergeser berjam-jam tanpa error apa pun.
        $cutoff = (new DateTimeImmutable($week.' 00:00:00', new DateTimeZone($circle->timezone)))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:sP');

        foreach ($memberIds as $userId) {
            $total = $totals[$userId]['total'];
            $achieved = $totals[$userId]['achieved'];

            $result = WeekScore::classify(
                $total,
                $achieved,
                (int) $circle->reward_min_ratio,
                (int) $circle->punishment_below_ratio,
            );

            WeeklyEvaluation::create([
                'circle_id' => $circle->id,
                'user_id' => $userId,
                'week_start' => $week,
                'habits_total' => $total,
                'habits_achieved' => $achieved,
                'result' => $result,
                'consequence_id' => $result === WeekScore::NEUTRAL
                    ? null
                    : $this->drawConsequence($circle->id, $result, $cutoff),
                'evaluated_at' => $nowString,
            ]);
        }
    }

    /**
     * Spin: satu item acak dari item yang aktif, sudah disetujui, dan disetujui sebelum $cutoff
     * (string waktu lengkap dengan offset).
     * Null bila daftar kosong (evaluasi tetap tercatat, hanya tanpa isi).
     */
    private function drawConsequence(int $circleId, string $type, string $cutoff): ?int
    {
        $id = Consequence::query()
            ->where('circle_id', $circleId)
            ->where('type', $type)
            ->where('is_active', true)
            ->whereNotNull('approved_at')
            ->where('approved_at', '<', $cutoff)
            ->inRandomOrder() // ORDER BY random(): daftar kecil (maks 100), tidak perlu cara lain
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    // ------------------------------------------------------------------ baca & konfirmasi

    /**
     * Evaluasi beberapa minggu terakhir (terbaru dulu) + semua punishment yang belum dikonfirmasi
     * (menumpuk lintas minggu, tidak terbatas $limitWeeks).
     *
     * @return array{evaluations: \Illuminate\Support\Collection, pending_punishments: \Illuminate\Support\Collection, circle: Circle}
     */
    public function list(User $user, int $circleId, int $limitWeeks): array
    {
        $circle = $user->circles()->whereKey($circleId)->firstOrFail();
        $limitWeeks = max(1, min($limitWeeks, (int) config('habit.evaluation.max_list_weeks')));

        $weeks = WeeklyEvaluation::query()
            ->where('circle_id', $circle->id)
            ->select('week_start')
            ->distinct()
            ->orderByDesc('week_start')
            ->limit($limitWeeks)
            ->pluck('week_start');

        $evaluations = WeeklyEvaluation::query()
            ->where('circle_id', $circle->id)
            ->whereIn('week_start', $weeks)
            ->with('consequence')
            ->orderByDesc('week_start')
            ->orderBy('user_id')
            ->get();

        $pending = WeeklyEvaluation::query()
            ->where('circle_id', $circle->id)
            ->where('result', EvaluationResult::Punishment->value)
            ->whereNull('fulfilled_at')
            ->with('consequence')
            ->orderBy('week_start')
            ->orderBy('user_id')
            ->get();

        return ['evaluations' => $evaluations, 'pending_punishments' => $pending, 'circle' => $circle];
    }

    /**
     * Partner (BUKAN yang kena) menyetujui bahwa punishment sudah dijalankan.
     * Bukti dikirim lewat grup WhatsApp, di aplikasi hanya ada persetujuannya.
     */
    public function fulfill(User $user, int $evaluationId): WeeklyEvaluation
    {
        return DB::transaction(function () use ($user, $evaluationId) {
            $circleId = WeeklyEvaluation::query()
                ->whereKey($evaluationId)
                ->whereIn('circle_id', DB::table('circle_members')->where('user_id', $user->id)->select('circle_id'))
                ->value('circle_id');

            if ($circleId === null) {
                abort(404); // bukan anggota sesi (atau tidak ada): keberadaan tidak bocor
            }

            // Kunci sesi dulu, baru baris evaluasi (aturan urutan kunci proyek).
            Circle::query()->whereKey($circleId)->lockForUpdate()->first();
            $evaluation = WeeklyEvaluation::query()->whereKey($evaluationId)->lockForUpdate()->firstOrFail();

            if ($evaluation->result !== EvaluationResult::Punishment) {
                throw ValidationException::withMessages(['evaluation' => 'Hanya punishment yang perlu dikonfirmasi.']);
            }

            if ((int) $evaluation->user_id === $user->id) {
                abort(403, 'Punishment dikonfirmasi oleh partner, bukan oleh yang bersangkutan.');
            }

            // Tombol ditekan dua kali: kembalikan apa adanya, jangan timpa waktu/pengonfirmasi pertama.
            if ($evaluation->fulfilled_at === null) {
                $evaluation->forceFill(['fulfilled_at' => now(), 'fulfilled_by' => $user->id])->save();
            }

            return $evaluation->load('consequence');
        });
    }
}
