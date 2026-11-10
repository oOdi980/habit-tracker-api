<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HabitLeave extends Model
{
    protected $fillable = [
        'circle_id', 'habit_id', 'week_start', 'quota_month',
        'requested_by', 'approved_by', 'approved_at',
    ];

    // week_start dan quota_month sengaja TIDAK di-cast: tetap string 'Y-m-d' (lihat Habit).
    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
        ];
    }

    public function habit(): BelongsTo
    {
        return $this->belongsTo(Habit::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
