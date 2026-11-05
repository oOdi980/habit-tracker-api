<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CircleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'timezone' => $this->timezone,
            'reward_min_ratio' => $this->reward_min_ratio,
            'punishment_below_ratio' => $this->punishment_below_ratio,
            // Hanya id + nama anggota (tanpa email/data lain). Urutan diurutkan per id supaya
            // keluarannya stabil; klien tidak boleh bergantung pada urutan tertentu.
            'members' => $this->whenLoaded('members', fn () => $this->members
                ->sortBy('id')
                ->map(fn ($member) => ['id' => $member->id, 'name' => $member->name])
                ->values()),
            'is_full' => $this->whenLoaded('members', fn () => $this->members->count() >= (int) config('habit.max_circle_members')),
        ];
    }
}
