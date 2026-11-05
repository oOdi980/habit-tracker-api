<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Habit hidup di SATU sesi saja. Dua jenis:
        //   personal : milik satu orang (owner_id terisi), dipantau partner, aturan 'individual'
        //   shared   : milik berdua (owner_id kosong), aturan 'together' (hari dihitung kalau
        //              KEDUA anggota check-in di hari yang sama)
        Schema::create('habits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('circle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->cascadeOnDelete();
            // Pembuat habit. Untuk habit bersama, yang menyetujui adalah anggota LAIN.
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            $table->string('title', 120);
            $table->string('scope', 16)->default('personal');
            $table->string('completion_rule', 16)->default('individual');
            $table->unsignedSmallInteger('target_per_week'); // 1-7 (harian = 7)

            // Persetujuan partner, hanya relevan untuk habit bersama.
            $table->timestampTz('accepted_at')->nullable();

            // Aturan "perubahan berlaku mulai Senin depan":
            //   starts_on = Senin pertama habit ini dievaluasi (null = belum aktif / menunggu persetujuan)
            //   ends_on   = Senin pertama habit TIDAK lagi dievaluasi (null = masih berjalan)
            // Habit diarsip hari Rabu -> ends_on = Senin depan, jadi minggu berjalan tetap dievaluasi.
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestampTz('archived_at')->nullable(); // kapan tombol arsip ditekan (audit/UI)

            $table->timestampsTz();

            $table->index(['circle_id', 'starts_on']);
            $table->index('owner_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            // Satu statement dengan banyak CONSTRAINT (aman untuk prepared statement PDO).
            DB::statement(<<<'SQL'
                ALTER TABLE habits
                    ADD CONSTRAINT habits_scope_check CHECK (scope IN ('personal', 'shared')),
                    ADD CONSTRAINT habits_rule_check CHECK (completion_rule IN ('individual', 'together')),
                    ADD CONSTRAINT habits_target_check CHECK (target_per_week BETWEEN 1 AND 7),
                    ADD CONSTRAINT habits_owner_check CHECK (
                        (scope = 'personal' AND owner_id IS NOT NULL AND completion_rule = 'individual')
                        OR (scope = 'shared' AND owner_id IS NULL)
                    ),
                    ADD CONSTRAINT habits_monday_check CHECK (
                        (starts_on IS NULL OR EXTRACT(ISODOW FROM starts_on) = 1)
                        AND (ends_on IS NULL OR EXTRACT(ISODOW FROM ends_on) = 1)
                    ),
                    ADD CONSTRAINT habits_period_check CHECK (
                        starts_on IS NULL OR ends_on IS NULL OR ends_on >= starts_on
                    ),
                    ADD CONSTRAINT habits_shared_accept_check CHECK (
                        scope = 'personal' OR starts_on IS NULL OR accepted_at IS NOT NULL
                    )
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('habits');
    }
};
