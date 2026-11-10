<?php

namespace Tests\Feature\Api;

use App\Models\CircleInvite;
use Tests\ApiTestCase;

class CircleTest extends ApiTestCase
{
    public function test_membuat_sesi_membuat_pembuatnya_jadi_anggota_dengan_default_database(): void
    {
        $odi = $this->makeUser();

        $this->callAs('POST', '/api/circles', $odi, ['name' => 'Odi & Anjal'])
            ->assertCreated()
            ->assertJsonPath('circle.name', 'Odi & Anjal')
            ->assertJsonPath('circle.timezone', 'Asia/Jakarta')
            ->assertJsonPath('circle.reward_min_ratio', 80)       // default dari database ikut terbaca
            ->assertJsonPath('circle.punishment_below_ratio', 50)
            ->assertJsonPath('circle.is_full', false)
            ->assertJsonCount(1, 'circle.members');

        $this->assertDatabaseCount('circle_members', 1);
    }

    public function test_zona_waktu_harus_valid(): void
    {
        $this->callAs('POST', '/api/circles', $this->makeUser(), ['name' => 'X', 'timezone' => 'Mars/Olympus'])
            ->assertJsonValidationErrors('timezone');

        $this->callAs('POST', '/api/circles', $this->makeUser(), ['name' => 'X', 'timezone' => 'Asia/Makassar'])
            ->assertCreated()
            ->assertJsonPath('circle.timezone', 'Asia/Makassar');
    }

    public function test_batas_jumlah_sesi_per_user_saat_membuat(): void
    {
        config(['habit.max_circles_per_user' => 2]);
        $odi = $this->makeUser();

        $this->callAs('POST', '/api/circles', $odi, ['name' => 'Satu'])->assertCreated();
        $this->callAs('POST', '/api/circles', $odi, ['name' => 'Dua'])->assertCreated();
        $this->callAs('POST', '/api/circles', $odi, ['name' => 'Tiga'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_daftar_sesi_hanya_menampilkan_milik_sendiri(): void
    {
        $odi = $this->makeUser();
        $orangLain = $this->makeUser();
        $milikOdi = $this->makeCircle($odi);
        $this->makeCircle($orangLain);

        $this->callAs('GET', '/api/circles', $odi)
            ->assertOk()
            ->assertJsonCount(1, 'circles')
            ->assertJsonPath('circles.0.id', $milikOdi->id);
    }

    public function test_bukan_anggota_dapat_404_baik_untuk_lihat_maupun_buat_undangan(): void
    {
        $odi = $this->makeUser();
        $penyusup = $this->makeUser();
        $circle = $this->makeCircle($odi);

        $this->callAs('GET', "/api/circles/{$circle->id}", $penyusup)->assertNotFound();
        $this->callAs('POST', "/api/circles/{$circle->id}/invites", $penyusup)->assertNotFound();
        $this->callAs('GET', "/api/circles/{$circle->id}", $odi)->assertOk();
    }

    public function test_kode_undangan_berformat_benar_dan_berlaku_24_jam(): void
    {
        $odi = $this->makeUser();
        $circle = $this->makeCircle($odi);

        $response = $this->callAs('POST', "/api/circles/{$circle->id}/invites", $odi)->assertCreated();

        $this->assertMatchesRegularExpression('/^[ABCDEFGHJKLMNPQRSTUVWXYZ2-9]{8}$/', $response->json('code'));
        $sisaJam = now()->diffInHours($response->json('expires_at'), false);
        $this->assertGreaterThanOrEqual(23, $sisaJam);
        $this->assertLessThanOrEqual(24, $sisaJam);
    }

    public function test_kode_baru_menghanguskan_kode_lama_yang_masih_aktif(): void
    {
        $odi = $this->makeUser();
        $anjal = $this->makeUser();
        $circle = $this->makeCircle($odi);

        $kodeLama = $this->newInviteCode($odi, $circle);
        $kodeBaru = $this->newInviteCode($odi, $circle);

        $this->callAs('POST', '/api/circles/join', $anjal, ['code' => $kodeLama])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->callAs('POST', '/api/circles/join', $anjal, ['code' => $kodeBaru])->assertOk();
    }

    public function test_tidak_bisa_membuat_kode_untuk_sesi_yang_sudah_penuh(): void
    {
        $odi = $this->makeUser();
        $circle = $this->makeCircle($odi, $this->makeUser());

        $this->callAs('POST', "/api/circles/{$circle->id}/invites", $odi)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('circle');
    }

    public function test_join_berhasil_dan_kode_tidak_bisa_dipakai_dua_kali(): void
    {
        $odi = $this->makeUser();
        $anjal = $this->makeUser();
        $orangKetiga = $this->makeUser();
        $circle = $this->makeCircle($odi);
        $code = $this->newInviteCode($odi, $circle);

        $this->callAs('POST', '/api/circles/join', $anjal, ['code' => $code])
            ->assertOk()
            ->assertJsonPath('circle.id', $circle->id)
            ->assertJsonPath('circle.is_full', true)
            ->assertJsonCount(2, 'circle.members');

        $this->assertDatabaseHas('circle_invites', ['code' => $code, 'used_by' => $anjal->id]);

        $this->callAs('POST', '/api/circles/join', $orangKetiga, ['code' => $code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_kode_diterima_walau_huruf_kecil_dan_pakai_tanda_hubung(): void
    {
        $odi = $this->makeUser();
        $anjal = $this->makeUser();
        $circle = $this->makeCircle($odi);
        $code = $this->newInviteCode($odi, $circle);
        $diketik = strtolower(substr($code, 0, 4).'-'.substr($code, 4));

        $this->callAs('POST', '/api/circles/join', $anjal, ['code' => $diketik])->assertOk();
    }

    public function test_kode_kedaluwarsa_ditolak(): void
    {
        $odi = $this->makeUser();
        $anjal = $this->makeUser();
        $circle = $this->makeCircle($odi);
        $code = $this->newInviteCode($odi, $circle);
        CircleInvite::query()->update(['expires_at' => now()->subMinute()]);

        $this->callAs('POST', '/api/circles/join', $anjal, ['code' => $code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->assertDatabaseCount('circle_members', 1);
    }

    public function test_pembuat_sesi_tidak_bisa_join_sesinya_sendiri(): void
    {
        $odi = $this->makeUser();
        $circle = $this->makeCircle($odi);
        $code = $this->newInviteCode($odi, $circle);

        $this->callAs('POST', '/api/circles/join', $odi, ['code' => $code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        // Kode tidak boleh terbakar oleh percobaan yang gagal.
        $this->assertDatabaseHas('circle_invites', ['code' => $code, 'used_at' => null]);
    }

    public function test_sesi_yang_keburu_penuh_menolak_dan_kode_tidak_terbakar(): void
    {
        $odi = $this->makeUser();
        $circle = $this->makeCircle($odi);
        $code = $this->newInviteCode($odi, $circle);

        // Setelah kode dibuat, slot kedua keburu terisi lewat jalur lain.
        $circle->members()->attach($this->makeUser()->id);

        $this->callAs('POST', '/api/circles/join', $this->makeUser(), ['code' => $code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->assertDatabaseHas('circle_invites', ['code' => $code, 'used_at' => null]);
        $this->assertDatabaseCount('circle_members', 2);
    }

    public function test_batas_jumlah_sesi_per_user_saat_join(): void
    {
        config(['habit.max_circles_per_user' => 1]);
        $odi = $this->makeUser();
        $anjal = $this->makeUser();
        $this->makeCircle($anjal); // Anjal sudah di batas
        $circle = $this->makeCircle($odi);
        $code = $this->newInviteCode($odi, $circle);

        $this->callAs('POST', '/api/circles/join', $anjal, ['code' => $code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_tebakan_kode_dibatasi_lima_per_menit(): void
    {
        $anjal = $this->makeUser();

        for ($i = 0; $i < 5; $i++) {
            $this->callAs('POST', '/api/circles/join', $anjal, ['code' => 'ABCDEFGH'])->assertUnprocessable();
        }

        $this->callAs('POST', '/api/circles/join', $anjal, ['code' => 'ABCDEFGH'])->assertStatus(429);
    }

    public function test_pembuatan_kode_dibatasi_sepuluh_per_jam(): void
    {
        $odi = $this->makeUser();
        $circle = $this->makeCircle($odi);

        for ($i = 0; $i < 10; $i++) {
            $this->callAs('POST', "/api/circles/{$circle->id}/invites", $odi)->assertCreated();
        }

        $this->callAs('POST', "/api/circles/{$circle->id}/invites", $odi)->assertStatus(429);
    }
}
