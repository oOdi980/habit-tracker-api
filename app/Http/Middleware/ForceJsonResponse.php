<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Memaksa Accept: application/json untuk route API, supaya error validasi (422) selalu
// berbentuk JSON walaupun klien lupa mengirim header Accept.
// CATATAN: ini BUKAN yang mencegah redirect ke halaman login pada 401. Middleware auth
// berjalan lebih dulu daripada middleware route, jadi penanganan 401 ada di bootstrap/app.php
// (redirectGuestsTo(fn () => null) + shouldRenderJsonWhen untuk path api/*).
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
