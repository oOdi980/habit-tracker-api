<?php

namespace Tests\Feature\Api;

use App\Models\CircleInvite;
use App\Models\User;
use App\Services\AuthService;
use Tests\ApiTestCase;

class AuthTest extends ApiTestCase
{
    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Anjal',
            'email' => 'anjal@example.com',
            'password' => 'rahasia123',
            'device_name' => 'iphone',
        ], $overrides);
    }

    public function test_akun_pertama_boleh_daftar_tanpa_kode_undangan(): void
    {
        $this->callAs('POST', '/api/auth/register', null, $this->registerPayload([
            'name' => 'Odi',
            'email' => 'Odi@Example.com', // huruf besar harus dinormalkan
        ]))
            ->assertCreated()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email'], 'circle'])
            ->assertJsonPath('user.email', 'odi@example.com')
            ->assertJsonPath('circle', null);

        $user = User::firstOrFail();
        $this->assertNotSame('rahasia123', $user->password); // tersimpan sebagai hash
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_akun_kedua_tanpa_kode_undangan_ditolak(): void
    {
        $this->makeUser();

        $this->callAs('POST', '/api/auth/register', null, $this->registerPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('invite_code');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_daftar_dengan_kode_undangan_langsung_masuk_ke_sesi(): void
    {
        $odi = $this->makeUser();
        $circle = $this->makeCircle($odi);
        $code = $this->newInviteCode($odi, $circle);

        $this->callAs('POST', '/api/auth/register', null, $this->registerPayload(['invite_code' => $code]))
            ->assertCreated()
            ->assertJsonPath('circle.id', $circle->id)
            ->assertJsonPath('circle.is_full', true);

        $anjal = User::where('email', 'anjal@example.com')->firstOrFail();
        $this->assertDatabaseHas('circle_members', ['circle_id' => $circle->id, 'user_id' => $anjal->id]);
        $this->assertDatabaseHas('circle_invites', ['code' => $code, 'used_by' => $anjal->id]);
    }

    public function test_kode_salah_tidak_meninggalkan_akun_yatim(): void
    {
        $odi = $this->makeUser();
        $this->makeCircle($odi);

        $this->callAs('POST', '/api/auth/register', null, $this->registerPayload(['invite_code' => 'ZZZZZZZZ']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('invite_code');

        // Rollback: user yang sempat dibuat di dalam transaksi tidak boleh tersisa.
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_kode_kedaluwarsa_ditolak_dan_tidak_ada_akun_baru(): void
    {
        $odi = $this->makeUser();
        $circle = $this->makeCircle($odi);
        $code = $this->newInviteCode($odi, $circle);
        CircleInvite::query()->update(['expires_at' => now()->subMinute()]);

        $this->callAs('POST', '/api/auth/register', null, $this->registerPayload(['invite_code' => $code]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('invite_code');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_email_kembar_ditolak_tanpa_peduli_huruf_besar_kecil(): void
    {
        $this->makeUser(['email' => 'odi@example.com']);

        $this->callAs('POST', '/api/auth/register', null, $this->registerPayload(['email' => 'ODI@example.com']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_password_terlalu_pendek_atau_terlalu_panjang_ditolak(): void
    {
        $this->callAs('POST', '/api/auth/register', null, $this->registerPayload(['password' => 'pendek']))
            ->assertJsonValidationErrors('password');

        $this->callAs('POST', '/api/auth/register', null, $this->registerPayload(['password' => str_repeat('a', 73)]))
            ->assertJsonValidationErrors('password');
    }

    public function test_login_berhasil_dan_token_bisa_dipakai(): void
    {
        $user = $this->makeUser(['email' => 'odi@example.com', 'password' => 'rahasia123']);

        $token = $this->callAs('POST', '/api/auth/login', null, [
            'email' => 'ODI@example.com', // login juga tidak peka huruf besar/kecil
            'password' => 'rahasia123',
            'device_name' => 'android',
        ])->assertOk()->json('token');

        $this->callAs('GET', '/api/auth/me', $token)
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_login_gagal_memberi_401_dengan_pesan_yang_sama(): void
    {
        $this->makeUser(['email' => 'odi@example.com', 'password' => 'rahasia123']);

        $salahPassword = $this->callAs('POST', '/api/auth/login', null, ['email' => 'odi@example.com', 'password' => 'salah-salah']);
        $emailTakAda = $this->callAs('POST', '/api/auth/login', null, ['email' => 'hantu@example.com', 'password' => 'rahasia123']);

        $salahPassword->assertUnauthorized();
        $emailTakAda->assertUnauthorized();
        $this->assertSame($salahPassword->json('message'), $emailTakAda->json('message'));
    }

    public function test_login_dibatasi_setelah_lima_percobaan_per_menit(): void
    {
        $this->makeUser(['email' => 'odi@example.com', 'password' => 'rahasia123']);

        for ($i = 0; $i < 5; $i++) {
            $this->callAs('POST', '/api/auth/login', null, ['email' => 'odi@example.com', 'password' => 'salah'])
                ->assertUnauthorized();
        }

        $this->callAs('POST', '/api/auth/login', null, ['email' => 'odi@example.com', 'password' => 'rahasia123'])
            ->assertStatus(429);
    }

    public function test_tanpa_token_dapat_401_json_bukan_redirect(): void
    {
        // Sengaja tanpa header Accept. 401 JSON dijamin bootstrap/app.php (redirectGuestsTo null +
        // shouldRenderJsonWhen untuk api/*), bukan ForceJsonResponse (auth berjalan lebih dulu).
        $this->get('/api/auth/me')->assertUnauthorized()->assertJsonStructure(['message']);
    }

    public function test_logout_mencabut_token_perangkat_itu_saja(): void
    {
        $this->makeUser(['email' => 'odi@example.com', 'password' => 'rahasia123']);
        $login = fn (string $device) => $this->callAs('POST', '/api/auth/login', null, [
            'email' => 'odi@example.com', 'password' => 'rahasia123', 'device_name' => $device,
        ])->json('token');

        $tokenA = $login('android');
        $tokenB = $login('web');

        $this->callAs('POST', '/api/auth/logout', $tokenA)->assertNoContent();

        $this->callAs('GET', '/api/auth/me', $tokenA)->assertUnauthorized();
        $this->callAs('GET', '/api/auth/me', $tokenB)->assertOk();
    }

    public function test_ganti_password_wajib_password_lama(): void
    {
        $user = $this->makeUser(['password' => 'lamaPass123']);

        $this->callAs('POST', '/api/auth/password', $user, ['current_password' => 'salah', 'password' => 'baruPass456'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->callAs('POST', '/api/auth/password', $user, ['current_password' => 'lamaPass123', 'password' => 'lamaPass123'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password'); // tidak boleh sama dengan yang lama
    }

    public function test_ganti_password_mengeluarkan_perangkat_lain_tapi_perangkat_ini_tetap_login(): void
    {
        $user = $this->makeUser(['email' => 'odi@example.com', 'password' => 'lamaPass123']);
        $login = fn (string $device) => $this->callAs('POST', '/api/auth/login', null, [
            'email' => 'odi@example.com', 'password' => 'lamaPass123', 'device_name' => $device,
        ])->json('token');

        $tokenA = $login('android');
        $tokenB = $login('web');

        $this->callAs('POST', '/api/auth/password', $tokenA, ['current_password' => 'lamaPass123', 'password' => 'baruPass456'])
            ->assertOk();

        $this->callAs('GET', '/api/auth/me', $tokenA)->assertOk();          // perangkat ini bertahan
        $this->callAs('GET', '/api/auth/me', $tokenB)->assertUnauthorized(); // perangkat lain dikeluarkan

        // Login ulang: password lama gagal, password baru berhasil. (Throttle login 5/menit
        // aman: sejauh ini baru 2 login + 2 percobaan di sini.)
        $this->callAs('POST', '/api/auth/login', null, ['email' => 'odi@example.com', 'password' => 'lamaPass123'])->assertUnauthorized();
        $this->callAs('POST', '/api/auth/login', null, ['email' => 'odi@example.com', 'password' => 'baruPass456'])->assertOk();
    }

    public function test_jumlah_token_per_user_dibatasi_dan_yang_terlama_dibuang(): void
    {
        $user = $this->makeUser();
        $service = app(AuthService::class);

        for ($i = 0; $i < 12; $i++) {
            $service->issueToken($user, "perangkat-{$i}");
        }

        $this->assertSame(10, $user->tokens()->count());
        $this->assertFalse($user->tokens()->where('name', 'perangkat-0')->exists()); // terlama dibuang
        $this->assertTrue($user->tokens()->where('name', 'perangkat-11')->exists());  // terbaru aman
    }

    public function test_me_menampilkan_sesi_beserta_anggota_tanpa_membocorkan_email_partner(): void
    {
        $odi = $this->makeUser(['name' => 'Odi']);
        $anjal = $this->makeUser(['name' => 'Anjal', 'email' => 'rahasia-anjal@example.com']);
        $this->makeCircle($odi, $anjal);

        $response = $this->callAs('GET', '/api/auth/me', $odi)->assertOk();

        $this->assertCount(1, $response->json('circles'));
        $this->assertCount(2, $response->json('circles.0.members'));
        $response->assertJsonMissing(['email' => 'rahasia-anjal@example.com']);
    }
}
