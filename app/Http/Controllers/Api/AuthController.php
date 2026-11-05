<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ChangePasswordRequest;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Http\Resources\CircleResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
use App\Services\CircleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly CircleService $circles,
    ) {
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        [$user, $circle, $token] = $this->auth->register($request->validated());

        return response()->json([
            'token' => $token,
            'user' => UserResource::make($user)->resolve(),
            'circle' => $circle ? CircleResource::make($circle)->resolve() : null,
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        // Satu pesan untuk "email tidak ada" dan "password salah".
        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            return response()->json(['message' => 'Email atau password salah.'], 401);
        }

        return response()->json([
            'token' => $this->auth->issueToken($user, $request->validated('device_name') ?? 'unknown'),
            'user' => UserResource::make($user)->resolve(),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => UserResource::make($user)->resolve(),
            'circles' => $this->circles->listFor($user)
                ->map(fn ($circle) => CircleResource::make($circle)->resolve())
                ->all(),
        ]);
    }

    /** Keluar dari perangkat INI saja (token lain tetap hidup). */
    public function logout(Request $request): Response
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->noContent();
    }

    /** Ganti password: wajib password lama; perangkat lain otomatis dikeluarkan. */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check($request->validated('current_password'), $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Password lama salah.']);
        }

        $user->update(['password' => $request->validated('password')]);

        // Token perangkat ini dipertahankan, sisanya dicabut. Kalau password dicuri/ditebak,
        // sesi perangkat lain (milik penyusup) ikut mati.
        $current = $user->currentAccessToken();
        $keepId = $current instanceof PersonalAccessToken ? $current->id : null;

        $user->tokens()
            ->when($keepId, fn ($query) => $query->where('id', '!=', $keepId))
            ->delete();

        return response()->json(['message' => 'Password diubah. Perangkat lain sudah dikeluarkan.']);
    }
}
