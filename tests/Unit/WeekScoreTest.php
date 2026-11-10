<?php

namespace Tests\Unit;

use App\Support\WeekScore;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

// Tes murni untuk aturan hitung evaluasi dan aturan "bulan = bulan hari Kamis".
class WeekScoreTest extends TestCase
{
    public function test_ambang_80_50_pada_batas_tepat(): void
    {
        // total 5: 4 = 80% tepat -> reward; 3 = 60% -> netral; 2 = 40% -> punishment; 2.5 tidak ada.
        $this->assertSame('reward', WeekScore::classify(5, 4, 80, 50));
        $this->assertSame('neutral', WeekScore::classify(5, 3, 80, 50));
        $this->assertSame('punishment', WeekScore::classify(5, 2, 80, 50));

        // total 2: 1 dari 2 = tepat 50% -> netral (punishment hanya KURANG DARI 50%).
        $this->assertSame('neutral', WeekScore::classify(2, 1, 80, 50));
        $this->assertSame('punishment', WeekScore::classify(2, 0, 80, 50));
        $this->assertSame('reward', WeekScore::classify(2, 2, 80, 50));
    }

    public function test_kasus_habit_sedikit(): void
    {
        // total 1: hanya dua kemungkinan.
        $this->assertSame('reward', WeekScore::classify(1, 1, 80, 50));
        $this->assertSame('punishment', WeekScore::classify(1, 0, 80, 50));

        // total 3: 2 dari 3 = 66% netral, 1 dari 3 = 33% punishment.
        $this->assertSame('neutral', WeekScore::classify(3, 2, 80, 50));
        $this->assertSame('punishment', WeekScore::classify(3, 1, 80, 50));
    }

    public function test_tanpa_habit_selalu_netral_tanpa_bagi_nol(): void
    {
        $this->assertSame('neutral', WeekScore::classify(0, 0, 80, 50));
        // Bahkan dengan ambang aneh (punishment di bawah 100%): tetap netral.
        $this->assertSame('neutral', WeekScore::classify(0, 0, 100, 100));
    }

    public function test_tidak_ada_pembulatan_pecahan(): void
    {
        // 7 dari 9 = 77.77% -> di bawah 80 (pembulatan ke 78 pun tetap di bawah, tapi 8 dari 10 = 80 tepat).
        $this->assertSame('neutral', WeekScore::classify(9, 7, 80, 50));
        $this->assertSame('reward', WeekScore::classify(10, 8, 80, 50));
        // 4 dari 9 = 44.4% punishment; 5 dari 9 = 55.5% netral.
        $this->assertSame('punishment', WeekScore::classify(9, 4, 80, 50));
        $this->assertSame('neutral', WeekScore::classify(9, 5, 80, 50));
    }

    public function test_reward_didahulukan_bila_ambang_sama(): void
    {
        // reward_min == punishment_below (tidak ada zona netral): 100% selalu reward, bukan punishment.
        $this->assertSame('reward', WeekScore::classify(4, 4, 70, 70));
        $this->assertSame('punishment', WeekScore::classify(4, 2, 70, 70));
    }

    public function test_persen_turun_ke_bawah_satu_desimal(): void
    {
        $this->assertSame(66.6, WeekScore::percent(3, 2));
        $this->assertSame(100.0, WeekScore::percent(4, 4));
        $this->assertSame(0.0, WeekScore::percent(4, 0));
        $this->assertSame(null, WeekScore::percent(0, 0));
    }

    public function test_bulan_sebuah_minggu_ditentukan_hari_kamisnya(): void
    {
        // Senin 2026-10-26: Kamisnya 10-29 -> Oktober.
        $this->assertSame('2026-10', WeekScore::monthOfWeek('2026-10-26'));
        // Senin 2026-11-30: Kamisnya 12-03 -> Desember (walau Senin-nya masih November).
        $this->assertSame('2026-12', WeekScore::monthOfWeek('2026-11-30'));
        // Senin 2026-11-02: Kamisnya 11-05 -> November.
        $this->assertSame('2026-11', WeekScore::monthOfWeek('2026-11-02'));
    }

    public function test_tahun_sebuah_minggu_ditentukan_hari_kamisnya(): void
    {
        // Senin 2026-12-28: Kamisnya 2026-12-31 (2027-01-01 adalah Jumat) -> masih 2026.
        $this->assertSame(2026, WeekScore::yearOfWeek('2026-12-28'));
        // Senin 2025-12-29: Kamisnya 2026-01-01 -> sudah 2026 (Senin-nya masih Desember 2025).
        $this->assertSame(2026, WeekScore::yearOfWeek('2025-12-29'));
        // Senin 2027-01-04: Kamisnya 2027-01-07 -> 2027.
        $this->assertSame(2027, WeekScore::yearOfWeek('2027-01-04'));
    }

    public function test_rentang_minggu_untuk_bulan(): void
    {
        // Nov 2026: Kamis jatuh pada 5, 12, 19, 26 -> Senin 2, 9, 16, 23.
        [$from, $to] = WeekScore::weekRange('month', '2026-11');
        $this->assertSame('2026-10-29', $from); // Senin pertama >= batas ini adalah 11-02
        $this->assertSame('2026-11-27', $to);   // Senin terakhir <= batas ini adalah 11-23
        $mondays = $this->mondaysBetween($from, $to);
        $this->assertSame(['2026-11-02', '2026-11-09', '2026-11-16', '2026-11-23'], $mondays);

        // Des 2026: Kamis 3, 10, 17, 24, 31 -> Senin 11-30, 12-07, 12-14, 12-21, 12-28 (5 minggu).
        [$from, $to] = WeekScore::weekRange('month', '2026-12');
        $this->assertSame(['2026-11-30', '2026-12-07', '2026-12-14', '2026-12-21', '2026-12-28'], $this->mondaysBetween($from, $to));

        // Februari tahun kabisat 2028 (29 hari) tidak boleh error.
        [$from, $to] = WeekScore::weekRange('month', '2028-02');
        $this->assertSame('2028-02-26', $to);
    }

    public function test_rentang_minggu_untuk_tahun_tidak_tumpang_tindih_antar_tahun(): void
    {
        $y2026 = $this->mondaysBetween(...WeekScore::weekRange('year', '2026'));
        $y2027 = $this->mondaysBetween(...WeekScore::weekRange('year', '2027'));

        // 2026 dimulai Senin 2025-12-29 (Kamis 2026-01-01) dan berakhir Senin 2026-12-28 (Kamis 12-31).
        $this->assertSame('2025-12-29', $y2026[0]);
        $this->assertSame('2026-12-28', $y2026[count($y2026) - 1]);
        // 2027 dimulai Senin 2027-01-04 (Kamis 2027-01-07).
        $this->assertSame('2027-01-04', $y2027[0]);
        // Tidak ada Senin yang masuk dua tahun sekaligus; 2026 adalah tahun ISO 53 minggu.
        $this->assertSame([], array_values(array_intersect($y2026, $y2027)));
        $this->assertSame(53, count($y2026));
    }

    public function test_bulan_tahun_dan_periode_tidak_valid_ditolak(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WeekScore::weekRange('month', '2026-13');
    }

    public function test_periode_tidak_dikenal_ditolak(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WeekScore::weekRange('week', '2026-11');
    }

    /** Semua Senin dalam [from, to] (inklusif). */
    private function mondaysBetween(string $from, string $to): array
    {
        $result = [];
        for ($d = new \DateTimeImmutable($from, new \DateTimeZone('UTC')); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) {
            if ($d->format('N') === '1') {
                $result[] = $d->format('Y-m-d');
            }
        }

        return $result;
    }
}
