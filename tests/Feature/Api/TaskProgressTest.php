<?php

namespace Tests\Feature\Api;

use App\Models\Circle;
use App\Models\HabitLog;
use App\Models\HabitTaskEntry;
use App\Models\User;
use Tests\ApiTestCase;

// Progres harian tugas, check-in otomatis, catatan, papan, dan riwayat.
// Kalender acuan (WIB): Senin 2026-11-02, 11-09, 11-16; Selasa 11-03, 11-10; Rabu 11-11; Kamis 11-05.
// Habit bawaan (makeChecklist): milik Odi, mulai 11-09, target 2 hari/minggu, tugas
// push up 50 kali, pull up 20 kali, lari 3000 meter.
class TaskProgressTest extends ApiTestCase
{
    private function entries(int $taskId): string
    {
        return "/api/tasks/{$taskId}/entries";
    }

    private function progress(int $taskId): string
    {
        return "/api/tasks/{$taskId}/progress";
    }

    /** @return array{0: User, 1: User, 2: Circle, 3: \App\Models\Habit, 4: array<string, \App\Models\HabitTask>} */
    private function hariSelasa(): array
    {
        [$odi, $anjal, $circle] = $this->makePair();
        [$habit, $t] = $this->makeChecklist($circle, $odi);
        $this->at('2026-11-10 08:00'); // Selasa

        return [$odi, $anjal, $circle, $habit, $t];
    }

    private function boardHabit(User $user, Circle $circle, int $habitId): array
    {
        $habits = $this->callAs('GET', "/api/circles/{$circle->id}/today", $user)->assertOk()->json('habits');

        return collect($habits)->firstWhere('id', $habitId);
    }

    private function logCount(int $habitId, ?string $date = null): int
    {
        $query = HabitLog::where('habit_id', $habitId);

        return $date === null ? $query->count() : $query->where('log_date', $date)->count();
    }

    // ---------- ceklis dan check-in otomatis ----------

    public function test_ceklis_satu_per_satu_dan_check_in_tercatat_otomatis_saat_tugas_terakhir(): void
    {
        [$odi, , , $habit, $t] = $this->hariSelasa();

        $this->callAs('POST', $this->entries($t['push up']->id), $odi, ['complete' => true])
            ->assertCreated()
            ->assertJsonPath('created', true)
            ->assertJsonPath('day.habit_id', $habit->id)
            ->assertJsonPath('day.log_date', '2026-11-10')
            ->assertJsonPath('day.complete', false)
            ->assertJsonPath('day.tasks.0.name', 'Push up')
            ->assertJsonPath('day.tasks.0.total', 50)       // ceklis = sebesar target
            ->assertJsonPath('day.tasks.0.done', true)
            ->assertJsonPath('day.tasks.0.has_manual', false)
            ->assertJsonPath('day.tasks.1.done', false)
            ->assertJsonPath('day.progress.done', 0);
        $this->assertSame(0, $this->logCount($habit->id));

        $this->callAs('POST', $this->entries($t['pull up']->id), $odi, ['complete' => true])
            ->assertCreated()->assertJsonPath('day.complete', false);
        $this->assertSame(0, $this->logCount($habit->id));

        // Tugas terakhir: check-in hari ini tercatat sendiri, tanpa tombol check-in.
        $this->callAs('POST', $this->entries($t['lari']->id), $odi, ['complete' => true])
            ->assertCreated()
            ->assertJsonPath('day.complete', true)
            ->assertJsonPath('day.tasks.2.total', 3000)
            ->assertJsonPath('day.progress.done', 1)
            ->assertJsonPath('day.progress.target', 2)
            ->assertJsonPath('day.progress.achieved', false);

        $this->assertDatabaseHas('habit_logs', ['habit_id' => $habit->id, 'user_id' => $odi->id, 'log_date' => '2026-11-10']);
        $this->assertSame(3, HabitTaskEntry::where('habit_id', $habit->id)->where('source', 'tap')->count());
    }

    public function test_ceklis_ulang_tidak_menggandakan_angka(): void
    {
        [$odi, , , , $t] = $this->hariSelasa();

        $this->callAs('POST', $this->entries($t['push up']->id), $odi, ['complete' => true])->assertCreated();

        $this->callAs('POST', $this->entries($t['push up']->id), $odi, ['complete' => true])
            ->assertOk() // bukan 201: tidak ada yang baru
            ->assertJsonPath('created', false)
            ->assertJsonPath('day.tasks.0.total', 50);

        $this->assertSame(1, HabitTaskEntry::where('task_id', $t['push up']->id)->count());
    }

    public function test_angka_manual_menumpuk_dicicil_dan_boleh_melebihi_target(): void
    {
        [$odi, , , , $t] = $this->hariSelasa();

        $this->callAs('POST', $this->entries($t['push up']->id), $odi, ['amount' => 20])
            ->assertCreated()
            ->assertJsonPath('day.tasks.0.total', 20)
            ->assertJsonPath('day.tasks.0.done', false)
            ->assertJsonPath('day.tasks.0.has_manual', true);

        $this->callAs('POST', $this->entries($t['push up']->id), $odi, ['amount' => 20])
            ->assertCreated()->assertJsonPath('day.tasks.0.total', 40)->assertJsonPath('day.tasks.0.done', false);

        $this->callAs('POST', $this->entries($t['push up']->id), $odi, ['amount' => 30])
            ->assertCreated()->assertJsonPath('day.tasks.0.total', 70)->assertJsonPath('day.tasks.0.done', true);

        $this->assertSame(3, HabitTaskEntry::where('task_id', $t['push up']->id)->count());
    }

    public function test_ceklis_setelah_dicicil_hanya_menambah_sisa_target(): void
    {
        [$odi, , , , $t] = $this->hariSelasa();

        $this->callAs('POST', $this->entries($t['push up']->id), $odi, ['amount' => 20])->assertCreated();
        $this->callAs('POST', $this->entries($t['push up']->id), $odi, ['complete' => true])
            ->assertCreated()->assertJsonPath('day.tasks.0.total', 50)->assertJsonPath('day.tasks.0.done', true);

        $rows = HabitTaskEntry::where('task_id', $t['push up']->id)->orderBy('id')->get();
        $this->assertSame([20, 30], $rows->pluck('amount')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(['manual', 'tap'], $rows->pluck('source')->all());
    }

    // ---------- koreksi: ubah angka dan batal ----------

    public function test_check_in_otomatis_hilang_saat_total_diturunkan_di_bawah_target_dan_kembali_saat_dinaikkan(): void
    {
        [$odi, , , $habit, $t] = $this->hariSelasa();

        foreach (['push up', 'pull up', 'lari'] as $nama) {
            $this->callAs('POST', $this->entries($t[$nama]->id), $odi, ['complete' => true])->assertCreated();
        }
        $this->assertSame(1, $this->logCount($habit->id));

        // "Ubah angka": total diganti persis (bukan ditambah).
        $this->callAs('PUT', $this->progress($t['push up']->id), $odi, ['amount' => 10])
            ->assertOk()
            ->assertJsonPath('day.complete', false)
            ->assertJsonPath('day.tasks.0.total', 10)
            ->assertJsonPath('day.tasks.0.done', false)
            ->assertJsonPath('day.tasks.0.has_manual', true)
            ->assertJsonPath('day.progress.done', 0);
        $this->assertSame(0, $this->logCount($habit->id));
        $this->assertSame(1, HabitTaskEntry::where('task_id', $t['push up']->id)->count());

        $this->callAs('PUT', $this->progress($t['push up']->id), $odi, ['amount' => 60])
            ->assertOk()->assertJsonPath('day.complete', true)->assertJsonPath('day.tasks.0.total', 60);
        $this->assertSame(1, $this->logCount($habit->id));
    }

    public function test_hapus_progres_mengosongkan_tugas_dan_membatalkan_check_in(): void
    {
        [$odi, , , $habit, $t] = $this->hariSelasa();

        foreach (['push up', 'pull up', 'lari'] as $nama) {
            $this->callAs('POST', $this->entries($t[$nama]->id), $odi, ['complete' => true])->assertCreated();
        }

        $this->callAs('DELETE', $this->progress($t['lari']->id), $odi)
            ->assertOk()
            ->assertJsonPath('day.complete', false)
            ->assertJsonPath('day.tasks.2.total', 0)
            ->assertJsonPath('day.tasks.2.done', false);

        $this->assertSame(0, $this->logCount($habit->id));
        $this->assertSame(0, HabitTaskEntry::where('task_id', $t['lari']->id)->count());
        $this->assertSame(1, HabitTaskEntry::where('task_id', $t['push up']->id)->count()); // tugas lain tidak tersentuh

        // Mengosongkan yang memang kosong tidak error.
        $this->callAs('DELETE', $this->progress($t['lari']->id), $odi)->assertOk();
    }

    // ---------- beban, batas, idempotensi ----------

    public function test_beban_hanya_untuk_tugas_jumlah(): void
    {
        [$odi, , , , $t] = $this->hariSelasa();

        $this->callAs('POST', $this->entries($t['push up']->id), $odi, ['amount' => 10, 'weight_g' => 20000])
            ->assertCreated()->assertJsonPath('day.tasks.0.max_weight_g', 20000);

        $this->callAs('PUT', $this->progress($t['push up']->id), $odi, ['amount' => 12, 'weight_g' => 22500])
            ->assertOk()->assertJsonPath('day.tasks.0.max_weight_g', 22500);

        // Lari (meter) tidak punya beban.
        $this->callAs('POST', $this->entries($t['lari']->id), $odi, ['amount' => 1000, 'weight_g' => 5000])
            ->assertStatus(422)->assertJsonValidationErrors('weight_g');
        $this->assertSame(0, HabitTaskEntry::where('task_id', $t['lari']->id)->count());

        // Beban nol atau di atas batas ditolak.
        $this->callAs('POST', $this->entries($t['push up']->id), $odi, ['amount' => 5, 'weight_g' => 0])
            ->assertStatus(422)->assertJsonValidationErrors('weight_g');
        $this->callAs('POST', $this->entries($t['push up']->id), $odi, ['amount' => 5, 'weight_g' => 1000001])
            ->assertStatus(422)->assertJsonValidationErrors('weight_g');
    }

    public function test_batas_angka_bentuk_permintaan_dan_jumlah_entri_per_hari(): void
    {
        [$odi, , , , $t] = $this->hariSelasa();

        $this->callAs('POST', $this->entries($t['push up']->id), $odi, ['amount' => 10001])
            ->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->callAs('POST', $this->entries($t['lari']->id), $odi, ['amount' => 1000001])
            ->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->callAs('PUT', $this->progress($t['push up']->id), $odi, ['amount' => 10001])
            ->assertStatus(422)->assertJsonValidationErrors('amount');

        // Nol/negatif/pecahan bukan "tambah"; kosongkan lewat PUT 0 atau DELETE.
        $this->callAs('POST', $this->entries($t['push up']->id), $odi, ['amount' => 0])->assertStatus(422);
        $this->callAs('POST', $this->entries($t['push up']->id), $odi, ['amount' => -5])->assertStatus(422);
        $this->callAs('POST', $this->entries($t['push up']->id), $odi, ['amount' => 2.5])->assertStatus(422);
        $this->callAs('PUT', $this->progress($t['push up']->id), $odi, ['amount' => -1])->assertStatus(422);

        // Harus tepat salah satu dari amount / complete.
        $this->callAs('POST', $this->entries($t['push up']->id), $odi, [])->assertStatus(422);
        $this->callAs('POST', $this->entries($t['push up']->id), $odi, ['amount' => 5, 'complete' => true])->assertStatus(422);
        $this->callAs('POST', $this->entries($t['push up']->id), $odi, ['complete' => false])->assertStatus(422);

        // Batas banyaknya entri per tugas per hari (diperkecil khusus tes).
        config(['habit.task.max_entries_per_task_per_day' => 3]);
        for ($i = 0; $i < 3; $i++) {
            $this->callAs('POST', $this->entries($t['push up']->id), $odi, ['amount' => 1])->assertCreated();
        }
        $this->callAs('POST', $this->entries($t['push up']->id), $odi, ['amount' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('task');
        $this->assertSame(3, HabitTaskEntry::where('task_id', $t['push up']->id)->count());

        // "Ubah angka" tetap bisa dipakai untuk merapikan.
        $this->callAs('PUT', $this->progress($t['push up']->id), $odi, ['amount' => 50])
            ->assertOk()->assertJsonPath('day.tasks.0.total', 50);
    }

    public function test_client_key_yang_sama_tidak_menggandakan_angka(): void
    {
        [$odi, , , , $t] = $this->hariSelasa();

        $this->callAs('POST', $this->entries($t['push up']->id), $odi, ['amount' => 10, 'client_key' => 'tap-1'])
            ->assertCreated()->assertJsonPath('day.tasks.0.total', 10);

        // Kirim ulang (mis. jaringan putus setelah server sudah menyimpan): tidak dobel.
        $this->callAs('POST', $this->entries($t['push up']->id), $odi, ['amount' => 10, 'client_key' => 'tap-1'])
            ->assertOk()->assertJsonPath('created', false)->assertJsonPath('day.tasks.0.total', 10);

        $this->callAs('POST', $this->entries($t['push up']->id), $odi, ['amount' => 10, 'client_key' => 'tap-2'])
            ->assertCreated()->assertJsonPath('day.tasks.0.total', 20);

        $this->callAs('POST', $this->entries($t['push up']->id), $odi, ['amount' => 10, 'client_key' => 'tidak boleh spasi!'])
            ->assertStatus(422)->assertJsonValidationErrors('client_key');
    }

    // ---------- kontrak check-in lama ----------

    public function test_habit_checklist_menolak_check_in_langsung(): void
    {
        [$odi, , , $habit] = $this->hariSelasa();

        $this->callAs('POST', "/api/habits/{$habit->id}/check-in", $odi)
            ->assertStatus(422)->assertJsonValidationErrors('habit');
        $this->callAs('DELETE', "/api/habits/{$habit->id}/check-in", $odi)
            ->assertStatus(422)->assertJsonValidationErrors('habit');

        $this->assertSame(0, $this->logCount($habit->id));
    }

    public function test_habit_simpel_tetap_check_in_satu_tap_dan_tidak_punya_catatan_atau_riwayat(): void
    {
        [$odi, , $circle] = $this->makePair();
        $simpel = $this->makeHabit($circle, $odi);
        $this->at('2026-11-10 08:00');

        $this->callAs('POST', "/api/habits/{$simpel->id}/check-in", $odi)->assertCreated();

        $this->callAs('PUT', "/api/habits/{$simpel->id}/note", $odi, ['note' => 'halo'])
            ->assertStatus(422)->assertJsonValidationErrors('habit');
        $this->callAs('GET', "/api/habits/{$simpel->id}/history", $odi)
            ->assertStatus(422)->assertJsonValidationErrors('habit');
    }

    // ---------- hak akses ----------

    public function test_hanya_pemilik_tugas_yang_boleh_menulis(): void
    {
        [$odi, $anjal, , $habit, $t] = $this->hariSelasa();
        $id = $t['push up']->id;

        // Partner hanya memantau.
        $this->callAs('POST', $this->entries($id), $anjal, ['complete' => true])->assertForbidden();
        $this->callAs('PUT', $this->progress($id), $anjal, ['amount' => 5])->assertForbidden();
        $this->callAs('DELETE', $this->progress($id), $anjal)->assertForbidden();

        // Orang di luar sesi: 404 (keberadaan tugas tidak bocor).
        $orangLuar = $this->makeUser();
        $this->callAs('POST', $this->entries($id), $orangLuar, ['complete' => true])->assertNotFound();
        $this->callAs('POST', $this->entries(999999), $odi, ['complete' => true])->assertNotFound();

        $this->callAs('POST', $this->entries($id), null, ['complete' => true])->assertUnauthorized();

        $this->assertSame(0, HabitTaskEntry::where('habit_id', $habit->id)->count());
    }

    // ---------- tugas yang tidak berlaku, habit yang belum jalan ----------

    public function test_tugas_yang_belum_atau_tidak_lagi_berlaku_ditolak_dan_tidak_menghalangi_check_in(): void
    {
        [$odi, , , $habit, $t] = $this->hariSelasa();

        // Plank baru berlaku Senin depan; Burpee berakhir Senin lalu.
        $masaDepan = $this->makeTasks($habit, $odi, [['Plank', 'minutes', 5]], ['valid_from' => '2026-11-16']);
        $usang = $this->makeTasks($habit, $odi, [['Burpee', 'count', 10]], ['valid_until' => '2026-11-09']);

        $this->callAs('POST', $this->entries($masaDepan['plank']->id), $odi, ['complete' => true])
            ->assertStatus(422)->assertJsonValidationErrors('task');
        $this->callAs('POST', $this->entries($usang['burpee']->id), $odi, ['complete' => true])
            ->assertStatus(422)->assertJsonValidationErrors('task');

        // Tugas yang tidak berlaku hari ini tidak ikut menentukan "lengkap".
        foreach (['push up', 'pull up', 'lari'] as $nama) {
            $response = $this->callAs('POST', $this->entries($t[$nama]->id), $odi, ['complete' => true])->assertCreated();
        }

        $response->assertJsonPath('day.complete', true)->assertJsonCount(3, 'day.tasks');
        $this->assertSame(1, $this->logCount($habit->id));
    }

    public function test_habit_yang_belum_mulai_atau_menunggu_persetujuan_tidak_bisa_dicatat(): void
    {
        [$odi, , $circle] = $this->makePair();
        [, $akanDatang] = $this->makeChecklist($circle, $odi, null, ['starts_on' => '2026-11-16']);
        $menunggu = $this->makeHabit($circle, null, ['mode' => 'checklist', 'starts_on' => null, 'accepted_at' => null]);
        $tugasMenunggu = $this->makeTasks($menunggu, $odi, [['Push up', 'count', 50]]);
        $this->at('2026-11-10 08:00');

        $this->callAs('POST', $this->entries($akanDatang['push up']->id), $odi, ['complete' => true])
            ->assertStatus(422)->assertJsonValidationErrors('habit');
        $this->callAs('POST', $this->entries($tugasMenunggu['push up']->id), $odi, ['complete' => true])
            ->assertStatus(422)->assertJsonValidationErrors('habit');

        $this->assertSame(0, HabitTaskEntry::count());
    }

    // ---------- pergantian hari ----------

    public function test_hari_kemarin_terkunci_setelah_pergantian_hari(): void
    {
        [$odi, , , $habit, $t] = $this->hariSelasa();

        $this->at('2026-11-10 23:59:30');
        foreach (['push up', 'pull up', 'lari'] as $nama) {
            $this->callAs('POST', $this->entries($t[$nama]->id), $odi, ['complete' => true])->assertCreated();
        }
        $this->assertSame(1, $this->logCount($habit->id, '2026-11-10'));

        // 30 detik kemudian sudah Rabu: "ubah/hapus" hanya menyentuh Rabu, hari Selasa tidak bisa diubah lagi.
        $this->at('2026-11-11 00:00:30');

        $this->callAs('PUT', $this->progress($t['push up']->id), $odi, ['amount' => 0])
            ->assertOk()->assertJsonPath('day.log_date', '2026-11-11')->assertJsonPath('day.complete', false);
        $this->callAs('DELETE', $this->progress($t['lari']->id), $odi)->assertOk();

        $this->assertSame(3, HabitTaskEntry::where('habit_id', $habit->id)->where('log_date', '2026-11-10')->count());
        $this->assertSame(1, $this->logCount($habit->id, '2026-11-10'));
        $this->assertSame(0, $this->logCount($habit->id, '2026-11-11'));

        // Progres hari Rabu mulai dari nol.
        $this->callAs('POST', $this->entries($t['push up']->id), $odi, ['complete' => true])
            ->assertCreated()->assertJsonPath('day.log_date', '2026-11-11')->assertJsonPath('day.tasks.0.total', 50);
        $this->assertSame(1, HabitTaskEntry::where('task_id', $t['push up']->id)->where('log_date', '2026-11-10')->count());
    }

    public function test_antrean_offline_di_pergantian_hari_masuk_ke_hari_yang_benar(): void
    {
        [$odi, , , $habit, $t] = $this->hariSelasa();
        $this->at('2026-11-11 00:05'); // server sudah Rabu; HP menekan Selasa 23:58 (7 menit lalu)

        foreach (['push up', 'pull up', 'lari'] as $nama) {
            $response = $this->callAs('POST', $this->entries($t[$nama]->id), $odi, [
                'complete' => true, 'client_ts' => '2026-11-10T23:58:00+07:00',
            ])->assertCreated()->assertJsonPath('day.log_date', '2026-11-10');
        }

        $response->assertJsonPath('day.complete', true);
        $this->assertSame(1, $this->logCount($habit->id, '2026-11-10'));
        $this->assertSame(0, $this->logCount($habit->id, '2026-11-11'));

        // client_ts terlalu lama diabaikan: memakai tanggal server.
        $this->callAs('POST', $this->entries($t['push up']->id), $odi, ['amount' => 1, 'client_ts' => '2026-11-10T20:00:00+07:00'])
            ->assertCreated()->assertJsonPath('day.log_date', '2026-11-11');
    }

    // ---------- habit bersama ----------

    public function test_habit_bersama_dihitung_kalau_keduanya_lengkap_dengan_tugas_masing_masing(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, null, ['mode' => 'checklist', 'target_per_week' => 1]);
        $tOdi = $this->makeTasks($habit, $odi, [['Push up', 'count', 50]]);
        $tAnjal = $this->makeTasks($habit, $anjal, [['Jalan', 'steps', 8000]]);
        $this->at('2026-11-10 08:00');

        // Odi lengkap sendirian: aturan "together" belum menghitung hari ini.
        $this->callAs('POST', $this->entries($tOdi['push up']->id), $odi, ['complete' => true])
            ->assertCreated()->assertJsonPath('day.complete', true)->assertJsonPath('day.progress.done', 0);

        $this->callAs('POST', $this->entries($tAnjal['jalan']->id), $anjal, ['amount' => 8000])
            ->assertCreated()->assertJsonPath('day.complete', true)
            ->assertJsonPath('day.progress.done', 1)->assertJsonPath('day.progress.achieved', true);

        // Papan: kedua anggota terlihat, masing-masing dengan daftar tugasnya sendiri.
        $papan = $this->boardHabit($odi, $circle, $habit->id);
        $this->assertSame('checklist', $papan['mode']);
        $this->assertCount(2, $papan['detail']);

        $detailAnjal = collect($papan['detail'])->firstWhere('user_id', $anjal->id);
        $this->assertTrue($detailAnjal['complete']);
        $this->assertSame('Jalan', $detailAnjal['tasks'][0]['name']);
        $this->assertSame('steps', $detailAnjal['tasks'][0]['metric']);
        $this->assertSame(8000, $detailAnjal['tasks'][0]['total']);
        $this->assertTrue($detailAnjal['tasks'][0]['has_manual']);

        $detailOdi = collect($papan['detail'])->firstWhere('user_id', $odi->id);
        $this->assertSame('Push up', $detailOdi['tasks'][0]['name']);
        $this->assertFalse($detailOdi['tasks'][0]['has_manual']);
        $this->assertEqualsCanonicalizing([$odi->id, $anjal->id], $papan['done_today_by']);
    }

    // ---------- papan ----------

    public function test_papan_memuat_detail_hanya_untuk_habit_checklist_dan_partner_bisa_memantau(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $simpel = $this->makeHabit($circle, $odi, ['title' => 'Tidur cepat']);
        [$olahraga, $t] = $this->makeChecklist($circle, $odi);
        [$yoga] = $this->makeChecklist($circle, $anjal, [['Yoga', 'minutes', 20]]);
        $this->at('2026-11-10 08:00');

        $this->callAs('POST', $this->entries($t['push up']->id), $odi, ['amount' => 30])->assertCreated();

        $papan = $this->boardHabit($odi, $circle, $simpel->id);
        $this->assertSame('simple', $papan['mode']);
        $this->assertNull($papan['detail']);

        // Anjal melihat progres setengah jalan Odi: 30 dari 50 push up, belum lengkap.
        $dariAnjal = $this->boardHabit($anjal, $circle, $olahraga->id);
        $this->assertSame('checklist', $dariAnjal['mode']);
        $this->assertFalse($dariAnjal['can_check_in']);
        $this->assertCount(1, $dariAnjal['detail']);
        $this->assertSame($odi->id, $dariAnjal['detail'][0]['user_id']);
        $this->assertFalse($dariAnjal['detail'][0]['complete']);
        $this->assertSame(30, $dariAnjal['detail'][0]['tasks'][0]['total']);
        $this->assertFalse($dariAnjal['detail'][0]['tasks'][0]['done']);
        $this->assertSame(0, $dariAnjal['detail'][0]['tasks'][2]['total']);

        // Habit personal Anjal terlihat oleh Odi dengan daftar tugas Anjal saja.
        $dariOdi = $this->boardHabit($odi, $circle, $yoga->id);
        $this->assertCount(1, $dariOdi['detail']);
        $this->assertSame($anjal->id, $dariOdi['detail'][0]['user_id']);
        $this->assertSame('Yoga', $dariOdi['detail'][0]['tasks'][0]['name']);
    }

    // ---------- catatan harian ----------

    public function test_catatan_harian_disimpan_diganti_dan_dihapus(): void
    {
        [$odi, $anjal, $circle, $habit] = $this->hariSelasa();
        $url = "/api/habits/{$habit->id}/note";

        $this->callAs('PUT', $url, $odi, ['note' => '  Lagi capek tapi tetap jalan  '])
            ->assertOk()->assertJsonPath('day.note', 'Lagi capek tapi tetap jalan')->assertJsonPath('day.log_date', '2026-11-10');

        // Anjal ikut melihat di papan.
        $this->assertSame('Lagi capek tapi tetap jalan', $this->boardHabit($anjal, $circle, $habit->id)['detail'][0]['note']);

        $this->callAs('PUT', $url, $odi, ['note' => 'Revisi'])->assertOk()->assertJsonPath('day.note', 'Revisi');
        $this->assertDatabaseCount('habit_day_notes', 1);

        $this->callAs('PUT', $url, $odi, ['note' => null])->assertOk()->assertJsonPath('day.note', null);
        $this->assertDatabaseCount('habit_day_notes', 0);
    }

    public function test_catatan_maksimal_500_karakter_dan_kuncinya_wajib_ada(): void
    {
        [$odi, $anjal, , $habit] = $this->hariSelasa();
        $url = "/api/habits/{$habit->id}/note";

        $this->callAs('PUT', $url, $odi, ['note' => str_repeat('a', 500)])->assertOk();
        $this->callAs('PUT', $url, $odi, ['note' => str_repeat('a', 501)])
            ->assertStatus(422)->assertJsonValidationErrors('note');
        $this->callAs('PUT', $url, $odi, [])->assertStatus(422)->assertJsonValidationErrors('note');

        // Karakter multibita dihitung sebagai karakter, bukan byte.
        $this->callAs('PUT', $url, $odi, ['note' => str_repeat('é', 500)])->assertOk();

        // Partner tidak boleh menulis di habit personal orang lain.
        $this->callAs('PUT', $url, $anjal, ['note' => 'iseng'])->assertForbidden();
    }

    // ---------- riwayat ----------

    public function test_riwayat_per_hari_terbaru_dulu_dengan_target_pada_saat_itu(): void
    {
        [$odi, $anjal, $circle, $habit, $t] = $this->hariSelasa();

        // Selasa: push up lengkap, pull up baru 10 dari 20 (hari tidak lengkap).
        $this->callAs('POST', $this->entries($t['push up']->id), $odi, ['complete' => true])->assertCreated();
        $this->callAs('POST', $this->entries($t['pull up']->id), $odi, ['amount' => 10])->assertCreated();

        // Rabu: semuanya lengkap, plus catatan.
        $this->at('2026-11-11 08:00');
        foreach (['push up', 'pull up', 'lari'] as $nama) {
            $this->callAs('POST', $this->entries($t[$nama]->id), $odi, ['complete' => true])->assertCreated();
        }
        $this->callAs('PUT', "/api/habits/{$habit->id}/note", $odi, ['note' => 'PR lari'])->assertOk();

        $url = "/api/habits/{$habit->id}/history";
        $this->callAs('GET', $url, $anjal) // partner boleh memantau
            ->assertOk()
            ->assertJsonPath('habit_id', $habit->id)
            ->assertJsonCount(2, 'days')
            ->assertJsonPath('next_before', null)
            ->assertJsonPath('days.0.date', '2026-11-11')
            ->assertJsonPath('days.0.members.0.user_id', $odi->id)
            ->assertJsonPath('days.0.members.0.complete', true)
            ->assertJsonPath('days.0.members.0.note', 'PR lari')
            ->assertJsonCount(3, 'days.0.members.0.tasks')
            ->assertJsonPath('days.1.date', '2026-11-10')
            ->assertJsonPath('days.1.members.0.complete', false)
            ->assertJsonCount(2, 'days.1.members.0.tasks') // hanya yang dikerjakan
            ->assertJsonPath('days.1.members.0.tasks.0.name', 'Push up')
            ->assertJsonPath('days.1.members.0.tasks.0.done', true)
            ->assertJsonPath('days.1.members.0.tasks.1.name', 'Pull up')
            ->assertJsonPath('days.1.members.0.tasks.1.total', 10)
            ->assertJsonPath('days.1.members.0.tasks.1.done', false)
            ->assertJsonPath('days.1.members.0.tasks.1.has_manual', true);

        // Halaman dengan kursor: satu hari per halaman.
        $this->callAs('GET', "{$url}?limit=1", $odi)
            ->assertOk()->assertJsonCount(1, 'days')->assertJsonPath('days.0.date', '2026-11-11')->assertJsonPath('next_before', '2026-11-11');

        $this->callAs('GET', "{$url}?limit=1&before=2026-11-11", $odi)
            ->assertOk()->assertJsonCount(1, 'days')->assertJsonPath('days.0.date', '2026-11-10')->assertJsonPath('next_before', '2026-11-10');

        $this->callAs('GET', "{$url}?limit=1&before=2026-11-10", $odi)
            ->assertOk()->assertJsonCount(0, 'days')->assertJsonPath('next_before', null);
    }

    public function test_riwayat_menampilkan_target_versi_yang_berlaku_saat_itu(): void
    {
        [$odi, , , $habit, $t] = $this->hariSelasa();

        $this->callAs('POST', $this->entries($t['push up']->id), $odi, ['complete' => true])->assertCreated(); // 50 pada 11-10

        // Senin 11-16 push up naik ke 60 (versi baru, sama seperti hasil jadwal perubahan).
        $t['push up']->update(['valid_until' => '2026-11-16']);
        $baru = $this->makeTasks($habit, $odi, [['Push up', 'count', 60]], ['valid_from' => '2026-11-16'])['push up'];

        $this->at('2026-11-16 08:00');
        $this->callAs('POST', $this->entries($baru->id), $odi, ['complete' => true])
            ->assertCreated()->assertJsonPath('day.tasks.0.target', 60)->assertJsonPath('day.tasks.0.total', 60);

        $response = $this->callAs('GET', "/api/habits/{$habit->id}/history", $odi)->assertOk()->assertJsonCount(2, 'days');
        $response->assertJsonPath('days.0.date', '2026-11-16')->assertJsonPath('days.0.members.0.tasks.0.target', 60);
        $response->assertJsonPath('days.1.date', '2026-11-10')->assertJsonPath('days.1.members.0.tasks.0.target', 50);
    }

    public function test_riwayat_validasi_kursor_dan_akses(): void
    {
        [$odi, , , $habit] = $this->hariSelasa();
        $url = "/api/habits/{$habit->id}/history";

        $this->callAs('GET', "{$url}?before=kemarin", $odi)->assertStatus(422)->assertJsonValidationErrors('before');
        $this->callAs('GET', "{$url}?before=2026-02-31", $odi)->assertStatus(422)->assertJsonValidationErrors('before');
        $this->callAs('GET', "{$url}?limit=32", $odi)->assertStatus(422)->assertJsonValidationErrors('limit');
        $this->callAs('GET', "{$url}?limit=0", $odi)->assertStatus(422)->assertJsonValidationErrors('limit');

        $this->callAs('GET', $url, $this->makeUser())->assertNotFound();
        $this->callAs('GET', $url)->assertUnauthorized();
    }

    // ---------- evaluasi mingguan ----------

    public function test_evaluasi_mingguan_menghitung_hari_yang_lengkap_lewat_tugas(): void
    {
        [$odi, , $circle] = $this->makePair();
        [$habit, $t] = $this->makeChecklist($circle, $odi, [['Push up', 'count', 50]], ['starts_on' => '2026-11-02']);

        $this->at('2026-11-03 08:00'); // Selasa
        $this->callAs('POST', $this->entries($t['push up']->id), $odi, ['complete' => true])->assertCreated();

        $this->at('2026-11-04 08:00'); // Rabu: baru 20 dari 50, hari ini TIDAK dihitung
        $this->callAs('POST', $this->entries($t['push up']->id), $odi, ['amount' => 20])->assertCreated();

        $this->at('2026-11-05 08:00'); // Kamis
        $this->callAs('POST', $this->entries($t['push up']->id), $odi, ['complete' => true])->assertCreated();

        $this->at('2026-11-09 00:11'); // evaluasi minggu 11-02..11-08
        $this->evaluate();

        $this->assertDatabaseHas('habit_weekly_summaries', [
            'habit_id' => $habit->id, 'user_id' => $odi->id, 'week_start' => '2026-11-02',
            'status' => 'achieved', 'done_count' => 2, 'target_snapshot' => 2,
        ]);
        $this->assertDatabaseHas('weekly_evaluations', [
            'user_id' => $odi->id, 'week_start' => '2026-11-02', 'habits_total' => 1, 'habits_achieved' => 1,
        ]);
    }
}
