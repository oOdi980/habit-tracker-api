<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\JoinCircleRequest;
use App\Http\Resources\CircleResource;
use App\Services\CircleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class CircleJoinController extends Controller
{
    public function __construct(private readonly CircleService $circles)
    {
    }

    /** User yang sudah punya akun bergabung ke sesi lain lewat kode undangan. */
    public function store(JoinCircleRequest $request): JsonResponse
    {
        $circle = DB::transaction(
            fn () => $this->circles->consumeInvite($request->user(), $request->validated('code'), 'code')
        );

        return response()->json(['circle' => CircleResource::make($circle)->resolve()]);
    }
}
