<?php

namespace App\Models;

use App\Enums\CompletionRule;
use App\Enums\HabitScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Habit extends Model
{
    protected $fillable = [
        'circle_id', 'owner_id', 'created_by', 'title', 'scope', 'completion_rule',
        'target_per_week', 'accepted_at', 'starts_on', 'ends_on', 'archived_at',
    ];

    // starts_on / ends_on sengaja TIDAK di-cast: kolom DATE dibiarkan string 'Y-m-d'
    // supaya tidak ada pergeseran zona waktu lewat Carbon.
    protected function casts(): array
    {
        return [
            'scope' => HabitScope::class,
            'completion_rule' => CompletionRule::class,
            'target_per_week' => 'integer',
            'accepted_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class);
    }

    /** Null untuk habit bersama. */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(HabitLog::class);
    }

    public function weeklySummaries(): HasMany
    {
        return $this->hasMany(HabitWeeklySummary::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }
}
