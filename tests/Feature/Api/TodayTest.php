<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

// Kalender acuan: 2026-11-09 = Senin, 11-10 = Selasa, 11-15 = Minggu, 11-16 = Senin.
class TodayTest extends ApiTestCase
{
    private function url(int $circleId): string
    {
        return "/api/circles/{$circleId}/today";
    }

    private function insertLog(int $habitId, int $userId, string $date): void
    {
        DB::table('habit_logs')->insert(['habit_id' => $habitId, 'user_id' => $userId, 'log_date' => $date]);
    }

    public function test_papan_memuat_tanggal_minggu_zona_dan_anggota(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $this->at('2026-11-10 10:00'); // Selasa

        $this->callAs('GET', $this->url($circle->id), $odi)
            ->assertOk()
            ->assertJsonPath('date', '2026-11-10')
            ->assertJsonPath('week_start', '2026-11-09')
            ->assertJsonPath('week_end', '2026-11-15')
            ->assertJsonPath('timezone', 'Asia/Jakarta')
            ->assertJsonPath('members.0.id', $odi->id)
            ->assertJsonPath('members.0.name', 'Odi')
            ->assertJsonPath('members.1.id', $anjal->id)
            ->assertJsonPath('habits', []);
    }

    public function test_papan_hanya_memuat_habit_yang_sedang_dihitung(): void
    {
        [$odi, , $circle] = $this->makePair();
        $this->at('2026-11-10 10:00');

        $aktif = $this->makeHabit($circle, $odi, ['title' => 'Aktif']);
        $this->makeHabit($circle, $odi, ['title' => 'Akan datang', 'starts_on' => '2026-11-16']);
        $this->makeHabit($circle, null, ['title' => 'Menunggu', 'starts_on' => null, 'accepted_at' => null]);
        $this->makeHabit($circle, $odi, ['title' => 'Berakhir', 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-09']);
        $diarsipkan = $this->makeHabit($circle, $odi, [
            'title' => 'Diarsipkan', 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-16', 'archived_at' => now(),
        ]);

        $response = $this->callAs('GET', $this->url($circle->id), $odi)->assertOk()->assertJsonCount(2, 'habits');

        $byId = collect($response->json('habits'))->keyBy('id');
        $this->assertSame('active', $byId[$aktif->id]['status']);
        $this->assertSame('ending', $byId[$diarsipkan->id]['status']);
        $this->assertSame('2026-11-16', $byId[$diarsipkan->id]['ends_on']);
    }

    public function test_progres_dan_siapa_yang_sudah_check_in_hari_ini_terlihat_oleh_partner(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $this->at('2026-11-10 10:00');
        $habit = $this->makeHabit($circle, $odi, ['target_per_week' => 3]);

        $this->insertLog($habit->id, $odi->id, '2026-11-09');
        $this->insertLog($habit->id, $odi->id, '2026-11-10');
        $this->insertLog($habit->id, $odi->id, '2026-11-02'); // minggu lalu: tidak dihitung

        // Dilihat dari sisi Anjal (real-time tanpa privasi per habit, sesuai keputusan).
        $this->callAs('GET', $this->url($circle->id), $anjal)
            ->assertOk()
            ->assertJsonPath('habits.0.id', $habit->id)
            ->assertJsonPath('habits.0.week_done', 2)
            ->assertJsonPath('habits.0.target_per_week', 3)
            ->assertJsonPath('habits.0.week_achieved', false)
            ->assertJsonPath('habits.0.done_today_by', [$odi->id]);
    }

    public function test_can_check_in_mengikuti_siapa_yang_melihat(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $this->at('2026-11-10 10:00');
        $milikOdi = $this->makeHabit($circle, $odi);
        $bersama = $this->makeHabit($circle, null);

        $untukOdi = collect($this->callAs('GET', $this->url($circle->id), $odi)->json('habits'))->keyBy('id');
        $this->assertTrue($untukOdi[$milikOdi->id]['can_check_in']);
        $this->assertTrue($untukOdi[$bersama->id]['can_check_in']);

        $untukAnjal = collect($this->callAs('GET', $this->url($circle->id), $anjal)->json('habits'))->keyBy('id');
        $this->assertFalse($untukAnjal[$milikOdi->id]['can_check_in']); // hanya memantau
        $this->assertTrue($untukAnjal[$bersama->id]['can_check_in']);
    }

    public function test_habit_bersama_baru_dihitung_kalau_kedua_anggota_check_in(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $this->at('2026-11-10 10:00');
        $habit = $this->makeHabit($circle, null, ['target_per_week' => 1]);

        $this->insertLog($habit->id, $odi->id, '2026-11-10'); // baru Odi

        $this->callAs('GET', $this->url($circle->id), $anjal)
            ->assertJsonPath('habits.0.week_done', 0)
            ->assertJsonPath('habits.0.week_achieved', false)
            ->assertJsonPath('habits.0.done_today_by', [$odi->id]);

        $this->insertLog($habit->id, $anjal->id, '2026-11-10'); // Anjal menyusul

        $this->callAs('GET', $this->url($circle->id), $anjal)
            ->assertJsonPath('habits.0.week_done', 1)
            ->assertJsonPath('habits.0.week_achieved', true)
            ->assertJsonPath('habits.0.done_today_by', [$odi->id, $anjal->id]);
    }

    public function test_pergantian_minggu_tepat_minggu_2359_ke_senin_0000(): void
    {
        [$odi, , $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, $odi, ['starts_on' => '2026-11-02']);
        $this->insertLog($habit->id, $odi->id, '2026-11-10');
        $this->insertLog($habit->id, $odi->id, '2026-11-15');

        $this->at('2026-11-15 23:59:59'); // Minggu, detik terakhir minggu ini
        $this->callAs('GET', $this->url($circle->id), $odi)
            ->assertJsonPath('date', '2026-11-15')
            ->assertJsonPath('week_start', '2026-11-09')
            ->assertJsonPath('habits.0.week_done', 2)
            ->assertJsonPath('habits.0.done_today_by', [$odi->id]);

        $this->at('2026-11-16 00:00:00'); // Senin: minggu baru, hitungan mulai dari nol
        $this->callAs('GET', $this->url($circle->id), $odi)
            ->assertJsonPath('date', '2026-11-16')
            ->assertJsonPath('week_start', '2026-11-16')
            ->assertJsonPath('week_end', '2026-11-22')
            ->assertJsonPath('habits.0.week_done', 0)
            ->assertJsonPath('habits.0.done_today_by', []);
    }

    public function test_perubahan_target_tertahan_berlaku_tepat_di_senin_yang_dijadwalkan(): void
    {
        [$odi, , $circle] = $this->makePair();
        $this->makeHabit($circle, $odi, [
            'starts_on' => '2026-11-02', 'target_per_week' => 3,
            'pending_target_per_week' => 5, 'pending_target_from' => '2026-11-16',
        ]);

        $this->at('2026-11-15 23:59:59');
        $this->callAs('GET', $this->url($circle->id), $odi)->assertJsonPath('habits.0.target_per_week', 3);

        $this->at('2026-11-16 00:00:00');
        $this->callAs('GET', $this->url($circle->id), $odi)->assertJsonPath('habits.0.target_per_week', 5);
    }

    public function test_hari_dan_minggu_mengikuti_zona_waktu_sesi_bukan_utc(): void
    {
        [$odi, , $circle] = $this->makePair(); // Asia/Jakarta (UTC+7)
        $this->at('2026-11-15 17:30', 'UTC'); // = Senin 2026-11-16 00:30 WIB

        $this->callAs('GET', $this->url($circle->id), $odi)
            ->assertJsonPath('date', '2026-11-16')
            ->assertJsonPath('week_start', '2026-11-16');
    }

    public function test_hitungan_persetujuan_menunggu_hanya_untuk_yang_perlu_menyetujui(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $this->at('2026-11-10 10:00');
        // Dibuat Odi, menunggu Anjal.
        $this->makeHabit($circle, null, ['starts_on' => null, 'accepted_at' => null, 'created_by' => $odi->id]);

        $this->callAs('GET', $this->url($circle->id), $odi)->assertJsonPath('pending_approvals_count', 0);
        $this->callAs('GET', $this->url($circle->id), $anjal)->assertJsonPath('pending_approvals_count', 1);
    }

    public function test_bukan_anggota_dapat_404_dan_tanpa_token_401(): void
    {
        [, , $circle] = $this->makePair();
        $penyusup = $this->makeUser();

        $this->callAs('GET', $this->url($circle->id), $penyusup)->assertNotFound();
        $this->callAs('GET', $this->url($circle->id))->assertUnauthorized();
    }

    public function test_papan_memuat_status_izin_dan_lencana_persetujuan_punishment(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $this->at('2026-11-10 10:00'); // Selasa, minggu berjalan 11-09
        $habit = $this->makeHabit($circle, $odi);

        // Odi mengajukan izin minggu ini (menunggu Anjal) dan mengusulkan satu item punishment.
        $leave = \App\Models\HabitLeave::create([
            'circle_id' => $circle->id, 'habit_id' => $habit->id, 'week_start' => '2026-11-09', 'quota_month' => '2026-11-01',
            'requested_by' => $odi->id,
        ]);
        $this->makeConsequence($circle, 'punishment', ['approved_at' => null, 'approved_by' => null]); // diusulkan Odi
        // Odi kena punishment minggu lalu, belum dikonfirmasi Anjal.
        \App\Models\WeeklyEvaluation::create([
            'circle_id' => $circle->id, 'user_id' => $odi->id, 'week_start' => '2026-11-02',
            'habits_total' => 3, 'habits_achieved' => 1, 'result' => 'punishment',
        ]);

        $odiBoard = $this->callAs('GET', $this->url($circle->id), $odi)->assertOk()
            ->assertJsonPath('habits.0.leave_status', 'pending')
            ->assertJsonPath('pending_leave_approvals_count', 0)          // izin miliknya sendiri
            ->assertJsonPath('pending_consequence_approvals_count', 0)
            ->assertJsonPath('punishments_to_confirm_count', 0)
            ->assertJsonPath('my_open_punishments_count', 1);

        $this->callAs('GET', $this->url($circle->id), $anjal)->assertOk()
            ->assertJsonPath('pending_leave_approvals_count', 1)
            ->assertJsonPath('pending_consequence_approvals_count', 1)
            ->assertJsonPath('punishments_to_confirm_count', 1)
            ->assertJsonPath('my_open_punishments_count', 0);

        // Setelah disetujui: status izin berubah, lencana izin hilang.
        $leave->forceFill(['approved_at' => now(), 'approved_by' => $anjal->id])->save();
        $this->callAs('GET', $this->url($circle->id), $anjal)
            ->assertJsonPath('habits.0.leave_status', 'approved')
            ->assertJsonPath('pending_leave_approvals_count', 0);
    }

    public function test_habit_tanpa_izin_berstatus_izin_null(): void
    {
        [$odi, , $circle] = $this->makePair();
        $this->at('2026-11-10 10:00');
        $this->makeHabit($circle, $odi);

        $this->callAs('GET', $this->url($circle->id), $odi)->assertJsonPath('habits.0.leave_status', null);
    }
}
