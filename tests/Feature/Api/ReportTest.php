<?php

namespace Tests\Feature\Api;

use App\Models\WeeklyEvaluation;
use Tests\ApiTestCase;

// Aturan periode: sebuah minggu masuk ke bulan/tahun tempat hari KAMIS-nya jatuh.
class ReportTest extends ApiTestCase
{
    private function url(int $circleId, string $query = ''): string
    {
        return "/api/circles/{$circleId}/report{$query}";
    }

    private function evaluasi($circle, $user, string $week, int $total, int $achieved, string $result): void
    {
        WeeklyEvaluation::create([
            'circle_id' => $circle->id, 'user_id' => $user->id, 'week_start' => $week,
            'habits_total' => $total, 'habits_achieved' => $achieved, 'result' => $result,
        ]);
    }

    public function test_laporan_bulanan_menjumlah_minggu_yang_kamisnya_jatuh_di_bulan_itu(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();

        $this->evaluasi($circle, $odi, '2026-10-26', 4, 0, 'punishment'); // Kamis 10-29: Oktober -> tidak ikut
        $this->evaluasi($circle, $odi, '2026-11-02', 3, 3, 'reward');
        $this->evaluasi($circle, $odi, '2026-11-09', 2, 1, 'neutral');
        $this->evaluasi($circle, $odi, '2026-11-16', 0, 0, 'neutral');     // minggu tanpa habit: tidak dihitung sebagai minggu
        $this->evaluasi($circle, $odi, '2026-11-30', 5, 5, 'reward');      // Kamis 12-03: Desember -> tidak ikut

        $this->callAs('GET', $this->url($circle->id, '?period=month&value=2026-11'), $anjal)
            ->assertOk()
            ->assertJsonPath('period', 'month')
            ->assertJsonPath('value', '2026-11')
            ->assertJsonPath('weeks_in_period', 4)           // Senin 2, 9, 16, 23
            ->assertJsonPath('members.0.user_id', $odi->id)
            ->assertJsonPath('members.0.weeks_evaluated', 2)
            ->assertJsonPath('members.0.weeks_reward', 1)
            ->assertJsonPath('members.0.weeks_neutral', 1)
            ->assertJsonPath('members.0.weeks_punishment', 0)
            ->assertJsonPath('members.0.habits_total', 5)
            ->assertJsonPath('members.0.habits_achieved', 4)
            ->assertJsonPath('members.0.percent', 80)
            // Anjal belum punya evaluasi: nol semua, persen kosong (bukan 0%).
            ->assertJsonPath('members.1.user_id', $anjal->id)
            ->assertJsonPath('members.1.weeks_evaluated', 0)
            ->assertJsonPath('members.1.habits_total', 0)
            ->assertJsonPath('members.1.percent', null);

        // Desember memuat minggu 11-30 (5 minggu: Kamis 3, 10, 17, 24, 31).
        $this->callAs('GET', $this->url($circle->id, '?period=month&value=2026-12'), $anjal)
            ->assertJsonPath('weeks_in_period', 5)
            ->assertJsonPath('members.0.weeks_evaluated', 1)
            ->assertJsonPath('members.0.weeks_reward', 1);
    }

    public function test_laporan_tahunan_memakai_tahun_hari_kamis_di_batas_tahun(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();

        $this->evaluasi($circle, $odi, '2025-12-29', 2, 2, 'reward');       // Kamis 2026-01-01 -> 2026
        $this->evaluasi($circle, $odi, '2026-06-01', 2, 1, 'neutral');
        $this->evaluasi($circle, $odi, '2026-12-28', 2, 0, 'punishment');   // Kamis 2026-12-31 -> 2026
        $this->evaluasi($circle, $odi, '2027-01-04', 2, 2, 'reward');       // Kamis 2027-01-07 -> 2027

        $this->callAs('GET', $this->url($circle->id, '?period=year&value=2026'), $anjal)
            ->assertOk()
            ->assertJsonPath('weeks_in_period', 53)           // 2026 adalah tahun ISO 53 minggu
            ->assertJsonPath('members.0.weeks_evaluated', 3)
            ->assertJsonPath('members.0.weeks_reward', 1)
            ->assertJsonPath('members.0.weeks_neutral', 1)
            ->assertJsonPath('members.0.weeks_punishment', 1)
            ->assertJsonPath('members.0.habits_total', 6)
            ->assertJsonPath('members.0.habits_achieved', 3)
            ->assertJsonPath('members.0.percent', 50);

        $this->callAs('GET', $this->url($circle->id, '?period=year&value=2027'), $anjal)
            ->assertJsonPath('members.0.weeks_evaluated', 1);
    }

    public function test_tanpa_value_memakai_bulan_dan_tahun_berjalan_menurut_zona_sesi(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();

        // 2026-11-30 17:30 UTC = Selasa 2026-12-01 00:30 WIB -> bulan berjalan sesi adalah Desember.
        $this->at('2026-11-30 17:30', 'UTC');
        $this->callAs('GET', $this->url($circle->id, '?period=month'), $anjal)->assertJsonPath('value', '2026-12');
        $this->callAs('GET', $this->url($circle->id, '?period=year'), $anjal)->assertJsonPath('value', '2026');
    }

    public function test_validasi_dan_akses(): void
    {
        [, $anjal, $circle] = $this->makePair();

        $this->callAs('GET', $this->url($circle->id), $anjal)->assertJsonValidationErrors('period');
        $this->callAs('GET', $this->url($circle->id, '?period=week'), $anjal)->assertJsonValidationErrors('period');
        $this->callAs('GET', $this->url($circle->id, '?period=year&value=2026-11'), $anjal)->assertJsonValidationErrors('value');
        $this->callAs('GET', $this->url($circle->id, '?period=month&value=2026-13'), $anjal)->assertJsonValidationErrors('value');
        $this->callAs('GET', $this->url($circle->id, '?period=month&value=2026'), $anjal)->assertJsonValidationErrors('value');
        $this->callAs('GET', $this->url($circle->id, '?period=month&value=2026-11'), $this->makeUser())->assertNotFound();
        $this->callAs('GET', $this->url($circle->id, '?period=month&value=2026-11'))->assertUnauthorized();
    }
}
