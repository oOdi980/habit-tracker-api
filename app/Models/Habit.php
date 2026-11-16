<?php

namespace App\Models;

use App\Enums\CompletionRule;
use App\Enums\HabitMode;
use App\Enums\HabitScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Habit extends Model
{
    // Tahap hidup sebuah habit (dihitung dari tanggal, tidak disimpan):
    public const PENDING_APPROVAL = 'pending_approval'; // habit bersama, menunggu persetujuan partner
    public const UPCOMING = 'upcoming';                 // sudah pasti, tetapi mulai dihitung Senin nanti
    public const ACTIVE = 'active';                     // sedang dihitung
    public const ENDING = 'ending';                     // diarsipkan, masih dihitung sampai Minggu ini selesai
    public const ENDED = 'ended';                       // sudah berakhir (tersembunyi)

    protected $fillable = [
        'circle_id', 'owner_id', 'created_by', 'title', 'scope', 'completion_rule', 'mode',
        'target_per_week', 'pending_target_per_week', 'pending_target_from',
        'accepted_at', 'starts_on', 'ends_on', 'archived_at',
    ];

    // Sama dengan default di database, supaya habit yang baru dibuat di memori langsung punya mode.
    protected $attributes = [
        'mode' => 'simple',
    ];

    // Kolom DATE (starts_on, ends_on, pending_target_from) sengaja TIDAK di-cast: dibiarkan
    // string 'Y-m-d' supaya tidak ada pergeseran zona waktu lewat Carbon, dan bisa dibandingkan
    // langsung sebagai string.
    protected function casts(): array
    {
        return [
            'scope' => HabitScope::class,
            'completion_rule' => CompletionRule::class,
            'mode' => HabitMode::class,
            'target_per_week' => 'integer',
            'pending_target_per_week' => 'integer',
            'accepted_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /** Tahap hidup pada tanggal lokal $today ('Y-m-d'). */
    public function lifecycle(string $today): string
    {
        if ($this->ends_on !== null && $this->ends_on <= $today) {
            return self::ENDED;
        }

        // Hanya habit bersama yang belum disetujui yang tidak punya starts_on.
        if ($this->starts_on === null) {
            return self::PENDING_APPROVAL;
        }

        if ($this->starts_on > $today) {
            return self::UPCOMING;
        }

        return $this->ends_on !== null ? self::ENDING : self::ACTIVE;
    }

    /** Target yang berlaku untuk minggu yang dimulai $weekStart (memperhitungkan perubahan tertahan). */
    public function targetFor(string $weekStart): int
    {
        if ($this->pending_target_from !== null && $this->pending_target_from <= $weekStart) {
            return (int) $this->pending_target_per_week;
        }

        return (int) $this->target_per_week;
    }

    /**
     * Siapa saja yang punya check-in di habit ini: pemiliknya (personal) atau seluruh anggota sesi
     * (bersama). $memberIds = id semua anggota sesi.
     *
     * @param  int[]  $memberIds
     * @return int[]
     */
    public function participantIds(array $memberIds): array
    {
        return $this->scope === HabitScope::Personal ? [(int) $this->owner_id] : $memberIds;
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

    public function tasks(): HasMany
    {
        return $this->hasMany(HabitTask::class);
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
