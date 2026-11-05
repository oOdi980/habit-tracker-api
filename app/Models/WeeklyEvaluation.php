<?php

namespace App\Models;

use App\Enums\EvaluationResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeeklyEvaluation extends Model
{
    protected $fillable = [
        'circle_id', 'user_id', 'week_start', 'habits_total', 'habits_achieved', 'result',
        'consequence_id', 'fulfilled_at', 'fulfilled_by', 'evaluated_at',
    ];

    protected function casts(): array
    {
        return [
            'habits_total' => 'integer',
            'habits_achieved' => 'integer',
            'result' => EvaluationResult::class,
            'fulfilled_at' => 'datetime',
            'evaluated_at' => 'datetime',
        ];
    }

    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function consequence(): BelongsTo
    {
        return $this->belongsTo(Consequence::class);
    }

    /** Partner yang mengonfirmasi punishment selesai. */
    public function fulfiller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fulfilled_by');
    }
}
