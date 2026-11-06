<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CheckInController;
use App\Http\Controllers\Api\CircleController;
use App\Http\Controllers\Api\CircleInviteController;
use App\Http\Controllers\Api\CircleJoinController;
use App\Http\Controllers\Api\HabitController;
use App\Http\Controllers\Api\TodayController;
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
        // Sesi
        Route::get('circles', [CircleController::class, 'index']);
        Route::post('circles', [CircleController::class, 'store']);
        Route::post('circles/join', [CircleJoinController::class, 'store'])->middleware('throttle:join');
        Route::get('circles/{circle}', [CircleController::class, 'show'])->whereNumber('circle');
        Route::post('circles/{circle}/invites', [CircleInviteController::class, 'store'])
            ->whereNumber('circle')
            ->middleware('throttle:invite');

        // Habit milik sebuah sesi
        Route::get('circles/{circle}/habits', [HabitController::class, 'index'])->whereNumber('circle');
        Route::post('circles/{circle}/habits', [HabitController::class, 'store'])->whereNumber('circle');
        Route::get('circles/{circle}/today', [TodayController::class, 'show'])->whereNumber('circle');

        // Habit tunggal
        Route::patch('habits/{habit}', [HabitController::class, 'update'])->whereNumber('habit');
        Route::delete('habits/{habit}', [HabitController::class, 'destroy'])->whereNumber('habit');
        Route::post('habits/{habit}/accept', [HabitController::class, 'accept'])->whereNumber('habit');
        Route::post('habits/{habit}/restore', [HabitController::class, 'restore'])->whereNumber('habit');

        // Check-in
        Route::post('habits/{habit}/check-in', [CheckInController::class, 'store'])->whereNumber('habit');
        Route::delete('habits/{habit}/check-in', [CheckInController::class, 'destroy'])->whereNumber('habit');
    });
});
