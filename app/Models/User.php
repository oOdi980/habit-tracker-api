<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed', // otomatis di-hash saat di-set
        ];
    }

    /** Semua sesi yang diikuti user ini (bisa lebih dari satu). */
    public function circles(): BelongsToMany
    {
        return $this->belongsToMany(Circle::class, 'circle_members')->withPivot('joined_at');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /** Habit personal milik user ini (habit bersama tidak punya owner). */
    public function habits(): HasMany
    {
        return $this->hasMany(Habit::class, 'owner_id');
    }

    public function habitLogs(): HasMany
    {
        return $this->hasMany(HabitLog::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(WeeklyEvaluation::class);
    }
}
