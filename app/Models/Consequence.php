<?php

namespace App\Models;

use App\Enums\ConsequenceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Consequence extends Model
{
    protected $fillable = ['circle_id', 'type', 'title', 'description', 'is_active'];

    protected function casts(): array
    {
        return [
            'type' => ConsequenceType::class,
            'is_active' => 'boolean',
        ];
    }

    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class);
    }
}
