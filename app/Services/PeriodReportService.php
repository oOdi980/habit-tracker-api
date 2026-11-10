<?php

namespace App\Services;

use App\Models\User;
use App\Support\CircleClock;
use App\Support\WeekScore;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * Laporan bulanan/tahunan. Dibangun dari evaluasi mingguan yang SUDAH beku (weekly_evaluations),
 * tidak menghitung ulang dari log harian: hasilnya tidak bisa bergeser kalau habit diedit atau
 * diarsipkan belakangan, dan murah (satu query agregat).
 *
 * Satu minggu masuk ke bulan/tahun tempat hari KAMIS-nya jatuh (WeekScore).
 * Minggu tanpa habit yang dihitung tidak ikut hitungan minggu.
 */
class PeriodReportService
{
    /**
     * @param  string  $period  'month' atau 'year'
     * @param  ?string  $value  'Y-m' / 'Y'; null = bulan/tahun berjalan menurut zona waktu sesi
     */
    public function report(User $user, int $circleId, string $period, ?string $value): array
    {
        $circle = $user->circles()->with('members')->whereKey($circleId)->firstOrFail();

        $today = CircleClock::forCircle($circle)->today();
        $value ??= $period === 'year' ? substr($today, 0, 4) : substr($today, 0, 7);

        [$from, $to] = WeekScore::weekRange($period, $value);

        // FILTER (WHERE ...) = agregat bersyarat Postgres: satu pass untuk semua hitungan.
        $rows = DB::table('weekly_evaluations')
            ->where('circle_id', $circle->id)
            ->whereBetween('week_start', [$from, $to])
            ->selectRaw("
                user_id,
                COUNT(*) FILTER (WHERE habits_total > 0) AS weeks_evaluated,
                COUNT(*) FILTER (WHERE habits_total > 0 AND result = 'reward') AS weeks_reward,
                COUNT(*) FILTER (WHERE habits_total > 0 AND result = 'neutral') AS weeks_neutral,
                COUNT(*) FILTER (WHERE habits_total > 0 AND result = 'punishment') AS weeks_punishment,
                COALESCE(SUM(habits_total), 0) AS habits_total,
                COALESCE(SUM(habits_achieved), 0) AS habits_achieved
            ")
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $members = $circle->members->sortBy('id')->map(function (User $member) use ($rows) {
            $row = $rows->get($member->id);
            $total = (int) ($row->habits_total ?? 0);
            $achieved = (int) ($row->habits_achieved ?? 0);

            return [
                'user_id' => $member->id,
                'name' => $member->name,
                'weeks_evaluated' => (int) ($row->weeks_evaluated ?? 0),
                'weeks_reward' => (int) ($row->weeks_reward ?? 0),
                'weeks_neutral' => (int) ($row->weeks_neutral ?? 0),
                'weeks_punishment' => (int) ($row->weeks_punishment ?? 0),
                'habits_total' => $total,
                'habits_achieved' => $achieved,
                'percent' => WeekScore::percent($total, $achieved),
            ];
        })->values()->all();

        return [
            'period' => $period,
            'value' => $value,
            'weeks_in_period' => $this->countMondays($from, $to),
            'members' => $members,
        ];
    }

    /** Jumlah Senin dalam [from, to] inklusif (tanggal murni, bebas DST). */
    private function countMondays(string $from, string $to): int
    {
        $firstMonday = CircleClock::weekStartOf($from);
        if ($firstMonday < $from) {
            $firstMonday = CircleClock::addDays($firstMonday, 7);
        }
        $lastMonday = CircleClock::weekStartOf($to);

        if ($lastMonday < $firstMonday) {
            return 0;
        }

        $utc = new DateTimeZone('UTC');
        $days = (new DateTimeImmutable($firstMonday, $utc))->diff(new DateTimeImmutable($lastMonday, $utc))->days;

        return intdiv($days, 7) + 1;
    }
}
