<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HabitLog extends Model
{
    // Tabel tidak punya created_at/updated_at; waktunya ada di completed_at.
    public $timestamps = false;

    protected $fillable = ['habit_id', 'user_id', 'log_date', 'completed_at', 'client_ts'];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'client_ts' => 'datetime',
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
