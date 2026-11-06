<?php

namespace Tests\Feature\Api;

use App\Models\HabitLog;
use App\Models\HabitWeeklySummary;
use App\Models\WeeklyEvaluation;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

// Kalender acuan: 2026-11-08 = Minggu, 11-09 = Senin, 11-10 = Selasa, 11-15 = Minggu, 11-16 = Senin.
class CheckInTest extends ApiTestCase
{
    private function url(int $habitId): string
    {
        return "/api/habits/{$habitId}/check-in";
    }

    private function insertLog(int $habitId, int $userId, string $date): void
    {
        DB::table('habit_logs')->insert(['habit_id' => $habitId, 'user_id' => $userId, 'log_date' => $date]);
    }

    // ---------- check-in dasar ----------

    public function test_check_in_berhasil_dan_mengembalikan_progres_minggu_ini(): void
    {
        [$odi, , $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, $odi, ['target_per_week' => 2]);
        $this->at('2026-11-10 08:00'); // Selasa

        $this->callAs('POST', $this->url($habit->id), $odi)
            ->assertCreated()
            ->assertJsonPath('check_in.created', true)
            ->assertJsonPath('check_in.habit_id', $habit->id)
            ->assertJsonPath('check_in.log_date', '2026-11-10')
            ->assertJsonPath('check_in.progress.week_start', '2026-11-09')
            ->assertJsonPath('check_in.progress.done', 1)
            ->assertJsonPath('check_in.progress.target', 2)
            ->assertJsonPath('check_in.progress.achieved', false);

        $this->at('2026-11-11 08:00'); // Rabu: check-in kedua memenuhi target 2
        $this->callAs('POST', $this->url($habit->id), $odi)
            ->assertCreated()
            ->assertJsonPath('check_in.progress.done', 2)
            ->assertJsonPath('check_in.progress.achieved', true);
    }

    public function test_check_in_dua_kali_di_hari_yang_sama_idempoten(): void
    {
        [$odi, , $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, $odi);
        $this->at('2026-11-10 08:00');

        $this->callAs('POST', $this->url($habit->id), $odi)->assertCreated();

        $this->callAs('POST', $this->url($habit->id), $odi)
            ->assertOk() // bukan 201: tidak ada yang baru
            ->assertJsonPath('check_in.created', false)
            ->assertJsonPath('check_in.progress.done', 1);

        $this->assertSame(1, HabitLog::where('habit_id', $habit->id)->count());
    }

    public function test_partner_tidak_boleh_check_in_habit_personal_orang_lain(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, $odi);
        $this->at('2026-11-10 08:00');

        $this->callAs('POST', $this->url($habit->id), $anjal)->assertForbidden();
        $this->callAs('DELETE', $this->url($habit->id), $anjal)->assertForbidden();
        $this->assertSame(0, HabitLog::count());
    }

    public function test_bukan_anggota_dapat_404(): void
    {
        [$odi, , $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, $odi);
        $penyusup = $this->makeUser();
        $this->at('2026-11-10 08:00');

        $this->callAs('POST', $this->url($habit->id), $penyusup)->assertNotFound();
        $this->callAs('DELETE', $this->url($habit->id), $penyusup)->assertNotFound();
        $this->callAs('POST', $this->url(999999), $odi)->assertNotFound();
    }

    public function test_tanpa_token_dapat_401(): void
    {
        $this->callAs('POST', $this->url(1))->assertUnauthorized();
    }

    // ---------- belum mulai / belum disetujui ----------

    public function test_check_in_sebelum_habit_mulai_ditolak_dan_diterima_tepat_senin_00_00(): void
    {
        [$odi, , $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, $odi); // mulai Senin 11-09

        $this->at('2026-11-08 23:59:59'); // Minggu, 1 detik sebelum mulai
        $this->callAs('POST', $this->url($habit->id), $odi)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('habit');
        $this->assertSame(0, HabitLog::count());

        $this->at('2026-11-09 00:00:00'); // Senin tepat
        $this->callAs('POST', $this->url($habit->id), $odi)
            ->assertCreated()
            ->assertJsonPath('check_in.log_date', '2026-11-09');
    }

    public function test_habit_bersama_yang_belum_disetujui_tidak_bisa_di_check_in(): void
    {
        [$odi, , $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, null, ['starts_on' => null, 'accepted_at' => null]);
        $this->at('2026-11-10 08:00');

        $this->callAs('POST', $this->url($habit->id), $odi)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('habit');
    }

    // ---------- habit bersama ----------

    public function test_habit_bersama_baru_dihitung_kalau_kedua_anggota_check_in_di_hari_yang_sama(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, null, ['target_per_week' => 2]);

        $this->at('2026-11-10 08:00'); // Selasa: Odi saja
        $this->callAs('POST', $this->url($habit->id), $odi)
            ->assertCreated()
            ->assertJsonPath('check_in.progress.done', 0);

        $this->at('2026-11-10 20:00'); // Selasa malam: Anjal menyusul -> hari itu lengkap
        $this->callAs('POST', $this->url($habit->id), $anjal)
            ->assertCreated()
            ->assertJsonPath('check_in.progress.done', 1);

        $this->at('2026-11-11 08:00'); // Rabu: hanya Anjal -> tidak menambah
        $this->callAs('POST', $this->url($habit->id), $anjal)
            ->assertCreated()
            ->assertJsonPath('check_in.progress.done', 1)
            ->assertJsonPath('check_in.progress.achieved', false);

        $this->at('2026-11-11 21:00'); // Rabu malam: Odi menyusul -> 2 hari lengkap = target
        $this->callAs('POST', $this->url($habit->id), $odi)
            ->assertCreated()
            ->assertJsonPath('check_in.progress.done', 2)
            ->assertJsonPath('check_in.progress.achieved', true);
    }

    // ---------- client_ts ----------

    public function test_client_ts_dalam_toleransi_dipakai_untuk_antrean_offline_di_pergantian_hari(): void
    {
        [$odi, , $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, $odi, ['starts_on' => '2026-11-02']);
        $this->at('2026-11-10 00:05'); // server sudah Selasa; HP menekan tombol Senin 23:58 (7 menit lalu)

        $this->callAs('POST', $this->url($habit->id), $odi, ['client_ts' => '2026-11-09T23:58:00+07:00'])
            ->assertCreated()
            ->assertJsonPath('check_in.log_date', '2026-11-09'); // dihitung Senin, bukan Selasa

        $this->assertDatabaseHas('habit_logs', ['habit_id' => $habit->id, 'user_id' => $odi->id, 'log_date' => '2026-11-09']);
    }

    public function test_client_ts_terlalu_lama_diabaikan_dan_memakai_tanggal_server(): void
    {
        [$odi, , $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, $odi, ['starts_on' => '2026-11-02']);
        $this->at('2026-11-10 00:05');

        // 35 menit lalu: di luar toleransi 10 menit -> tidak dipercaya.
        $this->callAs('POST', $this->url($habit->id), $odi, ['client_ts' => '2026-11-09T23:30:00+07:00'])
            ->assertCreated()
            ->assertJsonPath('check_in.log_date', '2026-11-10');

        // Jam HP yang jauh di masa lalu (mencoba mengisi mundur) juga diabaikan.
        $this->callAs('POST', $this->url($habit->id), $odi, ['client_ts' => '2026-11-03T12:00:00+07:00'])
            ->assertOk()
            ->assertJsonPath('check_in.created', false)
            ->assertJsonPath('check_in.log_date', '2026-11-10');
    }

    public function test_client_ts_di_masa_depan_tidak_pernah_melewati_tanggal_server(): void
    {
        [$odi, , $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, $odi, ['starts_on' => '2026-11-02']);
        $this->at('2026-11-10 23:58'); // jam HP maju 5 menit -> sudah "Rabu" di HP

        $this->callAs('POST', $this->url($habit->id), $odi, ['client_ts' => '2026-11-11T00:03:00+07:00'])
            ->assertCreated()
            ->assertJsonPath('check_in.log_date', '2026-11-10'); // tidak boleh mencatat besok
    }

    public function test_client_ts_dengan_zona_utc_dikonversi_ke_zona_sesi(): void
    {
        [$odi, , $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, $odi, ['starts_on' => '2026-11-02']);
        $this->at('2026-11-10 00:05'); // WIB

        // 16:58 UTC Senin = 23:58 WIB Senin
        $this->callAs('POST', $this->url($habit->id), $odi, ['client_ts' => '2026-11-09T16:58:00Z'])
            ->assertCreated()
            ->assertJsonPath('check_in.log_date', '2026-11-09');
    }

    public function test_client_ts_rusak_atau_tanpa_zona_ditolak(): void
    {
        [$odi, , $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, $odi);
        $this->at('2026-11-10 08:00');

        foreach (['bukan-tanggal', '2026-11-10T08:00:00', '2026-13-45T08:00:00+07:00', '10/11/2026'] as $bad) {
            $this->callAs('POST', $this->url($habit->id), $odi, ['client_ts' => $bad])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('client_ts');
        }

        $this->assertSame(0, HabitLog::count());
    }

    // ---------- minggu yang sudah dikunci ----------

    public function test_minggu_yang_sudah_dievaluasi_lewat_ringkasan_tidak_bisa_diubah(): void
    {
        [$odi, , $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, $odi, ['starts_on' => '2026-11-02']);
        $this->at('2026-11-10 08:00');

        HabitWeeklySummary::create([
            'habit_id' => $habit->id, 'user_id' => $odi->id, 'week_start' => '2026-11-09',
            'target_snapshot' => 3, 'done_count' => 1, 'status' => 'missed', 'evaluated_at' => now(),
        ]);

        $this->callAs('POST', $this->url($habit->id), $odi)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('habit');
        $this->callAs('DELETE', $this->url($habit->id), $odi)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('habit');
    }

    public function test_minggu_yang_sudah_dievaluasi_lewat_evaluasi_mingguan_tidak_bisa_diubah(): void
    {
        [$odi, , $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, $odi, ['starts_on' => '2026-11-02']);
        $this->at('2026-11-10 08:00');

        WeeklyEvaluation::create([
            'circle_id' => $circle->id, 'user_id' => $odi->id, 'week_start' => '2026-11-09',
            'habits_total' => 1, 'habits_achieved' => 0, 'result' => 'punishment',
        ]);

        $this->callAs('POST', $this->url($habit->id), $odi)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('habit');
    }

    public function test_evaluasi_milik_partner_atau_minggu_lain_tidak_menghalangi(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, $odi, ['starts_on' => '2026-11-02']);
        $this->at('2026-11-10 08:00');

        // Evaluasi Anjal minggu ini + evaluasi Odi minggu LALU: tidak ada hubungannya dengan check-in Odi sekarang.
        foreach ([[$anjal->id, '2026-11-09'], [$odi->id, '2026-11-02']] as [$userId, $week]) {
            WeeklyEvaluation::create([
                'circle_id' => $circle->id, 'user_id' => $userId, 'week_start' => $week,
                'habits_total' => 1, 'habits_achieved' => 1, 'result' => 'reward',
            ]);
        }

        $this->callAs('POST', $this->url($habit->id), $odi)->assertCreated();
    }

    // ---------- undo ----------

    public function test_undo_menghapus_check_in_hari_ini_saja(): void
    {
        [$odi, , $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, $odi, ['starts_on' => '2026-11-02']);
        $this->at('2026-11-10 08:00');

        $this->insertLog($habit->id, $odi->id, '2026-11-09'); // kemarin: terkunci
        $this->callAs('POST', $this->url($habit->id), $odi)->assertCreated();

        $this->callAs('DELETE', $this->url($habit->id), $odi)
            ->assertOk()
            ->assertJsonPath('check_in.deleted', true)
            ->assertJsonPath('check_in.log_date', '2026-11-10')
            ->assertJsonPath('check_in.progress.done', 1); // log kemarin tetap

        $this->assertDatabaseHas('habit_logs', ['habit_id' => $habit->id, 'log_date' => '2026-11-09']);
        $this->assertDatabaseMissing('habit_logs', ['habit_id' => $habit->id, 'log_date' => '2026-11-10']);

        // Undo lagi: tidak ada yang dihapus, tetap 200 (idempoten).
        $this->callAs('DELETE', $this->url($habit->id), $odi)
            ->assertOk()
            ->assertJsonPath('check_in.deleted', false);

        // Tidak ada cara membatalkan log kemarin: undo hanya menyentuh "hari ini".
        $this->assertDatabaseHas('habit_logs', ['habit_id' => $habit->id, 'log_date' => '2026-11-09']);
    }

    public function test_undo_tidak_menyentuh_check_in_partner_pada_habit_bersama(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, null);
        $this->at('2026-11-10 08:00');

        $this->callAs('POST', $this->url($habit->id), $odi)->assertCreated();
        $this->callAs('POST', $this->url($habit->id), $anjal)->assertCreated();

        $this->callAs('DELETE', $this->url($habit->id), $anjal)->assertOk()->assertJsonPath('check_in.deleted', true);

        $this->assertDatabaseHas('habit_logs', ['habit_id' => $habit->id, 'user_id' => $odi->id, 'log_date' => '2026-11-10']);
        $this->assertDatabaseMissing('habit_logs', ['habit_id' => $habit->id, 'user_id' => $anjal->id]);
    }

    // ---------- habit yang sedang diarsipkan ----------

    public function test_habit_yang_diarsipkan_masih_bisa_check_in_sampai_minggu_ini_selesai(): void
    {
        [$odi, , $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, $odi, [
            'starts_on' => '2026-11-02', 'ends_on' => '2026-11-16', 'archived_at' => now(),
        ]);

        $this->at('2026-11-15 23:59:59'); // Minggu terakhir, detik terakhir
        $this->callAs('POST', $this->url($habit->id), $odi)
            ->assertCreated()
            ->assertJsonPath('check_in.log_date', '2026-11-15');

        $this->at('2026-11-16 00:00:00'); // sudah berakhir
        $this->callAs('POST', $this->url($habit->id), $odi)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('habit');
    }
}
