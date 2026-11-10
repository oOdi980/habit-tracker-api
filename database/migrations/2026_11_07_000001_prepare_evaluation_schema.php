<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Penanda "sesi ini sudah dievaluasi sampai minggu yang dimulai tanggal ini (Senin)".
        // Dengan ini scheduler cukup membandingkan dua tanggal di memori untuk melewati sesi yang
        // tidak punya pekerjaan (tanpa satu query pun per sesi), dan tahu persis dari minggu mana
        // harus mengejar kalau server sempat mati.
        Schema::table('circles', function (Blueprint $table) {
            $table->date('evaluated_through')->nullable();
        });

        // Daftar reward/punishment: siapa yang mengusulkan, siapa yang menyetujui, kapan.
        // Item yang dipakai di evaluasi tidak pernah dihapus (hanya is_active = false) supaya
        // riwayat "kamu kena punishment X" tidak ikut hilang.
        Schema::table('consequences', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE circles
                    ADD CONSTRAINT circles_evaluated_through_check CHECK (
                        evaluated_through IS NULL OR EXTRACT(ISODOW FROM evaluated_through) = 1
                    )
                SQL);

            // Ambang bawaan disepakati 80/50 (sebelumnya 90/60 saat skema pertama dibuat).
            DB::statement('ALTER TABLE circles ALTER COLUMN reward_min_ratio SET DEFAULT 80, ALTER COLUMN punishment_below_ratio SET DEFAULT 50');

            // Penyetuju tidak boleh sama dengan pengusul (dijaga database, bukan hanya aplikasi).
            DB::statement(<<<'SQL'
                ALTER TABLE consequences
                    ADD CONSTRAINT consequences_no_self_approval_check CHECK (
                        approved_by IS NULL OR created_by IS NULL OR approved_by <> created_by
                    )
                SQL);

            // Status ringkasan mingguan bertambah satu: 'excused' (habit diberi izin di minggu itu).
            DB::statement('ALTER TABLE habit_weekly_summaries DROP CONSTRAINT hws_status_check');
            DB::statement(<<<'SQL'
                ALTER TABLE habit_weekly_summaries
                    ADD CONSTRAINT hws_status_check CHECK (status IN ('open', 'achieved', 'missed', 'excused'))
                SQL);
        }

        // Sesi yang masih memakai ambang bawaan lama (belum ada yang bisa mengubahnya lewat API)
        // ikut ke ambang baru. Sesi dengan ambang kustom tidak disentuh.
        DB::table('circles')
            ->where('reward_min_ratio', 90)
            ->where('punishment_below_ratio', 60)
            ->update(['reward_min_ratio' => 80, 'punishment_below_ratio' => 50]);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // PERHATIAN: gagal kalau sudah ada ringkasan berstatus 'excused'. Itu disengaja,
            // lebih baik berhenti daripada diam-diam mengubah riwayat.
            DB::statement('ALTER TABLE habit_weekly_summaries DROP CONSTRAINT IF EXISTS hws_status_check');
            DB::statement(<<<'SQL'
                ALTER TABLE habit_weekly_summaries
                    ADD CONSTRAINT hws_status_check CHECK (status IN ('open', 'achieved', 'missed'))
                SQL);

            DB::statement('ALTER TABLE consequences DROP CONSTRAINT IF EXISTS consequences_no_self_approval_check');
            DB::statement('ALTER TABLE circles DROP CONSTRAINT IF EXISTS circles_evaluated_through_check');
            DB::statement('ALTER TABLE circles ALTER COLUMN reward_min_ratio SET DEFAULT 90, ALTER COLUMN punishment_below_ratio SET DEFAULT 60');
        }

        Schema::table('consequences', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn('approved_at');
        });

        Schema::table('circles', function (Blueprint $table) {
            $table->dropColumn('evaluated_through');
        });
    }
};
