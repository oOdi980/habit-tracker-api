<?php

namespace Tests\Feature\Api;

use Tests\ApiTestCase;

// Kalender acuan: 2026-11-02 = Senin, 11-04 = Rabu, 11-08 = Minggu, 11-09 = Senin, 11-16 = Senin.
class HabitTest extends ApiTestCase
{
    private function habitsUrl(int $circleId): string
    {
        return "/api/circles/{$circleId}/habits";
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['title' => 'Olahraga', 'scope' => 'personal', 'target_per_week' => 5], $overrides);
    }

    // ---------- membuat ----------

    public function test_habit_personal_baru_mulai_dihitung_senin_depan(): void
    {
        [$odi, , $circle] = $this->makePair();
        $this->at('2026-11-04 10:00'); // Rabu

        $this->callAs('POST', $this->habitsUrl($circle->id), $odi, $this->payload())
            ->assertCreated()
            ->assertJsonPath('habit.starts_on', '2026-11-09')
            ->assertJsonPath('habit.status', 'upcoming')
            ->assertJsonPath('habit.owner_id', $odi->id)
            ->assertJsonPath('habit.completion_rule', 'individual')
            ->assertJsonPath('habit.target_per_week', 5)
            ->assertJsonPath('habit.needs_my_approval', false);
    }

    public function test_habit_yang_dibuat_hari_senin_mulai_senin_minggu_depan_bukan_hari_itu(): void
    {
        [$odi, , $circle] = $this->makePair();
        $this->at('2026-11-02 09:00'); // Senin

        $this->callAs('POST', $this->habitsUrl($circle->id), $odi, $this->payload())
            ->assertCreated()
            ->assertJsonPath('habit.starts_on', '2026-11-09');
    }

    public function test_habit_yang_dibuat_minggu_malam_mulai_besok_senin(): void
    {
        [$odi, , $circle] = $this->makePair();
        $this->at('2026-11-08 23:30'); // Minggu malam

        $this->callAs('POST', $this->habitsUrl($circle->id), $odi, $this->payload())
            ->assertCreated()
            ->assertJsonPath('habit.starts_on', '2026-11-09');
    }

    public function test_senin_dini_hari_lokal_yang_masih_minggu_di_utc_dihitung_minggu_baru(): void
    {
        [$odi, , $circle] = $this->makePair();
        $this->at('2026-11-08 17:30', 'UTC'); // = Senin 2026-11-09 00:30 WIB

        $this->callAs('POST', $this->habitsUrl($circle->id), $odi, $this->payload())
            ->assertCreated()
            ->assertJsonPath('habit.starts_on', '2026-11-16');
    }

    public function test_habit_bersama_menunggu_persetujuan_partner(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $this->at('2026-11-04 10:00');

        $this->callAs('POST', $this->habitsUrl($circle->id), $odi, $this->payload(['title' => 'Jalan bareng', 'scope' => 'shared', 'target_per_week' => 3]))
            ->assertCreated()
            ->assertJsonPath('habit.status', 'pending_approval')
            ->assertJsonPath('habit.starts_on', null)
            ->assertJsonPath('habit.owner_id', null)
            ->assertJsonPath('habit.completion_rule', 'together')
            ->assertJsonPath('habit.needs_my_approval', false); // pembuatnya sendiri

        $this->callAs('GET', $this->habitsUrl($circle->id), $anjal)
            ->assertOk()
            ->assertJsonPath('habits.0.status', 'pending_approval')
            ->assertJsonPath('habits.0.needs_my_approval', true);
    }

    public function test_habit_bersama_butuh_partner_di_sesi(): void
    {
        $odi = $this->makeUser();
        $circle = $this->makeCircle($odi); // sendirian

        $this->callAs('POST', $this->habitsUrl($circle->id), $odi, $this->payload(['scope' => 'shared']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scope');
    }

    public function test_validasi_membuat_habit(): void
    {
        [$odi, , $circle] = $this->makePair();

        $this->callAs('POST', $this->habitsUrl($circle->id), $odi, $this->payload(['title' => '', 'target_per_week' => 0]))
            ->assertJsonValidationErrors(['title', 'target_per_week']);

        $this->callAs('POST', $this->habitsUrl($circle->id), $odi, $this->payload(['scope' => 'banget', 'target_per_week' => 8]))
            ->assertJsonValidationErrors(['scope', 'target_per_week']);
    }

    public function test_batas_jumlah_habit_per_sesi(): void
    {
        config(['habit.max_habits_per_circle' => 2]);
        [$odi, , $circle] = $this->makePair();

        $this->callAs('POST', $this->habitsUrl($circle->id), $odi, $this->payload())->assertCreated();
        $this->callAs('POST', $this->habitsUrl($circle->id), $odi, $this->payload())->assertCreated();
        $this->callAs('POST', $this->habitsUrl($circle->id), $odi, $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('circle');
    }

    public function test_bukan_anggota_dapat_404_untuk_semua_operasi_habit(): void
    {
        [$odi, , $circle] = $this->makePair();
        $penyusup = $this->makeUser();
        $habit = $this->makeHabit($circle, $odi);

        $this->callAs('POST', $this->habitsUrl($circle->id), $penyusup, $this->payload())->assertNotFound();
        $this->callAs('GET', $this->habitsUrl($circle->id), $penyusup)->assertNotFound();
        $this->callAs('PATCH', "/api/habits/{$habit->id}", $penyusup, ['title' => 'x'])->assertNotFound();
        $this->callAs('DELETE', "/api/habits/{$habit->id}", $penyusup)->assertNotFound();
        $this->callAs('POST', "/api/habits/{$habit->id}/accept", $penyusup)->assertNotFound();
    }

    // ---------- daftar ----------

    public function test_daftar_habit_memuat_tahap_yang_benar_dan_menyembunyikan_yang_berakhir(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $this->at('2026-11-10 10:00'); // Selasa

        $aktif = $this->makeHabit($circle, $odi);                                                           // mulai 11-09
        $akanDatang = $this->makeHabit($circle, $odi, ['starts_on' => '2026-11-16']);
        $diarsipkan = $this->makeHabit($circle, $odi, ['starts_on' => '2026-11-02', 'ends_on' => '2026-11-16']);
        $berakhir = $this->makeHabit($circle, $odi, ['starts_on' => '2026-11-02', 'ends_on' => '2026-11-09']);
        $menunggu = $this->makeHabit($circle, null, ['starts_on' => null, 'accepted_at' => null]);

        $response = $this->callAs('GET', $this->habitsUrl($circle->id), $anjal)
            ->assertOk()
            ->assertJsonPath('date', '2026-11-10')
            ->assertJsonCount(4, 'habits');

        $status = collect($response->json('habits'))->pluck('status', 'id');
        $this->assertSame('active', $status[$aktif->id]);
        $this->assertSame('upcoming', $status[$akanDatang->id]);
        $this->assertSame('ending', $status[$diarsipkan->id]);
        $this->assertSame('pending_approval', $status[$menunggu->id]);
        $this->assertFalse($status->has($berakhir->id)); // berakhir = tersembunyi
    }

    // ---------- persetujuan habit bersama ----------

    public function test_tanggal_mulai_habit_bersama_dihitung_dari_saat_disetujui_bukan_saat_dibuat(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();

        $this->at('2026-11-04 10:00'); // dibuat Rabu
        $id = $this->callAs('POST', $this->habitsUrl($circle->id), $odi, $this->payload(['scope' => 'shared']))
            ->assertCreated()->json('habit.id');

        $this->at('2026-11-10 09:00'); // disetujui Selasa minggu berikutnya
        $this->callAs('POST', "/api/habits/{$id}/accept", $anjal)
            ->assertOk()
            ->assertJsonPath('habit.starts_on', '2026-11-16') // bukan 11-09 yang sudah lewat
            ->assertJsonPath('habit.status', 'upcoming')
            ->assertJsonPath('habit.accepted', true)
            ->assertJsonPath('habit.needs_my_approval', false);
    }

    public function test_pembuat_tidak_bisa_menyetujui_sendiri_dan_setuju_dua_kali_tidak_menggeser_tanggal(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $this->at('2026-11-04 10:00');
        $id = $this->callAs('POST', $this->habitsUrl($circle->id), $odi, $this->payload(['scope' => 'shared']))
            ->assertCreated()->json('habit.id');

        $this->callAs('POST', "/api/habits/{$id}/accept", $odi)->assertForbidden();

        $this->callAs('POST', "/api/habits/{$id}/accept", $anjal)->assertOk()->assertJsonPath('habit.starts_on', '2026-11-09');

        $this->at('2026-11-12 10:00'); // tombol ditekan lagi hari Kamis
        $this->callAs('POST', "/api/habits/{$id}/accept", $anjal)
            ->assertOk()
            ->assertJsonPath('habit.starts_on', '2026-11-09'); // tidak bergeser ke 11-16
    }

    public function test_habit_personal_tidak_perlu_dan_tidak_bisa_disetujui(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, $odi);

        $this->callAs('POST', "/api/habits/{$habit->id}/accept", $anjal)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('habit');
    }

    public function test_menolak_dan_membatalkan_habit_bersama_menghapusnya(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $this->at('2026-11-04 10:00');

        $tolak = $this->callAs('POST', $this->habitsUrl($circle->id), $odi, $this->payload(['scope' => 'shared']))->json('habit.id');
        $this->callAs('DELETE', "/api/habits/{$tolak}", $anjal)->assertNoContent(); // partner menolak

        $batal = $this->callAs('POST', $this->habitsUrl($circle->id), $odi, $this->payload(['scope' => 'shared']))->json('habit.id');
        $this->callAs('DELETE', "/api/habits/{$batal}", $odi)->assertNoContent(); // pembuat membatalkan

        $this->assertDatabaseCount('habits', 0);
    }

    // ---------- hapus / arsip / pulihkan ----------

    public function test_menghapus_habit_yang_belum_mulai_menghapus_permanen(): void
    {
        [$odi, , $circle] = $this->makePair();
        $this->at('2026-11-04 10:00');
        $id = $this->callAs('POST', $this->habitsUrl($circle->id), $odi, $this->payload())->json('habit.id'); // mulai 11-09

        $this->callAs('DELETE', "/api/habits/{$id}", $odi)->assertNoContent();
        $this->assertDatabaseCount('habits', 0);
    }

    public function test_mengarsipkan_habit_aktif_tetap_dihitung_sampai_minggu_ini_selesai(): void
    {
        [$odi, , $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, $odi); // mulai 11-09
        $this->at('2026-11-10 10:00'); // Selasa

        $this->callAs('DELETE', "/api/habits/{$habit->id}", $odi)
            ->assertOk()
            ->assertJsonPath('habit.status', 'ending')
            ->assertJsonPath('habit.ends_on', '2026-11-16');

        $this->assertNotNull($habit->fresh()->archived_at);

        // Mengarsipkan lagi = tidak berubah (idempoten).
        $this->callAs('DELETE', "/api/habits/{$habit->id}", $odi)->assertOk()->assertJsonPath('habit.ends_on', '2026-11-16');

        // Selama minggu ini masih tampil.
        $this->callAs('GET', $this->habitsUrl($circle->id), $odi)->assertJsonCount(1, 'habits');

        // Senin 00:00 ia berakhir: tersembunyi dan tidak bisa diubah lagi.
        $this->at('2026-11-16 00:00');
        $this->callAs('GET', $this->habitsUrl($circle->id), $odi)->assertJsonCount(0, 'habits');
        $this->callAs('PATCH', "/api/habits/{$habit->id}", $odi, ['title' => 'x'])->assertNotFound();
    }

    public function test_arsip_bisa_dibatalkan_selama_masa_arsip(): void
    {
        [$odi, , $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, $odi);
        $this->at('2026-11-10 10:00');

        $this->callAs('DELETE', "/api/habits/{$habit->id}", $odi)->assertOk();

        $this->callAs('POST', "/api/habits/{$habit->id}/restore", $odi)
            ->assertOk()
            ->assertJsonPath('habit.status', 'active')
            ->assertJsonPath('habit.ends_on', null);

        // Yang tidak sedang diarsipkan tidak bisa "dipulihkan".
        $this->callAs('POST', "/api/habits/{$habit->id}/restore", $odi)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('habit');
    }

    // ---------- ubah ----------

    public function test_ubah_judul_berlaku_langsung(): void
    {
        [$odi, , $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, $odi);
        $this->at('2026-11-10 10:00');

        $this->callAs('PATCH', "/api/habits/{$habit->id}", $odi, ['title' => 'Lari pagi'])
            ->assertOk()
            ->assertJsonPath('habit.title', 'Lari pagi');
    }

    public function test_ubah_target_habit_aktif_ditahan_sampai_senin_depan(): void
    {
        [$odi, , $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, $odi, ['target_per_week' => 3]);
        $this->at('2026-11-10 10:00');

        $this->callAs('PATCH', "/api/habits/{$habit->id}", $odi, ['target_per_week' => 5])
            ->assertOk()
            ->assertJsonPath('habit.target_per_week', 3)          // minggu ini tetap 3
            ->assertJsonPath('habit.next_target.target', 5)
            ->assertJsonPath('habit.next_target.from', '2026-11-16');

        $this->assertDatabaseHas('habits', [
            'id' => $habit->id, 'target_per_week' => 3, 'pending_target_per_week' => 5, 'pending_target_from' => '2026-11-16',
        ]);

        // Berubah pikiran lagi: perubahan yang tertahan ditimpa.
        $this->callAs('PATCH', "/api/habits/{$habit->id}", $odi, ['target_per_week' => 6])
            ->assertJsonPath('habit.next_target.target', 6);

        // Kembali ke target berjalan = membatalkan perubahan tertahan.
        $this->callAs('PATCH', "/api/habits/{$habit->id}", $odi, ['target_per_week' => 3])
            ->assertOk()
            ->assertJsonPath('habit.next_target', null);
        $this->assertDatabaseHas('habits', ['id' => $habit->id, 'pending_target_per_week' => null, 'pending_target_from' => null]);
    }

    public function test_ubah_target_yang_sudah_jatuh_tempo_menjadi_target_berjalan_lebih_dulu(): void
    {
        [$odi, , $circle] = $this->makePair();
        // Perubahan tertahan (jadi 5) yang berlaku sejak Senin 11-09, tetapi belum dipindahkan job.
        $habit = $this->makeHabit($circle, $odi, [
            'target_per_week' => 3, 'pending_target_per_week' => 5, 'pending_target_from' => '2026-11-09',
        ]);
        $this->at('2026-11-10 10:00');
        // Minggu lalu (11-02) sudah dievaluasi, jadi perubahan yang jatuh tempo boleh dipindahkan.
        $circle->forceFill(['evaluated_through' => '2026-11-02'])->save();

        // Target berjalan sebenarnya sudah 5. Mengirim 5 tidak boleh "mengembalikan" ke 3.
        $this->callAs('PATCH', "/api/habits/{$habit->id}", $odi, ['target_per_week' => 5])
            ->assertOk()
            ->assertJsonPath('habit.target_per_week', 5)
            ->assertJsonPath('habit.next_target', null);
        $this->assertDatabaseHas('habits', ['id' => $habit->id, 'target_per_week' => 5, 'pending_target_per_week' => null]);
    }

    public function test_edit_target_ditolak_selama_evaluasi_minggu_lalu_belum_selesai(): void
    {
        [$odi, , $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, $odi, [
            'target_per_week' => 3, 'pending_target_per_week' => 5, 'pending_target_from' => '2026-11-09',
        ]);
        $this->at('2026-11-09 00:05'); // Senin dini hari: minggu 11-02 belum dievaluasi

        // Memindahkan target 5 sekarang akan membuat minggu lalu dinilai dengan target 5.
        $this->callAs('PATCH', "/api/habits/{$habit->id}", $odi, ['target_per_week' => 4])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('target_per_week');

        $this->assertDatabaseHas('habits', [
            'id' => $habit->id, 'target_per_week' => 3, 'pending_target_per_week' => 5, 'pending_target_from' => '2026-11-09',
        ]);

        // Ganti judul tidak menyentuh target, jadi tetap boleh.
        $this->callAs('PATCH', "/api/habits/{$habit->id}", $odi, ['title' => 'Judul baru'])->assertOk();
        $this->assertDatabaseHas('habits', ['id' => $habit->id, 'target_per_week' => 3, 'pending_target_per_week' => 5]);
    }

    public function test_ubah_target_habit_yang_belum_mulai_berlaku_langsung(): void
    {
        [$odi, , $circle] = $this->makePair();
        $this->at('2026-11-04 10:00');
        $id = $this->callAs('POST', $this->habitsUrl($circle->id), $odi, $this->payload(['target_per_week' => 3]))->json('habit.id');

        $this->callAs('PATCH', "/api/habits/{$id}", $odi, ['target_per_week' => 6])
            ->assertOk()
            ->assertJsonPath('habit.target_per_week', 6)
            ->assertJsonPath('habit.next_target', null);
    }

    public function test_partner_boleh_melihat_tetapi_tidak_boleh_mengubah_habit_personal(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, $odi);
        $this->at('2026-11-10 10:00');

        $this->callAs('GET', $this->habitsUrl($circle->id), $anjal)->assertOk()->assertJsonCount(1, 'habits');
        $this->callAs('PATCH', "/api/habits/{$habit->id}", $anjal, ['title' => 'x'])->assertForbidden();
        $this->callAs('DELETE', "/api/habits/{$habit->id}", $anjal)->assertForbidden();
    }

    public function test_habit_bersama_boleh_diubah_kedua_anggota(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, null);
        $this->at('2026-11-10 10:00');

        $this->callAs('PATCH', "/api/habits/{$habit->id}", $anjal, ['title' => 'Lari bareng'])
            ->assertOk()
            ->assertJsonPath('habit.title', 'Lari bareng');
    }

    public function test_patch_tanpa_isi_ditolak(): void
    {
        [$odi, , $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, $odi);

        $this->callAs('PATCH', "/api/habits/{$habit->id}", $odi, [])
            ->assertJsonValidationErrors(['title', 'target_per_week']);
    }
}
