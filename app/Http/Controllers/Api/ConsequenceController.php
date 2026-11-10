<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreConsequenceRequest;
use App\Http\Resources\ConsequenceResource;
use App\Models\Consequence;
use App\Services\ConsequenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ConsequenceController extends Controller
{
    public function __construct(private readonly ConsequenceService $consequences)
    {
    }

    public function index(Request $request, int $circle): JsonResponse
    {
        $items = $this->consequences->list($request->user(), $circle);

        return response()->json([
            'consequences' => $items->map(fn (Consequence $item) => ConsequenceResource::make($item)->resolve())->all(),
        ]);
    }

    public function store(StoreConsequenceRequest $request, int $circle): JsonResponse
    {
        $item = $this->consequences->create($request->user(), $circle, $request->validated());

        return response()->json(['consequence' => ConsequenceResource::make($item)->resolve()], 201);
    }

    public function approve(Request $request, int $consequence): JsonResponse
    {
        $item = $this->consequences->approve($request->user(), $consequence);

        return response()->json(['consequence' => ConsequenceResource::make($item)->resolve()]);
    }

    public function destroy(Request $request, int $consequence): Response
    {
        $this->consequences->remove($request->user(), $consequence);

        return response()->noContent();
    }
}
