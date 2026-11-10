<?php

namespace App\Services;

use App\Models\Circle;
use App\Models\Consequence;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Daftar reward/punishment sesi. Aturannya:
 *  - Siapa pun anggota boleh mengusulkan; item baru BELUM aktif sampai partner menyetujui.
 *  - Yang mengusulkan tidak boleh menyetujui sendiri (dijaga juga oleh CHECK di database).
 *  - Item yang sudah disetujui baru bisa ikut spin untuk minggu yang dimulai SETELAH persetujuan
 *    (lihat EvaluationService::evaluateWeek), jadi tidak bisa diselipkan menjelang evaluasi.
 *  - Item yang sudah disetujui tidak pernah dihapus, hanya dinonaktifkan (is_active = false),
 *    karena riwayat evaluasi menunjuk ke item itu.
 */
class ConsequenceService
{
    /** 404 untuk sesi yang tidak ada ATAU bukan milik user. */
    private function memberCircle(User $user, int $circleId): Circle
    {
        return $user->circles()->whereKey($circleId)->firstOrFail();
    }

    /** Item aktif sesi (yang menunggu persetujuan ikut tampil). */
    public function list(User $user, int $circleId): Collection
    {
        $circle = $this->memberCircle($user, $circleId);

        return Consequence::query()
            ->where('circle_id', $circle->id)
            ->where('is_active', true)
            ->orderBy('type')
            ->orderBy('id')
            ->get();
    }

    /** @param array{type: string, title: string, description?: ?string} $data */
    public function create(User $user, int $circleId, array $data): Consequence
    {
        return DB::transaction(function () use ($user, $circleId, $data) {
            $circle = $this->memberCircle($user, $circleId);

            // Kunci sesi: dua request bersamaan tidak bisa sama-sama lolos cek batas jumlah.
            Circle::query()->whereKey($circle->id)->lockForUpdate()->first();

            $active = Consequence::query()->where('circle_id', $circle->id)->where('is_active', true)->count();
            if ($active >= (int) config('habit.max_consequences_per_circle')) {
                throw ValidationException::withMessages(['circle' => 'Daftar reward/punishment sudah mencapai batas.']);
            }

            return Consequence::create([
                'circle_id' => $circle->id,
                'type' => $data['type'],
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'is_active' => true,
                'created_by' => $user->id,
                // approved_at/approved_by sengaja kosong: menunggu partner.
            ]);
        });
    }

    /** Partner (bukan pengusul) menyetujui. Idempoten. */
    public function approve(User $user, int $consequenceId): Consequence
    {
        return DB::transaction(function () use ($user, $consequenceId) {
            $consequence = $this->lockedMemberItem($user, $consequenceId);

            if (! $consequence->is_active) {
                abort(404);
            }

            if ((int) $consequence->created_by === $user->id) {
                abort(403, 'Item ini kamu usulkan sendiri; partner yang harus menyetujuinya.');
            }

            // Sudah disetujui: jangan timpa waktu persetujuan pertama (itu yang dipakai aturan spin).
            if ($consequence->approved_at === null) {
                $consequence->forceFill(['approved_at' => now(), 'approved_by' => $user->id])->save();
            }

            return $consequence;
        });
    }

    /**
     * Hapus/nonaktifkan.
     *   - belum disetujui : kedua anggota boleh (pengusul membatalkan, partner menolak); dihapus permanen
     *                       (belum mungkin dipakai evaluasi).
     *   - sudah disetujui : hanya pengusulnya; dinonaktifkan, tidak dihapus.
     * Idempoten: item yang sudah nonaktif dianggap beres.
     */
    public function remove(User $user, int $consequenceId): void
    {
        DB::transaction(function () use ($user, $consequenceId) {
            $consequence = $this->lockedMemberItem($user, $consequenceId);

            if (! $consequence->is_active) {
                return;
            }

            if ($consequence->approved_at === null) {
                $consequence->delete();

                return;
            }

            if ((int) $consequence->created_by !== $user->id) {
                abort(403, 'Item yang sudah disetujui hanya bisa dinonaktifkan oleh pengusulnya.');
            }

            $consequence->forceFill(['is_active' => false])->save();
        });
    }

    /** Item milik sesi user, dikunci. 404 bila bukan anggota. Urutan kunci: sesi dulu, baru item. */
    private function lockedMemberItem(User $user, int $consequenceId): Consequence
    {
        $circleId = Consequence::query()
            ->whereKey($consequenceId)
            ->whereIn('circle_id', DB::table('circle_members')->where('user_id', $user->id)->select('circle_id'))
            ->value('circle_id');

        if ($circleId === null) {
            abort(404);
        }

        Circle::query()->whereKey($circleId)->lockForUpdate()->first();

        return Consequence::query()->whereKey($consequenceId)->lockForUpdate()->firstOrFail();
    }
}
