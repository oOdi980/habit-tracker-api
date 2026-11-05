<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Semua endpoint API harus membalas JSON, termasuk error. Tanpa ini, klien yang lupa
// mengirim "Accept: application/json" akan dapat redirect HTML ke halaman login (route
// "login" tidak ada) alih-alih 401 yang rapi.
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
