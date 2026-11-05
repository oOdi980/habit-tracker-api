<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Umum: 120 permintaan/menit per user (atau per IP bila belum login).
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($this->actor($request)));

        RateLimiter::for('register', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip()),
            Limit::perHour(20)->by($request->ip()),
        ]);

        // Dua lapis: per kombinasi email+IP (hentikan tebak password satu akun) dan per IP
        // (hentikan satu IP yang menembak banyak email).
        RateLimiter::for('login', function (Request $request) {
            $email = $request->input('email');
            $email = is_string($email) ? Str::lower($email) : '';

            return [
                Limit::perMinute(5)->by($email.'|'.$request->ip()),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });

        // Kode undangan itu rahasia 8 karakter: batasi tebakan, per user dan per jam.
        RateLimiter::for('join', fn (Request $request) => [
            Limit::perMinute(5)->by($this->actor($request)),
            Limit::perHour(30)->by($this->actor($request)),
        ]);

        RateLimiter::for('invite', fn (Request $request) => Limit::perHour(10)->by($this->actor($request)));
    }

    private function actor(Request $request): string
    {
        return (string) ($request->user()?->id ?: $request->ip());
    }
}
