<?php

namespace App\Http\Resources;

use App\Models\HabitLeave;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property HabitLeave $resource */
class LeaveResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $leave = $this->resource;
        $viewerId = (int) $request->user()?->id;
        $approved = $leave->approved_at !== null;

        return [
            'id' => $leave->id,
            'habit_id' => $leave->habit_id,
            'week_start' => $leave->week_start,
            'requested_by' => $leave->requested_by,
            'approved' => $approved,
            'approved_at' => $leave->approved_at?->toIso8601String(),
            'needs_my_approval' => ! $approved && (int) $leave->requested_by !== $viewerId,
        ];
    }
}
