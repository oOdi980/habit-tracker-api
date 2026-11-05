<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreCircleRequest;
use App\Http\Resources\CircleResource;
use App\Services\CircleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CircleController extends Controller
{
    public function __construct(private readonly CircleService $circles)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'circles' => $this->circles->listFor($request->user())
                ->map(fn ($circle) => CircleResource::make($circle)->resolve())
                ->all(),
        ]);
    }

    public function store(StoreCircleRequest $request): JsonResponse
    {
        $circle = $this->circles->create(
            $request->user(),
            $request->validated('name'),
            $request->validated('timezone'),
        );

        return response()->json(['circle' => CircleResource::make($circle)->resolve()], 201);
    }

    public function show(Request $request, int $circle): JsonResponse
    {
        return response()->json([
            'circle' => CircleResource::make($this->circles->findFor($request->user(), $circle))->resolve(),
        ]);
    }
}
