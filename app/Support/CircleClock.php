<?php

namespace App\Support;

use App\Models\Circle;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Satu-satunya tempat yang boleh menjawab "hari/minggu apa sekarang" untuk sebuah sesi.
 * Semua logika tanggal (check-in, habit baru, evaluasi) wajib lewat sini supaya tidak ada dua
 * versi perhitungan yang bisa berbeda satu hari.
 *
 * Murni PHP (DateTimeImmutable + DateTimeZone, tanpa Carbon/Laravel) agar bisa diuji terisolasi.
 *
 * Prinsip:
 *  - Waktu mutlak (instan) disimpan UTC. "Tanggal" selalu tanggal LOKAL sesi, berupa string 'Y-m-d'.
 *  - Aritmetika tanggal dikerjakan pada tanggal murni (tanpa jam), bukan "+24 jam", sehingga
 *    pergantian DST / panjang hari tidak bisa menggeser hasilnya.
 *  - Minggu = Senin..Minggu. Senin dihitung dari ISO day-of-week (1..7), bukan dari locale.
 */
final class CircleClock
{
    private readonly DateTimeZone $tz;

    /** Instan "sekarang" dalam UTC. */
    private readonly DateTimeImmutable $now;

    private function __construct(string $timezone, DateTimeInterface $now)
    {
        $this->tz = new DateTimeZone($timezone); // melempar exception bila zona tidak valid
        $this->now = DateTimeImmutable::createFromInterface($now)->setTimezone(new DateTimeZone('UTC'));
    }

    public static function forTimezone(string $timezone, DateTimeInterface $now): self
    {
        return new self($timezone, $now);
    }

    /** $now = null memakai now() Laravel (ikut berubah saat tes memakai travelTo). */
    public static function forCircle(Circle $circle, ?DateTimeInterface $now = null): self
    {
        return new self($circle->timezone, $now ?? now());
    }

    /** Tanggal lokal sesi saat ini, mis. '2026-11-04'. */
    public function today(): string
    {
        return $this->now->setTimezone($this->tz)->format('Y-m-d');
    }

    /** Senin minggu berjalan (tanggal lokal). */
    public function weekStart(): string
    {
        return self::weekStartOf($this->today());
    }

    /** Minggu minggu berjalan. */
    public function weekEnd(): string
    {
        return self::addDays($this->weekStart(), 6);
    }

    /**
     * "Senin depan": Senin SETELAH minggu berjalan. Kalau hari ini Senin, hasilnya Senin 7 hari
     * lagi (bukan hari ini), karena minggu ini sudah berjalan sejak 00:00.
     */
    public function nextMonday(): string
    {
        return self::addDays($this->weekStart(), 7);
    }

    public function utcNow(): DateTimeImmutable
    {
        return $this->now;
    }

    /**
     * Tanggal yang dicatat untuk sebuah check-in. Server yang menentukan; jam dari HP hanya
     * dipercaya bila selisihnya dengan jam server <= toleransi (untuk antrean offline di sekitar
     * pergantian hari, mis. ditekan 23:58 tetapi baru terkirim 00:03).
     * Hasil tidak pernah melewati tanggal server (jam HP yang maju beberapa menit tidak boleh
     * mencatat "besok").
     */
    public function resolveLogDate(?DateTimeInterface $clientTs, int $toleranceMinutes = 10): string
    {
        $serverDate = $this->today();

        if ($clientTs === null) {
            return $serverDate;
        }

        $skewSeconds = abs($this->now->getTimestamp() - $clientTs->getTimestamp());
        if ($skewSeconds > $toleranceMinutes * 60) {
            return $serverDate;
        }

        $clientDate = DateTimeImmutable::createFromInterface($clientTs)->setTimezone($this->tz)->format('Y-m-d');

        // Perbandingan string 'Y-m-d' sama dengan perbandingan kronologis.
        return $clientDate < $serverDate ? $clientDate : $serverDate;
    }

    /** Senin pada atau sebelum tanggal ini. Contoh: Minggu 2026-11-08 -> 2026-11-02. */
    public static function weekStartOf(string $date): string
    {
        $d = self::parseDate($date);
        $isoDay = (int) $d->format('N'); // 1 = Senin ... 7 = Minggu

        return $d->modify(sprintf('%+d days', -($isoDay - 1)))->format('Y-m-d');
    }

    public static function addDays(string $date, int $days): string
    {
        return self::parseDate($date)->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }

    /** Tanggal lokal dari sebuah instan. */
    public function toLocalDate(DateTimeInterface $instant): string
    {
        return DateTimeImmutable::createFromInterface($instant)->setTimezone($this->tz)->format('Y-m-d');
    }

    private static function parseDate(string $date): DateTimeImmutable
    {
        // '!' mengosongkan jam ke 00:00:00; diuraikan di UTC (tanggal murni, bebas DST).
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));

        // Tolak tanggal yang "dibetulkan" PHP secara diam-diam (mis. 2026-02-31).
        if ($d === false || $d->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException("Tanggal tidak valid: {$date}");
        }

        return $d;
    }
}
