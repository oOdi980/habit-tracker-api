<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Circle extends Model
{
    protected $fillable = ['name', 'timezone', 'reward_min_ratio', 'punishment_below_ratio'];

    protected function casts(): array
    {
        return [
            'reward_min_ratio' => 'integer',
            'punishment_below_ratio' => 'integer',
        ];
    }

    /** Anggota sesi (aplikasi membatasi tepat 2). */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'circle_members')->withPivot('joined_at');
    }

    public function invites(): HasMany
    {
        return $this->hasMany(CircleInvite::class);
    }

    public function habits(): HasMany
    {
        return $this->hasMany(Habit::class);
    }

    public function consequences(): HasMany
    {
        return $this->hasMany(Consequence::class);
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(WeeklyEvaluation::class);
    }
}
