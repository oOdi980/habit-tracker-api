<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HabitTaskEntry extends Model
{
    // Tabel hanya punya created_at (default database); tidak ada updated_at karena entri
    // tidak pernah diedit, hanya dihapus lalu diganti.
    public $timestamps = false;

    protected $fillable = [
        'task_id', 'habit_id', 'user_id', 'log_date', 'amount', 'weight_g', 'source', 'client_key',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'weight_g' => 'integer',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(HabitTask::class, 'task_id');
    }
}
