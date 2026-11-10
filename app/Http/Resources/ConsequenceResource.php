<?php

namespace App\Http\Resources;

use App\Models\Consequence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property Consequence $resource */
class ConsequenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $item = $this->resource;
        $viewerId = (int) $request->user()?->id;
        $approved = $item->approved_at !== null;

        return [
            'id' => $item->id,
            'circle_id' => $item->circle_id,
            'type' => $item->type->value,
            'title' => $item->title,
            'description' => $item->description,
            'created_by' => $item->created_by,
            'approved' => $approved,
            'approved_at' => $item->approved_at?->toIso8601String(),
            // Partner perlu menyetujui; pengusul tidak bisa menyetujui miliknya sendiri.
            'needs_my_approval' => ! $approved && (int) $item->created_by !== $viewerId,
        ];
    }
}
