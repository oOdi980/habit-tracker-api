<?php

namespace Tests\Feature\Api;

use App\Models\Circle;
use App\Models\HabitLeave;
use App\Models\WeeklyEvaluation;
use App\Services\EvaluationService;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

// Kalender acuan (WIB): Senin 2026-11-02, 11-09, 11-16, 11-23. Evaluasi minggu W jatuh di
// Senin berikutnya pukul 00:11 (grace 11 menit) waktu sesi.
class EvaluationTest extends ApiTestCase
{
    private function log(int $habitId, int $userId, string $date): void
    {
        DB::table('habit_logs')->insert(['habit_id' => $habitId, 'user_id' => $userId, 'log_date' => $date]);
    }

    /**
     * Skenario minggu 11-02..11-08 (semua habit mulai 11-02):
     *   A (Odi, target 2): 2 hari            -> tercapai
     *   B (Odi, target 3): 1 hari            -> gagal
     *   C (Anjal, target 1): 1 hari          -> tercapai
     *   S (bersama, target 1): hanya Odi     -> gagal untuk KEDUANYA ("together")
     * Odi: 1 dari 3 = 33% -> punishment. Anjal: 1 dari 2 = 50% -> netral.
     */
    private function skenario(): array
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $start = ['starts_on' => '2026-11-02'];

        $a = $this->makeHabit($circle, $odi, $start + ['title' => 'A', 'target_per_week' => 2]);
        $b = $this->makeHabit($circle, $odi, $start + ['title' => 'B', 'target_per_week' => 3]);
        $c = $this->makeHabit($circle, $anjal, $start + ['title' => 'C', 'target_per_week' => 1]);
        $s = $this->makeHabit($circle, null, $start + ['title' => 'S', 'target_per_week' => 1]);

        $this->log($a->id, $odi->id, '2026-11-02');
        $this->log($a->id, $odi->id, '2026-11-03');
        $this->log($b->id, $odi->id, '2026-11-02');
        $this->log($c->id, $anjal->id, '2026-11-04');
        $this->log($s->id, $odi->id, '2026-11-05');

        return [$odi, $anjal, $circle, $a, $b, $c, $s];
    }

    // ---------- hasil dasar ----------

    public function test_evaluasi_membekukan_ringkasan_per_habit_hasil_per_orang_dan_spin(): void
    {
        [$odi, $anjal, $circle, $a, $b, $c, $s] = $this->skenario();
        $punishment = $this->makeConsequence($circle, 'punishment');

        $this->at('2026-11-09 00:11');
        $this->evaluate();

        $this->assertDatabaseHas('weekly_evaluations', [
            'circle_id' => $circle->id, 'user_id' => $odi->id, 'week_start' => '2026-11-02',
            'habits_total' => 3, 'habits_achieved' => 1, 'result' => 'punishment', 'consequence_id' => $punishment->id,
        ]);
        $this->assertDatabaseHas('weekly_evaluations', [
            'circle_id' => $circle->id, 'user_id' => $anjal->id, 'week_start' => '2026-11-02',
            'habits_total' => 2, 'habits_achieved' => 1, 'result' => 'neutral', 'consequence_id' => null,
        ]);

        // 5 baris: A, B (Odi), C (Anjal), S untuk kedua anggota.
        $this->assertDatabaseCount('habit_weekly_summaries', 5);
        $rows = [
            [$a->id, $odi->id, 'achieved', 2, 2],
            [$b->id, $odi->id, 'missed', 3, 1],
            [$c->id, $anjal->id, 'achieved', 1, 1],
            [$s->id, $odi->id, 'missed', 1, 0],
            [$s->id, $anjal->id, 'missed', 1, 0], // Anjal ikut gagal karena Odi sendirian
        ];
        foreach ($rows as [$habitId, $userId, $status, $target, $done]) {
            $this->assertDatabaseHas('habit_weekly_summaries', [
                'habit_id' => $habitId, 'user_id' => $userId, 'week_start' => '2026-11-02',
                'status' => $status, 'target_snapshot' => $target, 'done_count' => $done,
            ]);
        }

        $this->assertSame('2026-11-02', $circle->fresh()->evaluated_through);
    }

    public function test_sebelum_grace_tidak_dievaluasi_dan_sesudahnya_dievaluasi(): void
    {
        [, , $circle] = $this->skenario();

        $this->at('2026-11-09 00:05'); // minggu 11-02 baru berakhir 5 menit lalu
        $this->evaluate();
        $this->assertDatabaseCount('weekly_evaluations', 0);

        $this->at('2026-11-09 00:11');
        $this->evaluate();
        $this->assertDatabaseCount('weekly_evaluations', 2);
        $this->assertSame('2026-11-02', $circle->fresh()->evaluated_through);
    }

    public function test_dijalankan_berulang_tidak_menggandakan_data(): void
    {
        $this->skenario();
        $this->at('2026-11-09 00:11');

        $this->evaluate();
        $this->evaluate();

        $this->at('2026-11-09 12:00');
        $this->evaluate();

        $this->assertDatabaseCount('weekly_evaluations', 2);
        $this->assertDatabaseCount('habit_weekly_summaries', 5);
    }

    public function test_server_mati_beberapa_minggu_dikejar_berurutan_dengan_data_tiap_minggu(): void
    {
        [$odi, , $circle, $a] = $this->skenario();
        $this->log($a->id, $odi->id, '2026-11-10'); // minggu 11-09: 1 hari saja

        $this->at('2026-11-23 00:11'); // job tidak pernah jalan sejak awal
        $stats = app(EvaluationService::class)->evaluateDueCircles();

        $this->assertSame(['circles' => 1, 'weeks' => 3, 'failed' => 0], $stats);
        $this->assertDatabaseCount('weekly_evaluations', 6); // 3 minggu x 2 orang
        $this->assertSame('2026-11-16', $circle->fresh()->evaluated_through);

        // Minggu 11-09: A target 2, Odi baru 1 hari -> gagal; dihitung dengan data minggu ITU.
        $this->assertDatabaseHas('habit_weekly_summaries', [
            'habit_id' => $a->id, 'user_id' => $odi->id, 'week_start' => '2026-11-09', 'status' => 'missed', 'done_count' => 1,
        ]);
    }

    // ---------- izin ----------

    public function test_izin_yang_disetujui_mengeluarkan_habit_dari_pembagi(): void
    {
        [$odi, $anjal, $circle, , $b] = $this->skenario();
        HabitLeave::create([
            'circle_id' => $circle->id, 'habit_id' => $b->id, 'week_start' => '2026-11-02', 'quota_month' => '2026-11-01',
            'requested_by' => $odi->id, 'approved_by' => $anjal->id, 'approved_at' => now(),
        ]);

        $this->at('2026-11-09 00:11');
        $this->evaluate();

        // B dikeluarkan: Odi tinggal A (tercapai) dan S (gagal) = 1 dari 2 = 50% -> netral, bukan punishment.
        $this->assertDatabaseHas('weekly_evaluations', [
            'user_id' => $odi->id, 'week_start' => '2026-11-02', 'habits_total' => 2, 'habits_achieved' => 1, 'result' => 'neutral',
        ]);
        $this->assertDatabaseHas('habit_weekly_summaries', [
            'habit_id' => $b->id, 'user_id' => $odi->id, 'week_start' => '2026-11-02', 'status' => 'excused', 'done_count' => 1,
        ]);
    }

    public function test_izin_yang_belum_disetujui_dianggap_tidak_ada(): void
    {
        [$odi, , $circle, , $b] = $this->skenario();
        HabitLeave::create([
            'circle_id' => $circle->id, 'habit_id' => $b->id, 'week_start' => '2026-11-02', 'quota_month' => '2026-11-01',
            'requested_by' => $odi->id, // approved_at kosong
        ]);

        $this->at('2026-11-09 00:11');
        $this->evaluate();

        $this->assertDatabaseHas('weekly_evaluations', [
            'user_id' => $odi->id, 'week_start' => '2026-11-02', 'habits_total' => 3, 'result' => 'punishment',
        ]);
        $this->assertDatabaseHas('habit_weekly_summaries', ['habit_id' => $b->id, 'week_start' => '2026-11-02', 'status' => 'missed']);
    }

    public function test_izin_habit_bersama_berlaku_untuk_kedua_anggota(): void
    {
        [$odi, $anjal, $circle, , , , $s] = $this->skenario();
        HabitLeave::create([
            'circle_id' => $circle->id, 'habit_id' => $s->id, 'week_start' => '2026-11-02', 'quota_month' => '2026-11-01',
            'requested_by' => $anjal->id, 'approved_by' => $odi->id, 'approved_at' => now(),
        ]);

        $this->at('2026-11-09 00:11');
        $this->evaluate();

        // Anjal: tinggal C (tercapai) = 1 dari 1 = 100% -> reward. Odi: A ok, B gagal = 1 dari 2 -> netral.
        $this->assertDatabaseHas('weekly_evaluations', ['user_id' => $anjal->id, 'habits_total' => 1, 'habits_achieved' => 1, 'result' => 'reward']);
        $this->assertDatabaseHas('weekly_evaluations', ['user_id' => $odi->id, 'habits_total' => 2, 'habits_achieved' => 1, 'result' => 'neutral']);
        $this->assertDatabaseHas('habit_weekly_summaries', ['habit_id' => $s->id, 'user_id' => $odi->id, 'status' => 'excused']);
        $this->assertDatabaseHas('habit_weekly_summaries', ['habit_id' => $s->id, 'user_id' => $anjal->id, 'status' => 'excused']);
    }

    // ---------- spin ----------

    public function test_reward_diambil_dari_daftar_reward_dan_orang_tanpa_habit_netral(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $c = $this->makeHabit($circle, $anjal, ['starts_on' => '2026-11-02', 'target_per_week' => 1]);
        $this->log($c->id, $anjal->id, '2026-11-04');
        $reward = $this->makeConsequence($circle, 'reward');
        $this->makeConsequence($circle, 'punishment'); // tidak boleh terpilih untuk reward

        $this->at('2026-11-09 00:11');
        $this->evaluate();

        $this->assertDatabaseHas('weekly_evaluations', [
            'user_id' => $anjal->id, 'habits_total' => 1, 'habits_achieved' => 1, 'result' => 'reward', 'consequence_id' => $reward->id,
        ]);
        // Odi tidak punya habit satu pun: 0 habit -> netral (bukan error bagi nol).
        $this->assertDatabaseHas('weekly_evaluations', [
            'user_id' => $odi->id, 'habits_total' => 0, 'habits_achieved' => 0, 'result' => 'neutral', 'consequence_id' => null,
        ]);
    }

    public function test_item_yang_disetujui_setelah_minggu_dimulai_tidak_ikut_spin(): void
    {
        [$odi, , $circle] = $this->skenario();

        // Minggu 11-02 dimulai Senin 00:00 WIB = Minggu 17:00 UTC (2026-11-01).
        $this->makeConsequence($circle, 'punishment', ['title' => 'Tepat saat minggu mulai', 'approved_at' => '2026-11-01 17:00:00']);
        $this->makeConsequence($circle, 'punishment', ['title' => 'Dinonaktifkan', 'is_active' => false]);
        $this->makeConsequence($circle, 'punishment', ['title' => 'Belum disetujui', 'approved_at' => null, 'approved_by' => null]);
        $this->makeConsequence($circle, 'punishment', ['title' => 'Disetujui Rabu', 'approved_at' => '2026-11-04 03:00:00']);

        $this->at('2026-11-09 00:11');
        $this->evaluate();

        // Odi kena punishment, tetapi tidak ada item yang memenuhi syarat: tercatat tanpa isi.
        $this->assertDatabaseHas('weekly_evaluations', ['user_id' => $odi->id, 'result' => 'punishment', 'consequence_id' => null]);
    }

    public function test_item_yang_disetujui_sedetik_sebelum_minggu_dimulai_ikut_spin(): void
    {
        [$odi, , $circle] = $this->skenario();

        $eligible = $this->makeConsequence($circle, 'punishment', ['title' => 'Sah', 'approved_at' => '2026-11-01 16:59:59']);
        $this->makeConsequence($circle, 'punishment', ['title' => 'Terlambat', 'approved_at' => '2026-11-01 17:00:00']);

        $this->at('2026-11-09 00:11');
        $this->evaluate();

        // Hanya satu yang memenuhi syarat, jadi hasilnya pasti dia (bukan kebetulan acak).
        $this->assertDatabaseHas('weekly_evaluations', ['user_id' => $odi->id, 'result' => 'punishment', 'consequence_id' => $eligible->id]);
    }

    // ---------- target tertahan & batas habit ----------

    public function test_target_tertahan_memakai_target_lama_untuk_minggu_lalu_lalu_dipindahkan(): void
    {
        [$odi, , $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, $odi, [
            'starts_on' => '2026-11-02', 'target_per_week' => 3,
            'pending_target_per_week' => 5, 'pending_target_from' => '2026-11-09',
        ]);

        $this->at('2026-11-09 00:11');
        $this->evaluate();

        // Minggu 11-02 dinilai dengan target 3 (yang berlaku saat itu), bukan 5.
        $this->assertDatabaseHas('habit_weekly_summaries', ['habit_id' => $habit->id, 'week_start' => '2026-11-02', 'target_snapshot' => 3]);
        // Setelahnya baru dipindahkan menjadi target berjalan.
        $this->assertDatabaseHas('habits', [
            'id' => $habit->id, 'target_per_week' => 5, 'pending_target_per_week' => null, 'pending_target_from' => null,
        ]);
    }

    public function test_pengejaran_beberapa_minggu_memakai_target_yang_berlaku_di_tiap_minggu(): void
    {
        [$odi, , $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, $odi, [
            'starts_on' => '2026-11-02', 'target_per_week' => 3,
            'pending_target_per_week' => 5, 'pending_target_from' => '2026-11-16',
        ]);

        $this->at('2026-11-23 00:11'); // mengejar 11-02, 11-09, 11-16 sekaligus
        $this->evaluate();

        foreach ([['2026-11-02', 3], ['2026-11-09', 3], ['2026-11-16', 5]] as [$week, $target]) {
            $this->assertDatabaseHas('habit_weekly_summaries', ['habit_id' => $habit->id, 'week_start' => $week, 'target_snapshot' => $target]);
        }
        $this->assertDatabaseHas('habits', ['id' => $habit->id, 'target_per_week' => 5, 'pending_target_from' => null]);
    }

    public function test_target_tertahan_untuk_minggu_depan_belum_dipindahkan(): void
    {
        [$odi, , $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, $odi, [
            'starts_on' => '2026-11-02', 'target_per_week' => 3,
            'pending_target_per_week' => 5, 'pending_target_from' => '2026-11-16', // berlaku 2 minggu lagi
        ]);

        $this->at('2026-11-09 00:11'); // hanya minggu 11-02 yang dievaluasi
        $this->evaluate();

        $this->assertDatabaseHas('habits', ['id' => $habit->id, 'target_per_week' => 3, 'pending_target_per_week' => 5, 'pending_target_from' => '2026-11-16']);
    }

    public function test_habit_yang_berakhir_tidak_dihitung_mulai_minggu_berakhirnya(): void
    {
        [$odi, , $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, $odi, [
            'starts_on' => '2026-11-02', 'ends_on' => '2026-11-09', 'archived_at' => now(), 'target_per_week' => 1,
        ]);

        $this->at('2026-11-16 00:11'); // mengejar 11-02 dan 11-09
        $this->evaluate();

        // Minggu 11-02: ada. Minggu 11-09 (ends_on = 11-09, "berakhir Senin itu"): tidak dihitung.
        $this->assertDatabaseHas('habit_weekly_summaries', ['habit_id' => $habit->id, 'week_start' => '2026-11-02']);
        $this->assertDatabaseMissing('habit_weekly_summaries', ['habit_id' => $habit->id, 'week_start' => '2026-11-09']);
        $this->assertDatabaseHas('weekly_evaluations', ['user_id' => $odi->id, 'week_start' => '2026-11-09', 'habits_total' => 0, 'result' => 'neutral']);
    }

    public function test_habit_yang_baru_mulai_minggu_ini_belum_dievaluasi(): void
    {
        [$odi, , $circle] = $this->makePair();
        $this->makeHabit($circle, $odi, ['starts_on' => '2026-11-09']); // mulai minggu yang baru berjalan

        $this->at('2026-11-09 00:11');
        $this->evaluate();

        $this->assertDatabaseCount('weekly_evaluations', 0);
        $this->assertDatabaseCount('habit_weekly_summaries', 0);
    }

    // ---------- sesi tanpa pekerjaan, zona waktu ----------

    public function test_sesi_tanpa_habit_hanya_memajukan_penanda(): void
    {
        [, , $circle] = $this->makePair();

        $this->at('2026-11-09 00:11');
        $this->evaluate();

        $this->assertDatabaseCount('weekly_evaluations', 0);
        $this->assertSame('2026-11-02', $circle->fresh()->evaluated_through);
    }

    public function test_tiap_sesi_dievaluasi_menurut_zona_waktunya_sendiri(): void
    {
        [$odi, , $circle] = $this->makePair();
        $circle->update(['timezone' => 'America/Los_Angeles']);
        $habit = $this->makeHabit($circle, $odi, ['starts_on' => '2026-11-02', 'target_per_week' => 1]);
        $this->log($habit->id, $odi->id, '2026-11-03');

        // 2026-11-08 17:11 UTC = Senin 00:11 WIB, tetapi di Los Angeles baru Minggu 09:11.
        $this->at('2026-11-08 17:11', 'UTC');
        $this->evaluate();
        $this->assertDatabaseCount('weekly_evaluations', 0);

        // Senin 2026-11-09 00:11 PST = 08:11 UTC.
        $this->at('2026-11-09 08:11', 'UTC');
        $this->evaluate();
        $this->assertDatabaseHas('weekly_evaluations', ['user_id' => $odi->id, 'week_start' => '2026-11-02']);
    }

    // ---------- jendela grace: check-in antre offline ----------

    public function test_check_in_antre_offline_di_pergantian_minggu_masuk_ke_minggu_yang_benar(): void
    {
        [$odi, , $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, $odi, ['starts_on' => '2026-11-02', 'target_per_week' => 1]);

        // Senin 00:05: HP menekan tombol Minggu 23:58 (7 menit lalu), baru terkirim sekarang.
        $this->at('2026-11-09 00:05');
        $this->callAs('POST', "/api/habits/{$habit->id}/check-in", $odi, ['client_ts' => '2026-11-08T23:58:00+07:00'])
            ->assertCreated()
            ->assertJsonPath('check_in.log_date', '2026-11-08');

        // Job jalan setelah grace: check-in tadi SUDAH terhitung di minggu 11-02.
        $this->at('2026-11-09 00:11');
        $this->evaluate();
        $this->assertDatabaseHas('habit_weekly_summaries', [
            'habit_id' => $habit->id, 'week_start' => '2026-11-02', 'status' => 'achieved', 'done_count' => 1,
        ]);
        $this->assertDatabaseHas('weekly_evaluations', ['user_id' => $odi->id, 'week_start' => '2026-11-02', 'result' => 'reward']);

        // Check-in yang terlambat 14 menit lewat toleransi dicatat di hari server (Senin), minggu baru.
        $this->at('2026-11-09 00:12');
        $this->callAs('POST', "/api/habits/{$habit->id}/check-in", $odi, ['client_ts' => '2026-11-08T23:58:00+07:00'])
            ->assertCreated()
            ->assertJsonPath('check_in.log_date', '2026-11-09');
    }

    // ---------- membaca hasil & konfirmasi punishment ----------

    public function test_daftar_evaluasi_memuat_isi_ambang_dan_punishment_yang_menumpuk(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $item = $this->makeConsequence($circle, 'punishment', ['title' => 'Cuci piring seminggu']);

        // Dua minggu berturut-turut Odi kena punishment (menumpuk), Anjal netral.
        foreach (['2026-11-02', '2026-11-09'] as $week) {
            WeeklyEvaluation::create([
                'circle_id' => $circle->id, 'user_id' => $odi->id, 'week_start' => $week,
                'habits_total' => 3, 'habits_achieved' => 1, 'result' => 'punishment', 'consequence_id' => $item->id,
            ]);
            WeeklyEvaluation::create([
                'circle_id' => $circle->id, 'user_id' => $anjal->id, 'week_start' => $week,
                'habits_total' => 2, 'habits_achieved' => 1, 'result' => 'neutral',
            ]);
        }
        $this->at('2026-11-17 10:00');

        $response = $this->callAs('GET', "/api/circles/{$circle->id}/evaluations", $anjal)
            ->assertOk()
            ->assertJsonPath('reward_min_percent', 80)
            ->assertJsonPath('punishment_below_percent', 50)
            ->assertJsonCount(4, 'evaluations')
            ->assertJsonCount(2, 'pending_punishments')
            ->assertJsonPath('evaluations.0.week_start', '2026-11-09') // terbaru dulu
            ->assertJsonPath('evaluations.0.user_id', $odi->id)
            ->assertJsonPath('evaluations.0.percent', 33.3)
            ->assertJsonPath('evaluations.0.week_end', '2026-11-15')
            ->assertJsonPath('evaluations.0.consequence.title', 'Cuci piring seminggu')
            ->assertJsonPath('evaluations.0.can_confirm', true);   // Anjal = partner dari yang kena

        $this->assertSame(false, $response->json('evaluations.1.can_confirm')); // baris Anjal sendiri: netral

        // Dari sisi Odi (yang kena), tidak ada yang bisa ia konfirmasi sendiri.
        $this->callAs('GET', "/api/circles/{$circle->id}/evaluations", $odi)
            ->assertJsonPath('pending_punishments.0.can_confirm', false);

        // limit=1 minggu terakhir saja, tetapi punishment yang menumpuk tetap lengkap.
        $this->callAs('GET', "/api/circles/{$circle->id}/evaluations?limit=1", $anjal)
            ->assertJsonCount(2, 'evaluations')
            ->assertJsonCount(2, 'pending_punishments');

        $this->callAs('GET', "/api/circles/{$circle->id}/evaluations?limit=0", $anjal)->assertJsonValidationErrors('limit');
        $this->callAs('GET', "/api/circles/{$circle->id}/evaluations", $this->makeUser())->assertNotFound();
    }

    public function test_partner_mengonfirmasi_punishment_dan_yang_kena_tidak_bisa_konfirmasi_sendiri(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $evaluation = WeeklyEvaluation::create([
            'circle_id' => $circle->id, 'user_id' => $odi->id, 'week_start' => '2026-11-02',
            'habits_total' => 3, 'habits_achieved' => 1, 'result' => 'punishment',
        ]);
        $url = "/api/evaluations/{$evaluation->id}/fulfill";

        $this->callAs('POST', $url, $odi)->assertForbidden();
        $this->callAs('POST', $url, $this->makeUser())->assertNotFound();

        $this->at('2026-11-10 09:00');
        $this->callAs('POST', $url, $anjal)
            ->assertOk()
            ->assertJsonPath('evaluation.fulfilled', true)
            ->assertJsonPath('evaluation.fulfilled_by', $anjal->id)
            ->assertJsonPath('evaluation.can_confirm', false);

        // Ditekan lagi di hari lain: tidak menimpa waktu konfirmasi pertama.
        $first = $evaluation->fresh()->fulfilled_at;
        $this->at('2026-11-11 09:00');
        $this->callAs('POST', $url, $anjal)->assertOk();
        $this->assertTrue($evaluation->fresh()->fulfilled_at->equalTo($first));

        $this->callAs('GET', "/api/circles/{$circle->id}/evaluations", $anjal)->assertJsonCount(0, 'pending_punishments');
    }

    public function test_reward_dan_netral_tidak_perlu_dikonfirmasi(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $reward = WeeklyEvaluation::create([
            'circle_id' => $circle->id, 'user_id' => $odi->id, 'week_start' => '2026-11-02',
            'habits_total' => 2, 'habits_achieved' => 2, 'result' => 'reward',
        ]);

        $this->callAs('POST', "/api/evaluations/{$reward->id}/fulfill", $anjal)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('evaluation');
    }

    public function test_command_selesai_dengan_sukses_tanpa_data(): void
    {
        $this->at('2026-11-09 00:11');
        $this->artisan('habits:evaluate-weeks')->assertExitCode(0);
        $this->assertSame(0, Circle::count());
    }
}
