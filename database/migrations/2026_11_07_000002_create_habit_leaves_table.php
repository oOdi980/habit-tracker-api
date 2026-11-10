<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Izin: satu habit "libur" untuk satu minggu. Habit yang diberi izin dikeluarkan dari
        // pembagi persentase minggu itu (status ringkasan 'excused'), baru berlaku bila partner
        // menyetujui. Kuota per orang per bulan dijaga aplikasi (config habit.leave.per_month).
        Schema::create('habit_leaves', function (Blueprint $table) {
            $table->id();
            // circle_id sengaja disimpan juga (turunan dari habit): kuota dan penguncian per sesi
            // tidak perlu join. Aplikasi mengisinya dari habit, tidak pernah dari klien.
            $table->foreignId('circle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('habit_id')->constrained()->cascadeOnDelete();

            $table->date('week_start'); // selalu Senin
            // Tanggal 1 bulan tempat minggu ini dihitung (aturan hari Kamis). Disimpan supaya
            // hitungan kuota tidak bergantung pada perhitungan tanggal di dua tempat berbeda.
            $table->date('quota_month');

            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();

            $table->timestampsTz();

            $table->unique(['habit_id', 'week_start']);
            $table->index(['circle_id', 'week_start']);
            $table->index(['circle_id', 'requested_by', 'quota_month']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE habit_leaves
                    ADD CONSTRAINT hl_monday_check CHECK (EXTRACT(ISODOW FROM week_start) = 1),
                    ADD CONSTRAINT hl_quota_month_check CHECK (EXTRACT(DAY FROM quota_month) = 1),
                    ADD CONSTRAINT hl_no_self_approval_check CHECK (approved_by IS NULL OR approved_by <> requested_by)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('habit_leaves');
    }
};
