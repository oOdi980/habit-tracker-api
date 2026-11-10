<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\EvaluationIndexRequest;
use App\Http\Resources\EvaluationResource;
use App\Models\WeeklyEvaluation;
use App\Services\EvaluationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EvaluationController extends Controller
{
    private const DEFAULT_WEEKS = 8;

    public function __construct(private readonly EvaluationService $evaluations)
    {
    }

    public function index(EvaluationIndexRequest $request, int $circle): JsonResponse
    {
        $result = $this->evaluations->list(
            $request->user(),
            $circle,
            (int) ($request->validated('limit') ?? self::DEFAULT_WEEKS),
        );

        $format = fn (WeeklyEvaluation $e) => EvaluationResource::make($e)->resolve();

        return response()->json([
            'reward_min_percent' => $result['circle']->reward_min_ratio,
            'punishment_below_percent' => $result['circle']->punishment_below_ratio,
            'evaluations' => $result['evaluations']->map($format)->all(),
            // Punishment yang belum dikonfirmasi partner; menumpuk lintas minggu.
            'pending_punishments' => $result['pending_punishments']->map($format)->all(),
        ]);
    }

    public function fulfill(Request $request, int $evaluation): JsonResponse
    {
        $fulfilled = $this->evaluations->fulfill($request->user(), $evaluation);

        return response()->json(['evaluation' => EvaluationResource::make($fulfilled)->resolve()]);
    }
}
