<?php

namespace Tests\Feature\Api;

use App\Models\HabitTask;
use App\Models\User;
use Tests\ApiTestCase;

// Daftar tugas sebuah habit (definisi). Progres harian ada di TaskProgressTest.
// Kalender acuan (WIB): Senin 2026-11-09, Selasa 11-10, Senin 11-16, Senin 11-23.
class HabitTaskTest extends ApiTestCase
{
    private function tasksUrl(int $habitId): string
    {
        return "/api/habits/{$habitId}/tasks";
    }

    private function createUrl(int $circleId): string
    {
        return "/api/circles/{$circleId}/habits";
    }

    private function task(string $name = 'Push up', string $metric = 'count', int $target = 50): array
    {
        return ['name' => $name, 'metric' => $metric, 'target' => $target];
    }

    private function payload(array $tasks, array $extra = []): array
    {
        return array_merge([
            'title' => 'Olahraga', 'scope' => 'personal', 'target_per_week' => 4, 'mode' => 'checklist', 'tasks' => $tasks,
        ], $extra);
    }

    private function boardHabit(User $user, int $circleId, int $habitId): array
    {
        $habits = $this->callAs('GET', "/api/circles/{$circleId}/today", $user)->assertOk()->json('habits');

        return collect($habits)->firstWhere('id', $habitId);
    }

    // ---------- membuat habit checklist ----------

    public function test_membuat_habit_checklist_menyimpan_tugas_pembuatnya(): void
    {
        [$odi, , $circle] = $this->makePair();
        $this->at('2026-11-10 08:00');

        $response = $this->callAs('POST', $this->createUrl($circle->id), $odi, $this->payload([
            $this->task('  Hammer   Curl ', 'count', 30),
            $this->task('Lari', 'meters', 3000),
        ]))->assertCreated()
            ->assertJsonPath('habit.mode', 'checklist')
            ->assertJsonPath('habit.starts_on', '2026-11-16'); // habit personal mulai Senin depan

        $habitId = $response->json('habit.id');

        // Nama dirapikan untuk tampilan, kuncinya huruf kecil; berlaku "sejak habit dibuat" (valid_from kosong).
        $this->assertDatabaseHas('habit_tasks', [
            'habit_id' => $habitId, 'user_id' => $odi->id, 'name' => 'Hammer Curl', 'name_key' => 'hammer curl',
            'metric' => 'count', 'target' => 30, 'position' => 0, 'valid_from' => null, 'valid_until' => null,
        ]);
        $this->assertDatabaseHas('habit_tasks', [
            'habit_id' => $habitId, 'name' => 'Lari', 'metric' => 'meters', 'target' => 3000, 'position' => 1,
        ]);
        $this->assertDatabaseCount('habit_tasks', 2);
    }

    public function test_tanpa_mode_habit_tetap_simpel_dan_tidak_boleh_membawa_tugas(): void
    {
        [$odi, , $circle] = $this->makePair();
        $this->at('2026-11-10 08:00');

        $this->callAs('POST', $this->createUrl($circle->id), $odi, [
            'title' => 'Tidur cepat', 'scope' => 'personal', 'target_per_week' => 5,
        ])->assertCreated()->assertJsonPath('habit.mode', 'simple');

        // Tugas tanpa mode, atau dengan mode simple, ditolak (bukan diam-diam diabaikan).
        $withoutMode = $this->payload([$this->task()]);
        unset($withoutMode['mode']);
        $this->callAs('POST', $this->createUrl($circle->id), $odi, $withoutMode)
            ->assertStatus(422)->assertJsonValidationErrors('tasks');

        $this->callAs('POST', $this->createUrl($circle->id), $odi, $this->payload([$this->task()], ['mode' => 'simple']))
            ->assertStatus(422)->assertJsonValidationErrors('tasks');

        $this->assertDatabaseCount('habit_tasks', 0);
    }

    public function test_validasi_daftar_tugas_saat_membuat_habit(): void
    {
        [$odi, , $circle] = $this->makePair();
        $this->at('2026-11-10 08:00');

        $tanpaTasks = $this->payload([]);
        unset($tanpaTasks['tasks']);

        $sebelas = [];
        for ($i = 1; $i <= 11; $i++) {
            $sebelas[] = $this->task("Tugas {$i}");
        }

        $cases = [
            'tasks wajib untuk checklist' => [$tanpaTasks, 'tasks'],
            'tasks kosong' => [$this->payload([]), 'tasks'],
            'lebih dari 10 tugas' => [$this->payload($sebelas), 'tasks'],
            'tanpa nama' => [$this->payload([['metric' => 'count', 'target' => 5]]), 'tasks.0.name'],
            'nama terlalu panjang' => [$this->payload([$this->task(str_repeat('a', 61))]), 'tasks.0.name'],
            'nama berisi karakter kontrol' => [$this->payload([$this->task("a\x07b")]), 'tasks.0.name'],
            'jenis ukuran tidak dikenal' => [$this->payload([$this->task('A', 'kg', 5)]), 'tasks.0.metric'],
            'target nol' => [$this->payload([$this->task('A', 'count', 0)]), 'tasks.0.target'],
            'target hitungan terlalu besar' => [$this->payload([$this->task('A', 'count', 10001)]), 'tasks.0.target'],
            'target menit lebih dari sehari' => [$this->payload([$this->task('A', 'minutes', 1441)]), 'tasks.0.target'],
            'nama kembar (huruf dan spasi diabaikan)' => [
                $this->payload([$this->task('Push up'), $this->task(' push  UP ')]), 'tasks.1.name',
            ],
        ];

        foreach ($cases as $label => [$payload, $errorKey]) {
            $response = $this->callAs('POST', $this->createUrl($circle->id), $odi, $payload);

            $this->assertSame(422, $response->getStatusCode(), "Kasus: {$label}");
            $response->assertJsonValidationErrors([$errorKey]);
        }

        // Batas tepat masih sah.
        $this->callAs('POST', $this->createUrl($circle->id), $odi, $this->payload([
            $this->task('Hitung', 'count', 10000), $this->task('Menit', 'minutes', 1440),
        ]))->assertCreated();

        // Tidak ada habit yang tersisa dari percobaan yang gagal (hanya yang terakhir ini).
        $this->assertDatabaseCount('habits', 1);
    }

    // ---------- melihat daftar ----------

    public function test_partner_bisa_melihat_daftar_tugas_dan_orang_luar_tidak(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        [$habit] = $this->makeChecklist($circle, $odi);
        $this->at('2026-11-10 08:00');

        $this->callAs('GET', $this->tasksUrl($habit->id), $anjal)
            ->assertOk()
            ->assertJsonPath('habit_id', $habit->id)
            ->assertJsonPath('mode', 'checklist')
            ->assertJsonPath('today', '2026-11-10')
            ->assertJsonPath('next_monday', '2026-11-16')
            ->assertJsonCount(1, 'members') // habit personal: hanya pemiliknya
            ->assertJsonPath('members.0.user_id', $odi->id)
            ->assertJsonPath('members.0.has_pending_change', false)
            ->assertJsonCount(3, 'members.0.current')
            ->assertJsonCount(3, 'members.0.next')
            ->assertJsonPath('members.0.current.0.name', 'Push up')
            ->assertJsonPath('members.0.current.0.metric', 'count')
            ->assertJsonPath('members.0.current.0.target', 50)
            ->assertJsonPath('members.0.current.2.metric', 'meters');

        $orangLuar = $this->makeUser();
        $this->callAs('GET', $this->tasksUrl($habit->id), $orangLuar)->assertNotFound();
        $this->callAs('GET', $this->tasksUrl($habit->id))->assertUnauthorized();
    }

    public function test_habit_simpel_tidak_punya_daftar_tugas(): void
    {
        [$odi, , $circle] = $this->makePair();
        $simpel = $this->makeHabit($circle, $odi);
        $this->at('2026-11-10 08:00');

        $this->callAs('GET', $this->tasksUrl($simpel->id), $odi)
            ->assertStatus(422)->assertJsonValidationErrors('habit');

        $this->callAs('PUT', $this->tasksUrl($simpel->id), $odi, ['tasks' => [$this->task()]])
            ->assertStatus(422)->assertJsonValidationErrors('habit');
    }

    public function test_habit_yang_sudah_berakhir_tidak_bisa_dilihat_atau_diubah(): void
    {
        [$odi, , $circle] = $this->makePair();
        [$habit] = $this->makeChecklist($circle, $odi, null, ['starts_on' => '2026-11-02', 'ends_on' => '2026-11-09']);
        $this->at('2026-11-10 08:00');

        $this->callAs('GET', $this->tasksUrl($habit->id), $odi)->assertNotFound();
        $this->callAs('PUT', $this->tasksUrl($habit->id), $odi, ['tasks' => [$this->task()]])->assertNotFound();
    }

    // ---------- mengganti daftar: habit belum mulai ----------

    public function test_habit_yang_belum_mulai_daftarnya_diganti_langsung(): void
    {
        [$odi, , $circle] = $this->makePair();
        [$habit] = $this->makeChecklist($circle, $odi, null, ['starts_on' => '2026-11-16']); // akan datang
        $this->at('2026-11-10 08:00');

        $this->callAs('PUT', $this->tasksUrl($habit->id), $odi, ['tasks' => [
            $this->task('Push up', 'count', 60), $this->task('Lari', 'meters', 2000),
        ]])->assertOk()
            ->assertJsonPath('members.0.has_pending_change', false)
            ->assertJsonCount(2, 'members.0.current')
            ->assertJsonPath('members.0.current.0.target', 60)
            ->assertJsonPath('members.0.current.1.name', 'Lari');

        // Pull up hilang betul-betul (belum ada riwayat yang perlu dijaga); tidak ada baris versi.
        $this->assertDatabaseMissing('habit_tasks', ['habit_id' => $habit->id, 'name_key' => 'pull up']);
        $this->assertSame(2, HabitTask::where('habit_id', $habit->id)->count());
        $this->assertSame(0, HabitTask::where('habit_id', $habit->id)->whereNotNull('valid_from')->count());
        $this->assertSame(0, HabitTask::where('habit_id', $habit->id)->whereNotNull('valid_until')->count());
    }

    // ---------- mengganti daftar: habit sudah berjalan (berlaku Senin depan) ----------

    public function test_perubahan_di_habit_berjalan_baru_berlaku_senin_depan(): void
    {
        [$odi, , $circle] = $this->makePair();
        [$habit, $t] = $this->makeChecklist($circle, $odi); // push up 50, pull up 20, lari 3000
        $this->at('2026-11-10 08:00'); // Selasa

        $this->callAs('PUT', $this->tasksUrl($habit->id), $odi, ['tasks' => [
            $this->task('Push up', 'count', 60),      // target naik  -> versi baru Senin depan
            $this->task('Lari', 'meters', 3000),      // tidak berubah
            $this->task('Plank', 'minutes', 5),       // baru        -> mulai Senin depan
        ]])->assertOk()
            ->assertJsonPath('members.0.has_pending_change', true)
            // Yang berlaku SEKARANG tidak berubah sama sekali.
            ->assertJsonPath('members.0.current.0.name', 'Push up')
            ->assertJsonPath('members.0.current.0.target', 50)
            ->assertJsonPath('members.0.current.1.name', 'Pull up')
            ->assertJsonPath('members.0.current.2.name', 'Lari')
            // Daftar yang berlaku mulai Senin depan.
            ->assertJsonCount(3, 'members.0.next')
            ->assertJsonPath('members.0.next.0.name', 'Push up')
            ->assertJsonPath('members.0.next.0.target', 60)
            ->assertJsonPath('members.0.next.1.name', 'Lari')
            ->assertJsonPath('members.0.next.2.name', 'Plank');

        // Versi lama ditutup Senin depan (bukan dihapus): entri lama tetap menunjuk target yang berlaku saat itu.
        $this->assertSame('2026-11-16', $t['push up']->fresh()->valid_until);
        $this->assertSame(50, $t['push up']->fresh()->target);
        $this->assertSame('2026-11-16', $t['pull up']->fresh()->valid_until); // dihapus dari daftar
        $this->assertNull($t['lari']->fresh()->valid_until);                  // tidak tersentuh
        $this->assertSame(1, $t['lari']->fresh()->position);                  // hanya urutan tampilan yang diperbarui

        $baru = HabitTask::where('habit_id', $habit->id)->where('name_key', 'push up')->whereNotNull('valid_from')->firstOrFail();
        $this->assertSame(60, $baru->target);
        $this->assertSame('2026-11-16', $baru->valid_from);
        $this->assertNull($baru->valid_until);

        $plank = HabitTask::where('habit_id', $habit->id)->where('name_key', 'plank')->firstOrFail();
        $this->assertSame('2026-11-16', $plank->valid_from);
        $this->assertSame('minutes', $plank->metric->value);

        // Papan hari ini masih memakai daftar lama; di Senin depan memakai yang baru.
        $hariIni = $this->boardHabit($odi, $circle->id, $habit->id);
        $this->assertSame(['Push up', 'Pull up', 'Lari'], array_column($hariIni['detail'][0]['tasks'], 'name'));
        $this->assertSame(50, $hariIni['detail'][0]['tasks'][0]['target']);

        $this->at('2026-11-16 08:00');
        $senin = $this->boardHabit($odi, $circle->id, $habit->id);
        $this->assertSame(['Push up', 'Lari', 'Plank'], array_column($senin['detail'][0]['tasks'], 'name'));
        $this->assertSame(60, $senin['detail'][0]['tasks'][0]['target']);
    }

    public function test_mengedit_dua_kali_sebelum_senin_yang_terakhir_menang_dan_mengembalikan_ke_awal_membatalkan(): void
    {
        [$odi, , $circle] = $this->makePair();
        [$habit, $t] = $this->makeChecklist($circle, $odi, [['Push up', 'count', 50]]);
        $this->at('2026-11-10 08:00');

        $this->callAs('PUT', $this->tasksUrl($habit->id), $odi, ['tasks' => [$this->task('Push up', 'count', 60)]])
            ->assertOk()->assertJsonPath('members.0.has_pending_change', true);
        $this->assertSame(2, HabitTask::where('habit_id', $habit->id)->count());

        // Kembali ke target semula: jadwal perubahan dibatalkan, tidak menumpuk baris versi.
        $this->callAs('PUT', $this->tasksUrl($habit->id), $odi, ['tasks' => [$this->task('Push up', 'count', 50)]])
            ->assertOk()
            ->assertJsonPath('members.0.has_pending_change', false)
            ->assertJsonPath('members.0.next.0.target', 50);

        $this->assertSame(1, HabitTask::where('habit_id', $habit->id)->count());
        $this->assertNull($t['push up']->fresh()->valid_until);
    }

    public function test_tugas_yang_dijadwalkan_hilang_bisa_dikembalikan_sebelum_senin(): void
    {
        [$odi, , $circle] = $this->makePair();
        [$habit, $t] = $this->makeChecklist($circle, $odi, [['Push up', 'count', 50], ['Pull up', 'count', 20]]);
        $this->at('2026-11-10 08:00');

        $this->callAs('PUT', $this->tasksUrl($habit->id), $odi, ['tasks' => [$this->task('Push up', 'count', 50)]])
            ->assertOk()->assertJsonCount(1, 'members.0.next')->assertJsonCount(2, 'members.0.current');
        $this->assertSame('2026-11-16', $t['pull up']->fresh()->valid_until);

        $this->callAs('PUT', $this->tasksUrl($habit->id), $odi, ['tasks' => [
            $this->task('Push up', 'count', 50), $this->task('Pull up', 'count', 20),
        ]])->assertOk()->assertJsonPath('members.0.has_pending_change', false);

        $this->assertNull($t['pull up']->fresh()->valid_until);
        $this->assertSame(2, HabitTask::where('habit_id', $habit->id)->count());
    }

    public function test_hanya_nama_tampilan_dan_urutan_yang_berubah_tidak_menjadwalkan_versi_baru(): void
    {
        [$odi, , $circle] = $this->makePair();
        [$habit, $t] = $this->makeChecklist($circle, $odi, [['Push up', 'count', 50], ['Pull up', 'count', 20]]);
        $this->at('2026-11-10 08:00');

        $this->callAs('PUT', $this->tasksUrl($habit->id), $odi, ['tasks' => [
            $this->task('Pull up', 'count', 20), $this->task('PUSH UP', 'count', 50),
        ]])->assertOk()
            ->assertJsonPath('members.0.has_pending_change', false)
            ->assertJsonPath('members.0.current.0.name', 'Pull up')
            ->assertJsonPath('members.0.current.1.name', 'PUSH UP');

        $this->assertSame(2, HabitTask::where('habit_id', $habit->id)->count());
        $this->assertSame('PUSH UP', $t['push up']->fresh()->name);
        $this->assertNull($t['push up']->fresh()->valid_until);
    }

    // ---------- habit bersama dan hak ubah ----------

    public function test_partner_tanpa_tugas_di_habit_bersama_langsung_berlaku_hari_ini(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, null, ['mode' => 'checklist', 'target_per_week' => 2]); // bersama, aktif
        $this->makeTasks($habit, $odi, [['Push up', 'count', 50]]);
        $this->at('2026-11-10 08:00'); // Selasa, tengah minggu

        // Anjal belum punya tugas sama sekali: kalau menunggu Senin depan, dia tidak bisa check-in minggu ini.
        $this->callAs('PUT', $this->tasksUrl($habit->id), $anjal, ['tasks' => [$this->task('Jalan', 'steps', 8000)]])
            ->assertOk()
            ->assertJsonCount(2, 'members')
            ->assertJsonPath('members.0.user_id', $odi->id)
            ->assertJsonPath('members.1.user_id', $anjal->id)
            ->assertJsonPath('members.1.current.0.name', 'Jalan')
            ->assertJsonPath('members.1.has_pending_change', false);

        $this->assertDatabaseHas('habit_tasks', ['user_id' => $anjal->id, 'name_key' => 'jalan', 'valid_from' => '2026-11-10']);

        $taskId = HabitTask::where('user_id', $anjal->id)->firstOrFail()->id;
        $this->callAs('POST', "/api/tasks/{$taskId}/entries", $anjal, ['complete' => true])
            ->assertCreated()
            ->assertJsonPath('day.tasks.0.total', 8000)
            ->assertJsonPath('day.complete', true);
    }

    public function test_tiap_anggota_hanya_mengubah_daftarnya_sendiri(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $habit = $this->makeHabit($circle, null, ['mode' => 'checklist']);
        $this->makeTasks($habit, $odi, [['Push up', 'count', 50]]);
        $milikAnjal = $this->makeTasks($habit, $anjal, [['Jalan', 'steps', 8000]]);
        $this->at('2026-11-10 08:00');

        $this->callAs('PUT', $this->tasksUrl($habit->id), $odi, ['tasks' => [$this->task('Squat', 'count', 30)]])->assertOk();

        // Daftar Anjal tidak tersentuh; daftar Odi dijadwalkan berganti.
        $this->assertSame(1, HabitTask::where('user_id', $anjal->id)->count());
        $this->assertNull($milikAnjal['jalan']->fresh()->valid_until);
        $this->assertSame(2, HabitTask::where('user_id', $odi->id)->count()); // push up (ditutup) + squat (baru)
    }

    public function test_habit_personal_hanya_boleh_diubah_pemiliknya(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        [$habit] = $this->makeChecklist($circle, $odi);
        $this->at('2026-11-10 08:00');

        $this->callAs('PUT', $this->tasksUrl($habit->id), $anjal, ['tasks' => [$this->task()]])->assertForbidden();
        $this->assertSame(3, HabitTask::where('habit_id', $habit->id)->count());
    }

    public function test_validasi_saat_mengganti_daftar(): void
    {
        [$odi, , $circle] = $this->makePair();
        [$habit] = $this->makeChecklist($circle, $odi);
        $this->at('2026-11-10 08:00');

        $this->callAs('PUT', $this->tasksUrl($habit->id), $odi, ['tasks' => []])
            ->assertStatus(422)->assertJsonValidationErrors('tasks');

        $this->callAs('PUT', $this->tasksUrl($habit->id), $odi, ['tasks' => [$this->task('A'), $this->task(' a ')]])
            ->assertStatus(422)->assertJsonValidationErrors('tasks.1.name');

        $this->callAs('PUT', $this->tasksUrl($habit->id), $odi, ['tasks' => [$this->task('A', 'steps', 200001)]])
            ->assertStatus(422)->assertJsonValidationErrors('tasks.0.target');

        // Semua percobaan gagal: daftar lama utuh.
        $this->assertSame(3, HabitTask::where('habit_id', $habit->id)->count());
    }
}
