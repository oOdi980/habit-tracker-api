<?php

namespace Tests;

use App\Models\Circle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
