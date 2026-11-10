<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ReportRequest;
use App\Services\PeriodReportService;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function __construct(private readonly PeriodReportService $reports)
    {
    }

    public function show(ReportRequest $request, int $circle): JsonResponse
    {
        $data = $request->validated();

        return response()->json($this->reports->report($request->user(), $circle, $data['period'], $data['value'] ?? null));
    }
}
