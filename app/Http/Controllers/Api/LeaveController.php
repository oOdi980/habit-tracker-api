<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreLeaveRequest;
use App\Http\Resources\LeaveResource;
use App\Models\HabitLeave;
use App\Services\LeaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LeaveController extends Controller
{
    public function __construct(private readonly LeaveService $leaves)
    {
    }

    public function index(Request $request, int $circle): JsonResponse
    {
        $result = $this->leaves->list($request->user(), $circle);

        return response()->json([
            'week_start' => $result['week_start'],
            'next_week_start' => $result['next_week_start'],
            'quota' => $result['quota'],
            'leaves' => $result['leaves']->map(fn (HabitLeave $leave) => LeaveResource::make($leave)->resolve())->all(),
        ]);
    }

    public function store(StoreLeaveRequest $request, int $habit): JsonResponse
    {
        $leave = $this->leaves->request($request->user(), $habit, $request->validated('week'));

        return response()->json(['leave' => LeaveResource::make($leave)->resolve()], 201);
    }

    public function approve(Request $request, int $leave): JsonResponse
    {
        $approved = $this->leaves->approve($request->user(), $leave);

        return response()->json(['leave' => LeaveResource::make($approved)->resolve()]);
    }

    public function destroy(Request $request, int $leave): Response
    {
        $this->leaves->cancel($request->user(), $leave);

        return response()->noContent();
    }
}
