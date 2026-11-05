<?php

namespace App\Services;

use App\Models\Circle;
use App\Models\CircleInvite;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;

/**
 * Semua aturan sesi (circle) ada di sini: bikin sesi, bikin kode undangan, dan memakai kode.
 *
 * ATURAN KUNCI (hindari deadlock): selalu kunci baris `circles` LEBIH DULU, baru baris
 * `circle_invites`. Kalau satu jalur kunci invite dulu lalu circle, sementara jalur lain
 * sebaliknya, dua request bersamaan bisa saling menunggu selamanya.
 */
class CircleService
{
    private const GENERIC_INVITE_ERROR = 'Kode undangan tidak valid atau sudah kedaluwarsa.';

    /** Sesi milik user beserta anggotanya (satu query tambahan, bukan N+1). */
    public function listFor(User $user): Collection
    {
        return $user->circles()->with('members')->orderBy('circles.id')->get();
    }

    /** 404 untuk sesi yang tidak ada ATAU bukan milik user, jadi keberadaan sesi tidak bocor. */
    public function findFor(User $user, int $circleId): Circle
    {
        return $user->circles()->with('members')->whereKey($circleId)->firstOrFail();
    }

    public function create(User $user, string $name, ?string $timezone): Circle
    {
        return DB::transaction(function () use ($user, $name, $timezone) {
            // Kunci baris user supaya dua request bersamaan tidak sama-sama lolos cek batas.
            User::whereKey($user->id)->lockForUpdate()->first();
            $this->assertBelowCircleLimit($user, 'name');

            $circle = Circle::create([
                'name' => $name,
                'timezone' => $timezone ?: config('habit.default_timezone'),
            ]);
            $circle->members()->attach($user->id);

            // refresh(): ambil nilai default dari database (ambang 90/60) yang tidak ada di model baru.
            return $circle->refresh()->load('members');
        });
    }

    /**
     * Buat kode undangan baru. Kode lama yang masih aktif langsung dihanguskan, jadi hanya
     * ada satu kode aktif per sesi (permukaan tebak-tebakan lebih kecil, tidak ada kode nyasar).
     */
    public function createInvite(User $user, int $circleId): CircleInvite
    {
        return DB::transaction(function () use ($user, $circleId) {
            $isMember = DB::table('circle_members')
                ->where('circle_id', $circleId)
                ->where('user_id', $user->id)
                ->exists();

            if (! $isMember) {
                abort(404);
            }

            // Kunci sesi dulu (lihat aturan kunci di atas), baru urus undangannya.
            Circle::whereKey($circleId)->lockForUpdate()->firstOrFail();

            $memberCount = DB::table('circle_members')->where('circle_id', $circleId)->count();
            if ($memberCount >= (int) config('habit.max_circle_members')) {
                throw ValidationException::withMessages(['circle' => 'Sesi ini sudah penuh, tidak perlu kode undangan.']);
            }

            // subSecond(): pastikan SUDAH lewat (bukan "tepat sekarang") saat dicek di consumeInvite.
            CircleInvite::where('circle_id', $circleId)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->update(['expires_at' => now()->subSecond()]);

            return $this->createInviteRow($circleId, $user->id);
        });
    }

    /**
     * Pakai kode undangan: user masuk ke sesi. WAJIB dipanggil di dalam transaksi (dipakai oleh
     * endpoint join dan oleh pendaftaran), karena kuncinya baru bermakna sampai transaksi selesai.
     *
     * @throws ValidationException pesan di bawah $field (nama field input di klien)
     */
    public function consumeInvite(User $user, string $rawCode, string $field): Circle
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('consumeInvite harus dipanggil di dalam transaksi.');
        }

        $code = $this->normalizeCode($rawCode);

        if (strlen($code) !== (int) config('habit.invite.length')) {
            $this->reject($field, self::GENERIC_INVITE_ERROR);
        }

        // 1) Intip tanpa kunci, hanya untuk tahu sesi mana yang harus dikunci DULU.
        $peek = CircleInvite::where('code', $code)->first(['id', 'circle_id']);
        if (! $peek) {
            $this->reject($field, self::GENERIC_INVITE_ERROR);
        }

        // 2) Kunci sesi, lalu kunci undangan dan baca ulang statusnya setelah kunci didapat.
        //    Request lain yang memegang kunci sesi sama menunggu di sini, jadi cek "penuh"
        //    di bawah tidak bisa dilewati oleh dua orang yang masuk bersamaan.
        $circle = Circle::whereKey($peek->circle_id)->lockForUpdate()->first();
        $invite = CircleInvite::where('code', $code)->lockForUpdate()->first();

        // Satu pesan generik untuk semua kegagalan kode (tidak ada / dipakai / kedaluwarsa),
        // supaya penebak tidak bisa membedakan kode yang "pernah ada".
        if (! $circle || ! $invite || $invite->used_at !== null || $invite->expires_at->lte(now())) {
            $this->reject($field, self::GENERIC_INVITE_ERROR);
        }

        $alreadyMember = DB::table('circle_members')
            ->where('circle_id', $circle->id)
            ->where('user_id', $user->id)
            ->exists();
        if ($alreadyMember) {
            $this->reject($field, 'Kamu sudah jadi anggota sesi ini.');
        }

        $memberCount = DB::table('circle_members')->where('circle_id', $circle->id)->count();
        if ($memberCount >= (int) config('habit.max_circle_members')) {
            $this->reject($field, 'Sesi ini sudah penuh.');
        }

        $this->assertBelowCircleLimit($user, $field);

        $circle->members()->attach($user->id);
        $invite->forceFill(['used_by' => $user->id, 'used_at' => now()])->save();

        return $circle->load('members');
    }

    /** Huruf besar, buang spasi dan tanda hubung, supaya "abcd-efgh" sama dengan "ABCDEFGH". */
    private function normalizeCode(string $raw): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '');
    }

    private function createInviteRow(int $circleId, int $creatorId): CircleInvite
    {
        // Peluang tabrakan kode ~1 banding 10^12, tapi tetap ditangani: transaksi bersarang
        // = SAVEPOINT, jadi pelanggaran unique cukup membatalkan percobaan ini saja.
        // Tanpa savepoint, Postgres menandai seluruh transaksi luar sebagai gagal.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return DB::transaction(fn () => CircleInvite::create([
                    'circle_id' => $circleId,
                    'created_by' => $creatorId,
                    'code' => $this->generateCode(),
                    'expires_at' => now()->addHours((int) config('habit.invite.ttl_hours')),
                ]));
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        throw new RuntimeException('Gagal membuat kode undangan unik.');
    }

    private function generateCode(): string
    {
        $alphabet = (string) config('habit.invite.alphabet');
        $last = strlen($alphabet) - 1;
        $code = '';

        // random_int() memakai CSPRNG sistem (bukan rand/mt_rand yang bisa ditebak).
        for ($i = 0, $len = (int) config('habit.invite.length'); $i < $len; $i++) {
            $code .= $alphabet[random_int(0, $last)];
        }

        return $code;
    }

    private function assertBelowCircleLimit(User $user, string $field): void
    {
        $count = DB::table('circle_members')->where('user_id', $user->id)->count();

        if ($count >= (int) config('habit.max_circles_per_user')) {
            $this->reject($field, 'Kamu sudah mencapai batas jumlah sesi.');
        }
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
