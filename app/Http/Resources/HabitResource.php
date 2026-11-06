<?php

namespace App\Http\Resources;

use App\Enums\HabitScope;
use App\Models\Habit;
use App\Support\CircleClock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wajib dipakai dengan relasi `circle` sudah terisi (zona waktu sesi dibutuhkan untuk tahap hidup).
 *
 * @property Habit $resource
 */
class HabitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $habit = $this->resource;
        $clock = CircleClock::forCircle($habit->circle);
        $today = $clock->today();
        $weekStart = $clock->weekStart();
        $viewerId = (int) $request->user()?->id;

        // Perubahan target yang tertahan dan baru berlaku di masa depan.
        $hasFuturePending = $habit->pending_target_from !== null && $habit->pending_target_from > $weekStart;

        return [
            'id' => $habit->id,
            'circle_id' => $habit->circle_id,
            'title' => $habit->title,
            'scope' => $habit->scope->value,
            'completion_rule' => $habit->completion_rule->value,
            'owner_id' => $habit->owner_id,
            'created_by' => $habit->created_by,
            'status' => $habit->lifecycle($today),
            // Target yang berlaku untuk minggu ini (sudah memperhitungkan perubahan yang jatuh tempo).
            'target_per_week' => $habit->targetFor($weekStart),
            'next_target' => $hasFuturePending
                ? ['target' => (int) $habit->pending_target_per_week, 'from' => $habit->pending_target_from]
                : null,
            'starts_on' => $habit->starts_on,
            'ends_on' => $habit->ends_on,
            'accepted' => $habit->accepted_at !== null,
            // Habit bersama yang menunggu persetujuan USER YANG MELIHAT (bukan pembuatnya).
            'needs_my_approval' => $habit->scope === HabitScope::Shared
                && $habit->starts_on === null
                && (int) $habit->created_by !== $viewerId,
        ];
    }
}
