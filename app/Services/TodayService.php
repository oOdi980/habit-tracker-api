<?php

namespace App\Services;

use App\Enums\HabitMode;
use App\Enums\HabitScope;
use App\Models\Habit;
use App\Models\HabitLeave;
use App\Models\User;
use App\Support\CircleClock;
use Illuminate\Support\Facades\DB;

/** "Papan hari ini": layar utama, satu panggilan berisi habit kedua anggota beserta progres minggu ini. */
class TodayService
{
    public function __construct(
        private readonly HabitService $habits,
        private readonly ProgressService $progress,
        private readonly TaskProgressService $tasks,
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

        // Isi tugas hari ini untuk habit checklist (kedua anggota terlihat), tiga query untuk semuanya.
        $details = $this->tasks->board(
            $habits->filter(fn (Habit $habit) => $habit->mode === HabitMode::Checklist),
            $memberIds,
            $today
        );

        // Status izin minggu ini per habit: 'pending' (menunggu partner) atau 'approved'. Satu query.
        $leaveStatus = HabitLeave::query()
            ->where('circle_id', $circle->id)
            ->where('week_start', $weekStart)
            ->get(['habit_id', 'approved_at'])
            ->mapWithKeys(fn ($leave) => [(int) $leave->habit_id => $leave->approved_at === null ? 'pending' : 'approved'])
            ->all();

        $rows = $habits->map(function (Habit $habit) use ($progress, $today, $user, $leaveStatus, $details) {
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
                'mode' => $habit->mode->value,
                'owner_id' => $habit->owner_id,
                'status' => $habit->lifecycle($today),
                'ends_on' => $habit->ends_on,
                'target_per_week' => $p['target'],
                'week_done' => $p['done'],
                'week_achieved' => $p['achieved'],
                'done_today_by' => $doneToday,
                // Habit simpel: null. Habit checklist: [{user_id, complete, note, tasks: [...]}] per anggota.
                'detail' => $details[$habit->id] ?? null,
                'leave_status' => $leaveStatus[$habit->id] ?? null,
                // Habit personal: hanya pemiliknya yang boleh check-in; partner hanya memantau.
                'can_check_in' => $habit->scope === HabitScope::Shared || (int) $habit->owner_id === $user->id,
            ];
        })->values()->all();

        // Semua angka lencana dalam SATU round trip (papan ini adalah endpoint yang paling sering dipanggil).
        //  - habit bersama yang menunggu persetujuan user ini
        //  - izin yang menunggu persetujuan user ini (minggu berjalan dan seterusnya)
        //  - item reward/punishment yang menunggu persetujuan user ini
        //  - punishment partner yang menunggu konfirmasi user ini
        //  - punishment milik user sendiri yang belum dikonfirmasi partner
        // Binding posisional (?), bukan bernama: placeholder bernama yang diulang tidak aman di PDO.
        $badges = DB::selectOne(
            "SELECT
                (SELECT COUNT(*) FROM habits
                  WHERE circle_id = ? AND scope = 'shared' AND starts_on IS NULL AND created_by <> ?) AS habits_to_approve,
                (SELECT COUNT(*) FROM habit_leaves
                  WHERE circle_id = ? AND approved_at IS NULL AND requested_by <> ? AND week_start >= ?) AS leaves_to_approve,
                (SELECT COUNT(*) FROM consequences
                  WHERE circle_id = ? AND is_active AND approved_at IS NULL AND created_by IS DISTINCT FROM ?) AS consequences_to_approve,
                (SELECT COUNT(*) FROM weekly_evaluations
                  WHERE circle_id = ? AND result = 'punishment' AND fulfilled_at IS NULL AND user_id <> ?) AS punishments_to_confirm,
                (SELECT COUNT(*) FROM weekly_evaluations
                  WHERE circle_id = ? AND result = 'punishment' AND fulfilled_at IS NULL AND user_id = ?) AS my_open_punishments",
            [
                $circle->id, $user->id,
                $circle->id, $user->id, $weekStart,
                $circle->id, $user->id,
                $circle->id, $user->id,
                $circle->id, $user->id,
            ]
        );

        return [
            'date' => $today,
            'week_start' => $weekStart,
            'week_end' => $clock->weekEnd(),
            'timezone' => $circle->timezone,
            'members' => $members,
            'pending_approvals_count' => (int) $badges->habits_to_approve,
            'pending_leave_approvals_count' => (int) $badges->leaves_to_approve,
            'pending_consequence_approvals_count' => (int) $badges->consequences_to_approve,
            'punishments_to_confirm_count' => (int) $badges->punishments_to_confirm,
            'my_open_punishments_count' => (int) $badges->my_open_punishments,
            'habits' => $rows,
        ];
    }
}
