<?php

namespace App\Models;

use App\Enums\SummaryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HabitWeeklySummary extends Model
{
    protected $fillable = [
        'habit_id', 'user_id', 'week_start', 'target_snapshot', 'done_count', 'status', 'evaluated_at',
    ];

    protected function casts(): array
    {
        return [
            'target_snapshot' => 'integer',
            'done_count' => 'integer',
            'status' => SummaryStatus::class,
            'evaluated_at' => 'datetime',
        ];
    }

    public function habit(): BelongsTo
    {
        return $this->belongsTo(Habit::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
