<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CircleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CircleInviteController extends Controller
{
    public function __construct(private readonly CircleService $circles)
    {
    }

    /** Buat kode undangan baru (kode lama yang masih aktif otomatis hangus). */
    public function store(Request $request, int $circle): JsonResponse
    {
        $invite = $this->circles->createInvite($request->user(), $circle);

        return response()->json([
            'circle_id' => $invite->circle_id,
            'code' => $invite->code,
            'expires_at' => $invite->expires_at->toIso8601String(),
        ], 201);
    }
}
