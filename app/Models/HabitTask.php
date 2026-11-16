<?php

namespace App\Models;

use App\Enums\TaskMetric;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu tugas harian milik satu orang (mis. "hammer curl" >= 30 kali). Lihat migrasi untuk aturan
 * versi: target tidak pernah diubah di tempat, hanya ditutup dan diganti baris baru.
 */
class HabitTask extends Model
{
    protected $fillable = [
        'habit_id', 'user_id', 'name', 'name_key', 'metric', 'target', 'position', 'valid_from', 'valid_until',
    ];

    // valid_from / valid_until sengaja TIDAK di-cast: dibiarkan string 'Y-m-d' (sama seperti
    // kolom tanggal di Habit) supaya tidak ada pergeseran zona waktu lewat Carbon.
    protected function casts(): array
    {
        return [
            'metric' => TaskMetric::class,
            'target' => 'integer',
            'position' => 'integer',
        ];
    }

    /** Tugas yang berlaku pada tanggal lokal $date: valid_from <= date < valid_until. */
    public function scopeInEffectOn(Builder $query, string $date): Builder
    {
        return $query
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', $date));
    }

    public function isInEffectOn(string $date): bool
    {
        return ($this->valid_from === null || $this->valid_from <= $date)
            && ($this->valid_until === null || $this->valid_until > $date);
    }

    public function habit(): BelongsTo
    {
        return $this->belongsTo(Habit::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(HabitTaskEntry::class, 'task_id');
    }
}
