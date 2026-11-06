<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreHabitRequest;
use App\Http\Requests\Api\UpdateHabitRequest;
use App\Http\Resources\HabitResource;
use App\Models\Habit;
use App\Services\HabitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class HabitController extends Controller
{
    public function __construct(private readonly HabitService $habits)
    {
    }

    public function index(Request $request, int $circle): JsonResponse
    {
        $result = $this->habits->list($request->user(), $circle);

        return response()->json([
            'date' => $result['today'],
            'habits' => $result['habits']->map(fn (Habit $habit) => HabitResource::make($habit)->resolve())->all(),
        ]);
    }

    public function store(StoreHabitRequest $request, int $circle): JsonResponse
    {
        $habit = $this->habits->create($request->user(), $circle, $request->validated());

        return response()->json(['habit' => HabitResource::make($habit)->resolve()], 201);
    }

    public function update(UpdateHabitRequest $request, int $habit): JsonResponse
    {
        $updated = $this->habits->update($request->user(), $habit, $request->validated());

        return response()->json(['habit' => HabitResource::make($updated)->resolve()]);
    }

    /**
     * 204 bila habit dihapus permanen (belum mulai / menolak / membatalkan);
     * 200 + habit (status "ending") bila diarsipkan dan masih dihitung sampai Minggu ini.
     */
    public function destroy(Request $request, int $habit): JsonResponse|Response
    {
        $archived = $this->habits->archive($request->user(), $habit);

        if ($archived === null) {
            return response()->noContent();
        }

        return response()->json(['habit' => HabitResource::make($archived)->resolve()]);
    }

    public function accept(Request $request, int $habit): JsonResponse
    {
        $accepted = $this->habits->accept($request->user(), $habit);

        return response()->json(['habit' => HabitResource::make($accepted)->resolve()]);
    }

    public function restore(Request $request, int $habit): JsonResponse
    {
        $restored = $this->habits->restore($request->user(), $habit);

        return response()->json(['habit' => HabitResource::make($restored)->resolve()]);
    }
}
