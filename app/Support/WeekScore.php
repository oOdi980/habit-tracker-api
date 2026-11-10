<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Aturan hitung murni (tanpa database/Laravel) untuk evaluasi mingguan dan laporan periode.
 * Dipisah dari service supaya bisa diuji terisolasi: di sinilah bug "meleset satu" biasanya bersembunyi.
 */
final class WeekScore
{
    public const REWARD = 'reward';
    public const PUNISHMENT = 'punishment';
    public const NEUTRAL = 'neutral';

    /**
     * Hasil sebuah minggu untuk satu orang.
     *
     * Semua perbandingan memakai bilangan bulat (dikali 100), tidak ada pecahan/pembulatan,
     * jadi 1 dari 2 habit = tepat 50%, bukan "49.99999".
     *
     * @param  int  $total  jumlah habit yang dihitung (habit berizin sudah dikeluarkan)
     * @param  int  $achieved  jumlah habit yang targetnya tercapai
     * @param  int  $rewardMinPercent  reward bila persentase >= nilai ini
     * @param  int  $punishmentBelowPercent  punishment bila persentase < nilai ini
     */
    public static function classify(int $total, int $achieved, int $rewardMinPercent, int $punishmentBelowPercent): string
    {
        // Tanpa habit yang dihitung tidak ada persentase (bagi nol): netral.
        if ($total <= 0) {
            return self::NEUTRAL;
        }

        if ($achieved * 100 >= $rewardMinPercent * $total) {
            return self::REWARD;
        }

        if ($achieved * 100 < $punishmentBelowPercent * $total) {
            return self::PUNISHMENT;
        }

        return self::NEUTRAL;
    }

    /** Persentase untuk ditampilkan (turun ke bawah, 1 desimal), null bila tidak ada habit. */
    public static function percent(int $total, int $achieved): ?float
    {
        return $total > 0 ? intdiv($achieved * 1000, $total) / 10 : null;
    }

    /**
     * Bulan tempat sebuah minggu dihitung: bulan dari hari KAMIS minggu itu (aturan ISO).
     * Hasil 'Y-m'. Dengan aturan ini tiap minggu masuk tepat satu bulan/tahun dan tiap bulan
     * berisi 4-5 minggu utuh, tanpa minggu yang terbelah.
     */
    public static function monthOfWeek(string $weekStart): string
    {
        return substr(CircleClock::addDays($weekStart, 3), 0, 7);
    }

    /** Tahun tempat sebuah minggu dihitung (tahun dari hari Kamisnya). */
    public static function yearOfWeek(string $weekStart): int
    {
        return (int) substr(CircleClock::addDays($weekStart, 3), 0, 4);
    }

    /**
     * Rentang week_start (inklusif) dari semua minggu yang hari Kamisnya jatuh di periode ini.
     *
     * @param  string  $period  'month' (value 'Y-m') atau 'year' (value 'Y')
     * @return array{0: string, 1: string} [week_start_pertama, week_start_terakhir]
     */
    public static function weekRange(string $period, string $value): array
    {
        if ($period === 'month') {
            if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
                throw new InvalidArgumentException("Bulan tidak valid: {$value}");
            }
            $first = $value.'-01';
            $last = (new DateTimeImmutable($first, new DateTimeZone('UTC')))->modify('last day of this month')->format('Y-m-d');
        } elseif ($period === 'year') {
            if (! preg_match('/^\d{4}$/', $value)) {
                throw new InvalidArgumentException("Tahun tidak valid: {$value}");
            }
            $first = $value.'-01-01';
            $last = $value.'-12-31';
        } else {
            throw new InvalidArgumentException("Periode tidak dikenal: {$period}");
        }

        // Kamis ada di [first, last]  <=>  Senin (= Kamis - 3 hari) ada di [first - 3, last - 3].
        return [CircleClock::addDays($first, -3), CircleClock::addDays($last, -3)];
    }
}
