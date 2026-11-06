<?php

namespace App\Services;

use App\Enums\HabitScope;
use App\Models\Habit;
use App\Models\User;
use App\Support\CircleClock;

/** "Papan hari ini": layar utama, satu panggilan berisi habit kedua anggota beserta progres minggu ini. */
class TodayService
{
    public function __construct(
        private readonly HabitService $habits,
        private readonly ProgressService $progress,
    ) {
    }

    public function board(User $user, int $circleId): array
    {
        $circle = $this->habits->memberCircle($user, $circleId);
        $clock = CircleClock::forCircle($circle);
        $today = $clock->today();
        $weekStart = $clock->weekStart();

        $memberIds = $this->progress->memberIds($circle->id);
        $members = $circle->members()->orderBy('users.id')->get()
            ->map(fn (User $member) => ['id' => $member->id, 'name' => $member->name])
            ->values()
            ->all();

        // Hanya habit yang SEDANG dihitung hari ini. Yang akan datang, menunggu persetujuan,
        // atau sudah berakhir muncul di daftar habit, bukan di papan.
        $habits = Habit::query()
            ->where('circle_id', $circle->id)
            ->whereNotNull('starts_on')
            ->where('starts_on', '<=', $today)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>', $today))
            ->orderBy('id')
            ->get();

        $progress = $this->progress->weekProgress($habits, $memberIds, $weekStart);

        $rows = $habits->map(function (Habit $habit) use ($progress, $today, $user) {
            $p = $progress[$habit->id];

            $doneToday = [];
            foreach ($p['dates_by_user'] as $userId => $dates) {
                if (in_array($today, $dates, true)) {
                    $doneToday[] = (int) $userId;
                }
            }
            sort($doneToday);

            return [
                'id' => $habit->id,
                'title' => $habit->title,
                'scope' => $habit->scope->value,
                'completion_rule' => $habit->completion_rule->value,
                'owner_id' => $habit->owner_id,
                'status' => $habit->lifecycle($today),
                'ends_on' => $habit->ends_on,
                'target_per_week' => $p['target'],
                'week_done' => $p['done'],
                'week_achieved' => $p['achieved'],
                'done_today_by' => $doneToday,
                // Habit personal: hanya pemiliknya yang boleh check-in; partner hanya memantau.
                'can_check_in' => $habit->scope === HabitScope::Shared || (int) $habit->owner_id === $user->id,
            ];
        })->values()->all();

        // Habit bersama yang menunggu persetujuan USER INI (untuk lencana/pengingat di layar).
        $pendingApprovals = Habit::query()
            ->where('circle_id', $circle->id)
            ->where('scope', HabitScope::Shared->value)
            ->whereNull('starts_on')
            ->where('created_by', '!=', $user->id)
            ->count();

        return [
            'date' => $today,
            'week_start' => $weekStart,
            'week_end' => $clock->weekEnd(),
            'timezone' => $circle->timezone,
            'members' => $members,
            'pending_approvals_count' => $pendingApprovals,
            'habits' => $rows,
        ];
    }
}
