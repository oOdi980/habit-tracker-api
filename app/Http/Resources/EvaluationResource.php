<?php

namespace App\Http\Resources;

use App\Enums\EvaluationResult;
use App\Models\WeeklyEvaluation;
use App\Support\CircleClock;
use App\Support\WeekScore;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pakai dengan relasi `consequence` sudah dimuat (hindari N+1).
 *
 * @property WeeklyEvaluation $resource
 */
class EvaluationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $evaluation = $this->resource;
        $viewerId = (int) $request->user()?->id;
        $consequence = $evaluation->consequence;
        $isPunishment = $evaluation->result === EvaluationResult::Punishment;
        $fulfilled = $evaluation->fulfilled_at !== null;

        return [
            'id' => $evaluation->id,
            'week_start' => $evaluation->week_start,
            'week_end' => CircleClock::addDays($evaluation->week_start, 6),
            'user_id' => $evaluation->user_id,
            'habits_total' => $evaluation->habits_total,
            'habits_achieved' => $evaluation->habits_achieved,
            'percent' => WeekScore::percent($evaluation->habits_total, $evaluation->habits_achieved),
            'result' => $evaluation->result->value,
            // Null bila hasilnya netral, atau daftarnya kosong saat minggu itu dievaluasi.
            'consequence' => $consequence === null ? null : [
                'id' => $consequence->id,
                'type' => $consequence->type->value,
                'title' => $consequence->title,
                'description' => $consequence->description,
            ],
            'fulfilled' => $fulfilled,
            'fulfilled_at' => $evaluation->fulfilled_at?->toIso8601String(),
            'fulfilled_by' => $evaluation->fulfilled_by,
            // Hanya PARTNER dari yang kena punishment yang boleh mengonfirmasi.
            'can_confirm' => $isPunishment && ! $fulfilled && (int) $evaluation->user_id !== $viewerId,
        ];
    }
}
