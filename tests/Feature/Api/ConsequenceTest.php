<?php

namespace Tests\Feature\Api;

use Tests\ApiTestCase;

class ConsequenceTest extends ApiTestCase
{
    private function url(int $circleId): string
    {
        return "/api/circles/{$circleId}/consequences";
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['type' => 'punishment', 'title' => 'Cuci piring seminggu'], $overrides);
    }

    public function test_usulan_baru_menunggu_persetujuan_partner(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();

        $this->callAs('POST', $this->url($circle->id), $odi, $this->payload(['description' => 'Tanpa mengeluh']))
            ->assertCreated()
            ->assertJsonPath('consequence.type', 'punishment')
            ->assertJsonPath('consequence.title', 'Cuci piring seminggu')
            ->assertJsonPath('consequence.created_by', $odi->id)
            ->assertJsonPath('consequence.approved', false)
            ->assertJsonPath('consequence.needs_my_approval', false); // pengusul tidak menyetujui sendiri

        $this->callAs('GET', $this->url($circle->id), $anjal)
            ->assertOk()
            ->assertJsonCount(1, 'consequences')
            ->assertJsonPath('consequences.0.needs_my_approval', true);
    }

    public function test_validasi_usulan(): void
    {
        [$odi, , $circle] = $this->makePair();

        $this->callAs('POST', $this->url($circle->id), $odi, $this->payload(['type' => 'hadiah']))->assertJsonValidationErrors('type');
        $this->callAs('POST', $this->url($circle->id), $odi, $this->payload(['title' => '']))->assertJsonValidationErrors('title');
        $this->callAs('POST', $this->url($circle->id), $odi, $this->payload(['title' => str_repeat('x', 121)]))->assertJsonValidationErrors('title');
        $this->callAs('POST', $this->url($circle->id), $odi, $this->payload(['description' => str_repeat('x', 501)]))->assertJsonValidationErrors('description');
        $this->assertDatabaseCount('consequences', 0);
    }

    public function test_partner_menyetujui_dan_pengusul_tidak_bisa_menyetujui_sendiri(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $id = $this->callAs('POST', $this->url($circle->id), $odi, $this->payload())->json('consequence.id');

        $this->callAs('POST', "/api/consequences/{$id}/approve", $odi)->assertForbidden();

        $this->callAs('POST', "/api/consequences/{$id}/approve", $anjal)
            ->assertOk()
            ->assertJsonPath('consequence.approved', true)
            ->assertJsonPath('consequence.needs_my_approval', false);

        $this->assertDatabaseHas('consequences', ['id' => $id, 'approved_by' => $anjal->id]);
    }

    public function test_menyetujui_dua_kali_tidak_menggeser_waktu_persetujuan_pertama(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $id = $this->callAs('POST', $this->url($circle->id), $odi, $this->payload())->json('consequence.id');

        // Waktu persetujuan pertama menentukan kapan item boleh ikut spin, jadi tidak boleh bergeser.
        $this->at('2026-11-04 10:00');
        $this->callAs('POST', "/api/consequences/{$id}/approve", $anjal)->assertOk();
        $first = $this->app['db']->table('consequences')->where('id', $id)->value('approved_at');

        $this->at('2026-11-06 10:00');
        $this->callAs('POST', "/api/consequences/{$id}/approve", $anjal)->assertOk();

        $this->assertSame($first, $this->app['db']->table('consequences')->where('id', $id)->value('approved_at'));
    }

    public function test_bukan_anggota_dapat_404_untuk_semua_operasi(): void
    {
        [$odi, , $circle] = $this->makePair();
        $item = $this->makeConsequence($circle, 'reward');
        $penyusup = $this->makeUser();

        $this->callAs('GET', $this->url($circle->id), $penyusup)->assertNotFound();
        $this->callAs('POST', $this->url($circle->id), $penyusup, $this->payload())->assertNotFound();
        $this->callAs('POST', "/api/consequences/{$item->id}/approve", $penyusup)->assertNotFound();
        $this->callAs('DELETE', "/api/consequences/{$item->id}", $penyusup)->assertNotFound();
        $this->callAs('GET', $this->url($circle->id))->assertUnauthorized();
    }

    public function test_usulan_yang_belum_disetujui_boleh_dibatalkan_pengusul_atau_ditolak_partner(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();

        $batal = $this->callAs('POST', $this->url($circle->id), $odi, $this->payload())->json('consequence.id');
        $this->callAs('DELETE', "/api/consequences/{$batal}", $odi)->assertNoContent();

        $tolak = $this->callAs('POST', $this->url($circle->id), $odi, $this->payload())->json('consequence.id');
        $this->callAs('DELETE', "/api/consequences/{$tolak}", $anjal)->assertNoContent();

        $this->assertDatabaseCount('consequences', 0);
    }

    public function test_item_yang_sudah_disetujui_hanya_dinonaktifkan_pengusulnya_tidak_dihapus(): void
    {
        [$odi, $anjal, $circle] = $this->makePair();
        $item = $this->makeConsequence($circle, 'punishment'); // diusulkan Odi, disetujui Anjal

        // Partner tidak boleh menyingkirkan usulan orang lain yang sudah sah (mencegah akal-akalan sebelum spin).
        $this->callAs('DELETE', "/api/consequences/{$item->id}", $anjal)->assertForbidden();
        $this->assertDatabaseHas('consequences', ['id' => $item->id, 'is_active' => true]);

        $this->callAs('DELETE', "/api/consequences/{$item->id}", $odi)->assertNoContent();
        // Barisnya tetap ada (riwayat evaluasi menunjuk ke sini), hanya nonaktif.
        $this->assertDatabaseHas('consequences', ['id' => $item->id, 'is_active' => false]);

        // Ulangi: idempoten.
        $this->callAs('DELETE', "/api/consequences/{$item->id}", $odi)->assertNoContent();

        // Nonaktif tidak muncul di daftar dan tidak bisa disetujui lagi.
        $this->callAs('GET', $this->url($circle->id), $odi)->assertJsonCount(0, 'consequences');
        $this->callAs('POST', "/api/consequences/{$item->id}/approve", $anjal)->assertNotFound();
    }

    public function test_batas_jumlah_item_per_sesi(): void
    {
        config(['habit.max_consequences_per_circle' => 2]);
        [$odi, , $circle] = $this->makePair();

        $this->callAs('POST', $this->url($circle->id), $odi, $this->payload())->assertCreated();
        $this->callAs('POST', $this->url($circle->id), $odi, $this->payload(['type' => 'reward']))->assertCreated();
        $this->callAs('POST', $this->url($circle->id), $odi, $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('circle');
    }
}
