<?php

namespace Tests\Unit;

use App\Support\CircleClock;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

// Tes murni (tanpa aplikasi/database): logika waktu adalah bagian paling rawan bug.
class CircleClockTest extends TestCase
{
    private const WIB = 'Asia/Jakarta';

    /** Instan dari waktu dinding lokal, mis. at('2026-11-04 23:59:59'). */
    private function at(string $local, string $tz = self::WIB): DateTimeImmutable
    {
        return new DateTimeImmutable($local, new DateTimeZone($tz));
    }

    private function clock(string $local, string $tz = self::WIB): CircleClock
    {
        return CircleClock::forTimezone($tz, $this->at($local, $tz));
    }

    public function test_tanggal_lokal_berganti_tepat_tengah_malam_waktu_sesi_bukan_utc(): void
    {
        // 23:59:59 WIB = 16:59:59 UTC; 00:00:00 WIB = 17:00:00 UTC hari sebelumnya.
        $this->assertSame('2026-11-04', $this->clock('2026-11-04 23:59:59')->today());
        $this->assertSame('2026-11-05', $this->clock('2026-11-05 00:00:00')->today());

        // Instan yang sama diberikan dalam UTC memberi hasil sama.
        $utc = new DateTimeImmutable('2026-11-04 17:00:00', new DateTimeZone('UTC'));
        $this->assertSame('2026-11-05', CircleClock::forTimezone(self::WIB, $utc)->today());
    }

    public function test_minggu_selalu_dimulai_senin_untuk_semua_hari(): void
    {
        // 2026-11-02 adalah Senin.
        foreach (['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08'] as $date) {
            $this->assertSame('2026-11-02', CircleClock::weekStartOf($date), "weekStartOf({$date})");
        }
        $this->assertSame('2026-11-09', CircleClock::weekStartOf('2026-11-09'));
    }

    public function test_batas_minggu_minggu_2359_dan_senin_0000(): void
    {
        $sundayLast = $this->clock('2026-11-08 23:59:59');
        $this->assertSame('2026-11-02', $sundayLast->weekStart());
        $this->assertSame('2026-11-08', $sundayLast->weekEnd());
        $this->assertSame('2026-11-09', $sundayLast->nextMonday());

        $mondayFirst = $this->clock('2026-11-09 00:00:00');
        $this->assertSame('2026-11-09', $mondayFirst->weekStart());
        $this->assertSame('2026-11-15', $mondayFirst->weekEnd());
        $this->assertSame('2026-11-16', $mondayFirst->nextMonday());
    }

    public function test_senin_depan_pada_hari_senin_adalah_tujuh_hari_lagi_bukan_hari_ini(): void
    {
        $this->assertSame('2026-11-09', $this->clock('2026-11-02 09:00:00')->nextMonday());
        $this->assertSame('2026-11-09', $this->clock('2026-11-04 10:00:00')->nextMonday());
    }

    public function test_senin_lokal_yang_masih_minggu_di_utc_dihitung_minggu_baru(): void
    {
        // Minggu 2026-11-08 17:30 UTC = Senin 2026-11-09 00:30 WIB.
        $utc = new DateTimeImmutable('2026-11-08 17:30:00', new DateTimeZone('UTC'));
        $clock = CircleClock::forTimezone(self::WIB, $utc);

        $this->assertSame('2026-11-09', $clock->today());
        $this->assertSame('2026-11-09', $clock->weekStart());
        $this->assertSame('2026-11-16', $clock->nextMonday());
    }

    public function test_pergantian_bulan_dan_tahun(): void
    {
        $this->assertSame('2027-01-01', CircleClock::addDays('2026-12-31', 1));
        $this->assertSame('2026-12-31', CircleClock::addDays('2027-01-01', -1));
        $this->assertSame('2026-03-01', CircleClock::addDays('2026-02-28', 1)); // 2026 bukan kabisat
        $this->assertSame('2028-02-29', CircleClock::addDays('2028-02-28', 1)); // 2028 kabisat
        // 2027-01-01 hari Jumat -> Senin pekan itu jatuh di tahun sebelumnya.
        $this->assertSame('2026-12-28', CircleClock::weekStartOf('2027-01-01'));
        $this->assertSame('2027-01-04', $this->clock('2026-12-31 12:00:00')->nextMonday());
    }

    public function test_zona_waktu_lain_dan_perubahan_dst_tidak_menggeser_tanggal(): void
    {
        // Europe/Amsterdam pindah ke musim panas pada 2026-03-29 (02:00 -> 03:00).
        $before = new DateTimeImmutable('2026-03-29 00:30:00', new DateTimeZone('UTC')); // 01:30 CET
        $this->assertSame('2026-03-29', CircleClock::forTimezone('Europe/Amsterdam', $before)->today());

        $after = new DateTimeImmutable('2026-03-29 22:30:00', new DateTimeZone('UTC')); // 00:30 CEST, sudah 30 Maret
        $this->assertSame('2026-03-30', CircleClock::forTimezone('Europe/Amsterdam', $after)->today());

        // Aritmetika tanggal murni: melintasi hari DST tetap tepat 7 hari.
        $this->assertSame('2026-04-05', CircleClock::addDays('2026-03-29', 7));
        $this->assertSame('2026-03-30', CircleClock::weekStartOf('2026-04-02'));

        // New York: 2026-11-04 23:30 lokal = 2026-11-05 04:30 UTC.
        $this->assertSame('2026-11-04', $this->clock('2026-11-04 23:30:00', 'America/New_York')->today());
    }

    public function test_tanpa_client_ts_memakai_tanggal_server(): void
    {
        $this->assertSame('2026-11-10', $this->clock('2026-11-10 10:00:00')->resolveLogDate(null));
    }

    public function test_client_ts_sebelum_tengah_malam_diterima_bila_masih_dalam_toleransi(): void
    {
        // Server sudah 00:03 Selasa; HP menekan 23:58 Senin (selisih 5 menit) -> dicatat Senin.
        $clock = $this->clock('2026-11-10 00:03:00');
        $this->assertSame('2026-11-09', $clock->resolveLogDate($this->at('2026-11-09 23:58:00')));
    }

    public function test_client_ts_yang_terlalu_lama_diabaikan(): void
    {
        $clock = $this->clock('2026-11-10 00:03:00');
        // Selisih 23 menit > 10 menit -> pakai tanggal server.
        $this->assertSame('2026-11-10', $clock->resolveLogDate($this->at('2026-11-09 23:40:00')));
    }

    public function test_toleransi_tepat_10_menit_diterima_dan_10_menit_1_detik_ditolak(): void
    {
        // Selisih tepat 10:00 -> diterima; tanggal klien = tanggal server, jadi hasilnya 2026-11-10.
        $clock = $this->clock('2026-11-10 00:10:00');
        $this->assertSame('2026-11-10', $clock->resolveLogDate($this->at('2026-11-10 00:00:00')));

        // Batas yang bermakna lintas tengah malam: server 00:09:59, client 23:59:59 kemarin
        // -> selisih tepat 10:00 -> diterima (dicatat kemarin).
        $c2 = $this->clock('2026-11-10 00:09:59');
        $this->assertSame('2026-11-09', $c2->resolveLogDate($this->at('2026-11-09 23:59:59')));
        // Server 00:10:00, client 23:59:59 kemarin -> selisih 10:01 -> ditolak (tanggal server).
        $c3 = $this->clock('2026-11-10 00:10:00');
        $this->assertSame('2026-11-10', $c3->resolveLogDate($this->at('2026-11-09 23:59:59')));
    }

    public function test_client_ts_di_masa_depan_tidak_pernah_melewati_tanggal_server(): void
    {
        // Server 23:56 Senin; jam HP maju 6 menit (00:02 Selasa). Tidak boleh tercatat Selasa.
        $clock = $this->clock('2026-11-09 23:56:00');
        $this->assertSame('2026-11-09', $clock->resolveLogDate($this->at('2026-11-10 00:02:00')));
    }

    public function test_tanggal_tidak_valid_ditolak_keras(): void
    {
        foreach (['2026-02-31', '2026-13-01', 'kemarin', '2026-1-5', ''] as $bad) {
            try {
                CircleClock::weekStartOf($bad);
                $this->fail("Seharusnya menolak '{$bad}'");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_zona_waktu_tidak_valid_ditolak(): void
    {
        $this->expectException(\Exception::class);
        CircleClock::forTimezone('Mars/Olympus', new DateTimeImmutable('now'));
    }
}
