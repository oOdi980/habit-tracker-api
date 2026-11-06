<?php

namespace Tests;

use App\Models\Circle;
use App\Models\Habit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

// Dasar untuk tes API: database di-refresh, plus beberapa pembantu supaya tes pendek dan jelas.
abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }

    /** Sesi dengan anggota yang diberikan (tanpa lewat API, untuk menyiapkan keadaan awal). */
    protected function makeCircle(User ...$members): Circle
    {
        $circle = Circle::create(['name' => 'Sesi tes', 'timezone' => 'Asia/Jakarta']);

        foreach ($members as $member) {
            $circle->members()->attach($member->id);
        }

        return $circle;
    }

    /** Odi + Anjal + sesi berdua. Odi dibuat lebih dulu, jadi id-nya lebih kecil. */
    protected function makePair(): array
    {
        $odi = $this->makeUser(['name' => 'Odi']);
        $anjal = $this->makeUser(['name' => 'Anjal']);

        return [$odi, $anjal, $this->makeCircle($odi, $anjal)];
    }

    /**
     * Majukan/mundurkan jam aplikasi ke waktu dinding LOKAL tertentu (default WIB).
     * Contoh: at('2026-11-04 23:59:59'). Kalender acuan: 2026-11-02 adalah hari Senin.
     */
    protected function at(string $localTime, string $timezone = 'Asia/Jakarta'): void
    {
        $this->travelTo(Carbon::parse($localTime, $timezone));
    }

    /**
     * Habit langsung di database (tanpa lewat API) untuk menyiapkan keadaan awal.
     * $owner = null berarti habit BERSAMA (sudah disetujui, kecuali $attrs menimpanya).
     * Bawaan: mulai Senin 2026-11-09, target 3.
     */
    protected function makeHabit(Circle $circle, ?User $owner, array $attrs = []): Habit
    {
        $shared = $owner === null;
        $creator = $owner ?? $circle->members()->orderBy('users.id')->first();

        return Habit::create(array_merge([
            'circle_id' => $circle->id,
            'owner_id' => $owner?->id,
            'created_by' => $creator->id,
            'title' => 'Habit tes',
            'scope' => $shared ? 'shared' : 'personal',
            'completion_rule' => $shared ? 'together' : 'individual',
            'target_per_week' => 3,
            'starts_on' => '2026-11-09',
        ], $shared ? ['accepted_at' => now()] : [], $attrs));
    }

    /**
     * Panggil API sebagai: User (dibuatkan token baru), string (token mentah), atau null (anonim).
     *
     * @param  User|string|null  $as
     */
    protected function callAs(string $method, string $uri, User|string|null $as = null, array $data = []): TestResponse
    {
        // Guard Sanctum menyimpan user di memori selama aplikasi tes hidup. Tanpa ini, request
        // kedua dalam satu tes bisa "menumpang" identitas request pertama.
        $this->app['auth']->forgetGuards();

        $headers = [];
        if ($as instanceof User) {
            $headers['Authorization'] = 'Bearer '.$as->createToken('tes')->plainTextToken;
        } elseif (is_string($as)) {
            $headers['Authorization'] = 'Bearer '.$as;
        }

        return $this->json($method, $uri, $data, $headers);
    }

    /** Buat kode undangan lewat API (sekaligus menguji endpoint-nya). */
    protected function newInviteCode(User $owner, Circle $circle): string
    {
        return $this->callAs('POST', "/api/circles/{$circle->id}/invites", $owner)
            ->assertCreated()
            ->json('code');
    }
}
