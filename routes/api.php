<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CircleController;
use App\Http\Controllers\Api\CircleInviteController;
use App\Http\Controllers\Api\CircleJoinController;
use App\Http\Middleware\ForceJsonResponse;
use Illuminate\Support\Facades\Route;

// Awalan "/api" ditambahkan otomatis oleh bootstrap/app.php.
// Nama limiter (throttle:xxx) didefinisikan di AppServiceProvider.
Route::middleware(ForceJsonResponse::class)->group(function () {

    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:register');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');

        Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('logout', [AuthController::class, 'logout']);
            Route::post('password', [AuthController::class, 'changePassword']);
        });
    });

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::get('circles', [CircleController::class, 'index']);
        Route::post('circles', [CircleController::class, 'store']);
        Route::post('circles/join', [CircleJoinController::class, 'store'])->middleware('throttle:join');
        Route::get('circles/{circle}', [CircleController::class, 'show'])->whereNumber('circle');
        Route::post('circles/{circle}/invites', [CircleInviteController::class, 'store'])
            ->whereNumber('circle')
            ->middleware('throttle:invite');
    });
});
