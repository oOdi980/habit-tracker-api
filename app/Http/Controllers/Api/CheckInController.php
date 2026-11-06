<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CheckInRequest;
use App\Services\CheckInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckInController extends Controller
{
    public function __construct(private readonly CheckInService $checkIns)
    {
    }

    /** 201 bila check-in baru tercatat; 200 bila sudah tercatat sebelumnya (idempoten). */
    public function store(CheckInRequest $request, int $habit): JsonResponse
    {
        $result = $this->checkIns->checkIn($request->user(), $habit, $request->validated('client_ts'));

        return response()->json(['check_in' => $result], $result['created'] ? 201 : 200);
    }

    /** Batalkan check-in hari ini. Selalu 200; "deleted" menunjukkan ada tidaknya yang dihapus. */
    public function destroy(Request $request, int $habit): JsonResponse
    {
        return response()->json(['check_in' => $this->checkIns->undo($request->user(), $habit)]);
    }
}
