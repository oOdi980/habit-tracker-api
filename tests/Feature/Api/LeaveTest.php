<?php

namespace Tests\Feature\Api;

use App\Models\HabitLeave;
use Tests\ApiTestCase;

// Kalender acuan (WIB): Rabu 2026-11-04 -> minggu berjalan 11-02, minggu depan 11-09.
// Rabu 2026-11-25 -> minggu berjalan 11-23 (Kamis 11-26: November), minggu depan 11-30 (Kamis 12-03: Desember).
class LeaveTest extends ApiTestCase
{
    private function url(int $habitId): string
    {
        return "/api/habits/{$habitId}/leaves";
    }

    /** Habit personal Odi yang sudah berjalan sejak 2026-10-26 (aktif di semua minggu yang dipakai tes). */
    private function aktif($circle, $owner, array $attrs = [])
    {
        return $this->makeHabit($circle, $owner, $attrs + ['starts_on' => '2026-10-26']);
    }

    public function test_mengajukan_izin_minggu_ini_dan_minggu_depan(): void
    {
        [$odi, , $circle] = $this->makePair();
        $habit = $this->aktif($circle, $odi);
        $this->at('2026-11-04 10:00');

        $this->callAs('POST', $this->url($habit->id), $odi, ['week' => 'current'])
            ->assertCreated()
            ->assertJsonPath('leave.week_start', '2026-11-02')
            ->assertJsonPath('leave.approved', false)
            ->assertJsonPath('leave.needs_my_approval', false);

        $this->callAs('POST', $this->url($habit->id), $odi, ['week' => 'next'])
            ->assertCreated()
            ->assertJsonPath('leave.week_start', '2026-11-09');

        // Bulan kuota disimpan dari aturan hari Kamis (keduanya November).
        $this->assertDatabaseCount('habit_leaves', 2);
        $this->assertDatabaseHas('habit_leaves', ['habit_id' => $habit->id, 'week_start' => '2026-11-02', 'quota_month' => '2026-11-01']);
    }

    public function test_validasi_dan_akses(): void
    {
        [$odi, , $circle] = $this->makePair();
        $habit = $this->aktif($circle, $odi);
        $this->at('2026-11-04 10:00');

        $this->callAs('POST', $this->url($habit->id), $odi, ['week' => '2026-11-09'])->assertJsonValidationErrors('week');
        $this->callAs('POST', $this->url($habit->id), $odi, [])->assertJsonValidationErrors('week');
        $this->callAs('POST', $this->url($habit->id), $this->makeUser(), ['week' => 'current'])->assertNotFound();
        $this->callAs('POST', $this->url($habit->id), null, ['week' => 'current'])->assertUnauthorized();
    }

    public function test_partner_menyetujui_dan_pengaju_tidak_bisa_menyetujui_sendiri(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $habit = $this->aktif($circle, $odi);
        $this->at('2026-11-04 10:00');
        $id = $this->callAs('POST', $this->url($habit->id), $odi, ['week' => 'current'])->json('leave.id');

        $this->callAs('POST', "/api/leaves/{$id}/approve", $odi)->assertForbidden();

        $this->callAs('POST', "/api/leaves/{$id}/approve", $anjal)
            ->assertOk()
            ->assertJsonPath('leave.approved', true);

        // Idempoten, dan waktu persetujuan pertama tidak bergeser.
        $first = $this->app['db']->table('habit_leaves')->where('id', $id)->value('approved_at');
        $this->at('2026-11-05 10:00');
        $this->callAs('POST', "/api/leaves/{$id}/approve", $anjal)->assertOk();
        $this->assertSame($first, $this->app['db']->table('habit_leaves')->where('id', $id)->value('approved_at'));
    }

    public function test_izin_habit_personal_hanya_dari_pemiliknya(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $habit = $this->aktif($circle, $odi);
        $this->at('2026-11-04 10:00');

        $this->callAs('POST', $this->url($habit->id), $anjal, ['week' => 'current'])->assertForbidden();
        $this->assertDatabaseCount('habit_leaves', 0);
    }

    public function test_habit_bersama_bisa_diajukan_siapa_saja_dan_kuota_milik_pengaju(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $shared = $this->aktif($circle, null);
        $this->at('2026-11-04 10:00');

        $this->callAs('POST', $this->url($shared->id), $anjal, ['week' => 'current'])
            ->assertCreated()
            ->assertJsonPath('leave.requested_by', $anjal->id);

        // Yang menyetujui adalah partnernya (Odi), bukan pengaju.
        $id = HabitLeave::first()->id;
        $this->callAs('POST', "/api/leaves/{$id}/approve", $anjal)->assertForbidden();
        $this->callAs('POST', "/api/leaves/{$id}/approve", $odi)->assertOk();
    }

    public function test_izin_ganda_untuk_habit_dan_minggu_yang_sama_ditolak(): void
    {
        [$odi, , $circle] = $this->makePair();
        $habit = $this->aktif($circle, $odi);
        $this->at('2026-11-04 10:00');

        $this->callAs('POST', $this->url($habit->id), $odi, ['week' => 'current'])->assertCreated();
        $this->callAs('POST', $this->url($habit->id), $odi, ['week' => 'current'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('habit');
        $this->assertDatabaseCount('habit_leaves', 1);
    }

    public function test_habit_yang_belum_mulai_atau_sudah_berakhir_tidak_bisa_diberi_izin(): void
    {
        [$odi, , $circle] = $this->makePair();
        $this->at('2026-11-04 10:00');

        $belumMulai = $this->makeHabit($circle, $odi, ['starts_on' => '2026-11-16']);
        $this->callAs('POST', $this->url($belumMulai->id), $odi, ['week' => 'current'])->assertJsonValidationErrors('habit');
        $this->callAs('POST', $this->url($belumMulai->id), $odi, ['week' => 'next'])->assertJsonValidationErrors('habit'); // 11-09 < 11-16

        // Berakhir Senin 11-09: minggu ini masih dihitung, minggu depan tidak.
        $berakhir = $this->makeHabit($circle, $odi, ['starts_on' => '2026-10-26', 'ends_on' => '2026-11-09', 'archived_at' => now()]);
        $this->callAs('POST', $this->url($berakhir->id), $odi, ['week' => 'next'])->assertJsonValidationErrors('habit');
        $this->callAs('POST', $this->url($berakhir->id), $odi, ['week' => 'current'])->assertCreated();

        // Habit bersama yang menunggu persetujuan (starts_on kosong) juga belum dihitung.
        $menunggu = $this->makeHabit($circle, null, ['starts_on' => null, 'accepted_at' => null]);
        $this->callAs('POST', $this->url($menunggu->id), $odi, ['week' => 'current'])->assertJsonValidationErrors('habit');
    }

    public function test_kuota_empat_per_orang_per_bulan_boleh_dipakai_sekaligus_di_satu_minggu(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $habits = [];
        for ($i = 0; $i < 5; $i++) {
            $habits[] = $this->aktif($circle, $odi, ['title' => "H{$i}"]);
        }
        $shared = $this->aktif($circle, null);
        $this->at('2026-11-04 10:00');

        // Empat izin sekaligus di minggu yang sama: boleh.
        for ($i = 0; $i < 4; $i++) {
            $this->callAs('POST', $this->url($habits[$i]->id), $odi, ['week' => 'current'])->assertCreated();
        }

        // Kelima (walau untuk minggu depan yang masih November): ditolak.
        $this->callAs('POST', $this->url($habits[4]->id), $odi, ['week' => 'next'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('leave');

        // Kuota milik ORANG: Anjal masih punya jatah penuh (lewat habit bersama).
        $this->callAs('POST', $this->url($shared->id), $anjal, ['week' => 'current'])->assertCreated();
    }

    public function test_bulan_kuota_mengikuti_hari_kamis_minggu_itu(): void
    {
        [$odi, , $circle] = $this->makePair();
        $habits = [];
        for ($i = 0; $i < 5; $i++) {
            $habits[] = $this->aktif($circle, $odi, ['title' => "H{$i}"]);
        }
        $this->at('2026-11-25 10:00'); // minggu berjalan 11-23 (November), minggu depan 11-30 (Kamisnya 12-03: Desember)

        for ($i = 0; $i < 4; $i++) {
            $this->callAs('POST', $this->url($habits[$i]->id), $odi, ['week' => 'current'])->assertCreated();
        }

        // Kuota November habis, tetapi minggu 11-30 dihitung ke Desember: kuota Desember masih penuh.
        $this->callAs('POST', $this->url($habits[4]->id), $odi, ['week' => 'next'])->assertCreated();
        $this->assertDatabaseHas('habit_leaves', ['habit_id' => $habits[4]->id, 'week_start' => '2026-11-30', 'quota_month' => '2026-12-01']);
    }

    public function test_membatalkan_izin_mengembalikan_kuota(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $habits = [];
        for ($i = 0; $i < 5; $i++) {
            $habits[] = $this->aktif($circle, $odi, ['title' => "H{$i}"]);
        }
        $this->at('2026-11-04 10:00');

        $ids = [];
        for ($i = 0; $i < 4; $i++) {
            $ids[] = $this->callAs('POST', $this->url($habits[$i]->id), $odi, ['week' => 'current'])->json('leave.id');
        }
        $this->callAs('POST', $this->url($habits[4]->id), $odi, ['week' => 'current'])->assertUnprocessable();

        $this->callAs('DELETE', "/api/leaves/{$ids[0]}", $odi)->assertNoContent();
        $this->callAs('POST', $this->url($habits[4]->id), $odi, ['week' => 'current'])->assertCreated();
    }

    public function test_pembatalan_menolak_dan_izin_yang_sudah_disetujui(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $a = $this->aktif($circle, $odi, ['title' => 'A']);
        $b = $this->aktif($circle, $odi, ['title' => 'B']);
        $this->at('2026-11-04 10:00');

        // Belum disetujui: partner boleh menolak (hapus).
        $tolak = $this->callAs('POST', $this->url($a->id), $odi, ['week' => 'current'])->json('leave.id');
        $this->callAs('DELETE', "/api/leaves/{$tolak}", $anjal)->assertNoContent();
        $this->assertDatabaseMissing('habit_leaves', ['id' => $tolak]);

        // Sudah disetujui: partner tidak boleh mencabut, pengaju boleh membatalkan.
        $sah = $this->callAs('POST', $this->url($b->id), $odi, ['week' => 'current'])->json('leave.id');
        $this->callAs('POST', "/api/leaves/{$sah}/approve", $anjal)->assertOk();
        $this->callAs('DELETE', "/api/leaves/{$sah}", $anjal)->assertForbidden();
        $this->callAs('DELETE', "/api/leaves/{$sah}", $odi)->assertNoContent();

        // Sudah tidak ada: 404; bukan anggota: 404.
        $this->callAs('DELETE', "/api/leaves/{$sah}", $odi)->assertNotFound();
        $this->callAs('DELETE', "/api/leaves/{$sah}", $this->makeUser())->assertNotFound();
    }

    public function test_minggu_yang_sudah_lewat_terkunci_untuk_persetujuan_dan_pembatalan(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $habit = $this->aktif($circle, $odi);
        $leave = HabitLeave::create([
            'circle_id' => $circle->id, 'habit_id' => $habit->id, 'week_start' => '2026-11-02', 'quota_month' => '2026-11-01',
            'requested_by' => $odi->id,
        ]);

        $this->at('2026-11-09 10:00'); // minggu 11-02 sudah lewat

        $this->callAs('POST', "/api/leaves/{$leave->id}/approve", $anjal)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('leave');
        $this->callAs('DELETE', "/api/leaves/{$leave->id}", $odi)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('leave');
        $this->assertDatabaseHas('habit_leaves', ['id' => $leave->id, 'approved_at' => null]);
    }

    public function test_detik_pertama_minggu_baru_sudah_mengunci_minggu_lalu(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $habit = $this->aktif($circle, $odi);
        $leave = HabitLeave::create([
            'circle_id' => $circle->id, 'habit_id' => $habit->id, 'week_start' => '2026-11-02', 'quota_month' => '2026-11-01',
            'requested_by' => $odi->id,
        ]);

        $this->at('2026-11-08 23:59:59'); // Minggu, detik terakhir: masih boleh disetujui
        $this->callAs('POST', "/api/leaves/{$leave->id}/approve", $anjal)->assertOk();

        $this->at('2026-11-09 00:00:00');  // Senin tepat: terkunci
        $this->callAs('DELETE', "/api/leaves/{$leave->id}", $odi)->assertUnprocessable();
    }

    public function test_daftar_izin_memuat_minggu_ini_minggu_depan_dan_sisa_kuota(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $a = $this->aktif($circle, $odi, ['title' => 'A']);
        $b = $this->aktif($circle, $odi, ['title' => 'B']);
        $this->at('2026-11-04 10:00');

        $this->callAs('POST', $this->url($a->id), $odi, ['week' => 'current']);
        $this->callAs('POST', $this->url($b->id), $odi, ['week' => 'next']);
        // Izin minggu lalu tidak ikut tampil.
        HabitLeave::create([
            'circle_id' => $circle->id, 'habit_id' => $a->id, 'week_start' => '2026-10-26', 'quota_month' => '2026-10-01',
            'requested_by' => $odi->id,
        ]);

        $this->callAs('GET', "/api/circles/{$circle->id}/leaves", $odi)
            ->assertOk()
            ->assertJsonPath('week_start', '2026-11-02')
            ->assertJsonPath('next_week_start', '2026-11-09')
            ->assertJsonCount(2, 'leaves')
            ->assertJsonPath('quota.2026-11.used', 2)
            ->assertJsonPath('quota.2026-11.limit', 4);

        // Kuota dihitung per orang: dari sisi Anjal belum terpakai, dan ia melihat izin yang perlu ia setujui.
        $this->callAs('GET', "/api/circles/{$circle->id}/leaves", $anjal)
            ->assertJsonPath('quota.2026-11.used', 0)
            ->assertJsonPath('leaves.0.needs_my_approval', true);

        $this->callAs('GET', "/api/circles/{$circle->id}/leaves", $this->makeUser())->assertNotFound();
    }
}
