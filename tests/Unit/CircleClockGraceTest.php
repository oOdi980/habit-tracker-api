<?php

namespace Tests\Unit;

use App\Support\CircleClock;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

// Menentukan "minggu terakhir yang boleh dievaluasi": inti dari job Senin dini hari.
class CircleClockGraceTest extends TestCase
{
    private function lastClosed(string $local, string $tz = 'Asia/Jakarta', int $grace = 11): string
    {
        return CircleClock::forTimezone($tz, new DateTimeImmutable($local, new DateTimeZone($tz)))
            ->lastClosedWeekStart($grace);
    }

    public function test_senin_dini_hari_sebelum_grace_masih_dua_minggu_lalu(): void
    {
        // Senin 2026-11-09. Minggu yang baru berakhir (11-02) BELUM boleh dievaluasi sebelum 00:11.
        $this->assertSame('2026-10-26', $this->lastClosed('2026-11-09 00:00:00'));
        $this->assertSame('2026-10-26', $this->lastClosed('2026-11-09 00:05:00'));
        $this->assertSame('2026-10-26', $this->lastClosed('2026-11-09 00:10:59'));
    }

    public function test_tepat_grace_minggu_lalu_boleh_dievaluasi(): void
    {
        $this->assertSame('2026-11-02', $this->lastClosed('2026-11-09 00:11:00'));
        $this->assertSame('2026-11-02', $this->lastClosed('2026-11-09 00:11:01'));
    }

    public function test_sepanjang_minggu_tetap_minggu_lalu(): void
    {
        $this->assertSame('2026-11-02', $this->lastClosed('2026-11-10 12:00:00')); // Selasa
        $this->assertSame('2026-11-02', $this->lastClosed('2026-11-15 23:59:59')); // Minggu terakhir, detik terakhir
    }

    public function test_minggu_2359_lalu_senin_0000_geser_tepat_di_batas(): void
    {
        // Minggu 23:59:59: minggu lalu masih 11-02 (minggu 11-09 belum selesai).
        $this->assertSame('2026-11-02', $this->lastClosed('2026-11-15 23:59:59'));
        // Senin 00:00:00: minggu baru, tetapi grace belum lewat -> masih 11-02.
        $this->assertSame('2026-11-02', $this->lastClosed('2026-11-16 00:00:00'));
        // Senin 00:11: 11-09 boleh dievaluasi.
        $this->assertSame('2026-11-09', $this->lastClosed('2026-11-16 00:11:00'));
    }

    public function test_memakai_zona_waktu_sesi_bukan_utc(): void
    {
        // 2026-11-08 17:11 UTC = Senin 2026-11-09 00:11 WIB -> sesi WIB boleh evaluasi 11-02.
        $utc = new DateTimeImmutable('2026-11-08 17:11:00', new DateTimeZone('UTC'));
        $this->assertSame('2026-11-02', CircleClock::forTimezone('Asia/Jakarta', $utc)->lastClosedWeekStart(11));
        // Sesi Los Angeles pada instan yang sama baru Minggu 09:11: minggu berjalannya masih
        // yang dimulai 11-02, jadi minggu terakhir yang boleh dievaluasi adalah 10-26.
        $this->assertSame('2026-10-26', CircleClock::forTimezone('America/Los_Angeles', $utc)->lastClosedWeekStart(11));
    }

    public function test_grace_nol_artinya_langsung_di_senin_0000(): void
    {
        $this->assertSame('2026-11-02', $this->lastClosed('2026-11-09 00:00:00', 'Asia/Jakarta', 0));
    }

    public function test_pergantian_bulan_dan_tahun(): void
    {
        // Senin 2027-01-04 00:11 -> minggu lalu dimulai Senin 2026-12-28.
        $this->assertSame('2026-12-28', $this->lastClosed('2027-01-04 00:11:00'));
        // Senin 2027-01-04 00:05 -> dua minggu lalu: 2026-12-21.
        $this->assertSame('2026-12-21', $this->lastClosed('2027-01-04 00:05:00'));
    }

    public function test_dst_tidak_menggeser_hasil(): void
    {
        // Eropa/Amsterdam beralih ke waktu musim panas Minggu 2026-03-29 (jam 02:00 -> 03:00).
        // Senin 2026-03-30 00:11 lokal: minggu lalu dimulai Senin 2026-03-23.
        $this->assertSame('2026-03-23', $this->lastClosed('2026-03-30 00:11:00', 'Europe/Amsterdam'));
        $this->assertSame('2026-03-16', $this->lastClosed('2026-03-30 00:05:00', 'Europe/Amsterdam'));
    }
}
