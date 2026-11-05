<?php

namespace App\Services;

use App\Models\Circle;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuthService
{
    private const REGISTER_LOCK = 'habit:register';

    public function __construct(private readonly CircleService $circles)
    {
    }

    /**
     * Daftar akun. Aturan:
     *  - Akun PERTAMA (tabel users masih kosong) boleh daftar tanpa kode.
     *  - Akun berikutnya WAJIB membawa kode undangan, dan langsung masuk ke sesinya.
     * User + keanggotaan + penghangusan kode dibuat dalam SATU transaksi: kode salah berarti
     * tidak ada akun yatim yang tertinggal.
     *
     * @param  array{name: string, email: string, password: string, invite_code?: ?string, device_name?: ?string}  $data
     * @return array{0: User, 1: ?Circle, 2: string} [user, sesi (jika lewat undangan), token]
     */
    public function register(array $data): array
    {
        try {
            [$user, $circle] = DB::transaction(function () use ($data) {
                // Serialisasi semua pendaftaran. Tanpa ini, dua orang yang daftar bersamaan di
                // server kosong sama-sama melihat "belum ada user" dan sama-sama lolos tanpa kode.
                // Kunci advisory dilepas otomatis saat transaksi selesai.
                if (DB::getDriverName() === 'pgsql') {
                    DB::select('select pg_advisory_xact_lock(hashtext(?))', [self::REGISTER_LOCK]);
                }

                $inviteCode = $data['invite_code'] ?? null;

                if (User::query()->exists() && blank($inviteCode)) {
                    throw ValidationException::withMessages([
                        'invite_code' => 'Pendaftaran butuh kode undangan dari anggota sesi.',
                    ]);
                }

                $user = User::create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => $data['password'], // di-hash otomatis oleh cast 'hashed'
                ]);

                $circle = filled($inviteCode)
                    ? $this->circles->consumeInvite($user, $inviteCode, 'invite_code')
                    : null;

                return [$user, $circle];
            });
        } catch (UniqueConstraintViolationException) {
            // Balapan langka: dua pendaftaran email sama lolos validasi bersamaan.
            throw ValidationException::withMessages(['email' => 'Email sudah terdaftar.']);
        }

        return [$user, $circle, $this->issueToken($user, $data['device_name'] ?? 'unknown')];
    }

    /** Buat token baru dan buang token terlama bila melebihi batas per user. */
    public function issueToken(User $user, string $deviceName): string
    {
        $plain = $user->createToken($deviceName)->plainTextToken;

        $overflow = $user->tokens()
            ->orderByDesc('id')
            ->skip((int) config('habit.max_tokens_per_user'))
            ->take(PHP_INT_MAX)
            ->pluck('id');

        if ($overflow->isNotEmpty()) {
            $user->tokens()->whereIn('id', $overflow)->delete();
        }

        return $plain;
    }
}
