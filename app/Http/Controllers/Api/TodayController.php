<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TodayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TodayController extends Controller
{
    public function __construct(private readonly TodayService $today)
    {
    }

    public function show(Request $request, int $circle): JsonResponse
    {
        return response()->json($this->today->board($request->user(), $circle));
    }
}
