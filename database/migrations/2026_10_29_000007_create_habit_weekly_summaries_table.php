<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Satu baris = satu habit x satu orang x satu minggu. Dibuat HANYA oleh job Senin dini
        // hari, jadi target di sini adalah snapshot: mengubah target di tengah minggu tidak
        // bisa dipakai untuk lolos dari evaluasi, dan histori lama tidak ikut berubah.
        Schema::create('habit_weekly_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('habit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Selalu hari Senin (dijaga CHECK di bawah), disimpan sebagai DATE tanpa zona waktu.
            $table->date('week_start');
            $table->unsignedSmallInteger('target_snapshot');

            // Kosong selama minggu berjalan (progres live dihitung dari habit_logs supaya
            // tidak bisa drift). Diisi final saat minggu ditutup.
            $table->unsignedSmallInteger('done_count')->nullable();
            $table->string('status', 16)->default('open'); // open | achieved | missed
            $table->timestampTz('evaluated_at')->nullable();

            $table->timestampsTz();

            $table->unique(['habit_id', 'user_id', 'week_start']);
            // Sapuan job: "minggu mana yang masih open padahal sudah lewat".
            $table->index(['week_start', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE habit_weekly_summaries
                    ADD CONSTRAINT hws_status_check CHECK (status IN ('open', 'achieved', 'missed')),
                    ADD CONSTRAINT hws_target_check CHECK (target_snapshot BETWEEN 1 AND 7),
                    ADD CONSTRAINT hws_done_check CHECK (done_count IS NULL OR done_count BETWEEN 0 AND 7),
                    ADD CONSTRAINT hws_monday_check CHECK (EXTRACT(ISODOW FROM week_start) = 1),
                    ADD CONSTRAINT hws_state_check CHECK (
                        (status = 'open' AND evaluated_at IS NULL AND done_count IS NULL)
                        OR (status <> 'open' AND evaluated_at IS NOT NULL AND done_count IS NOT NULL)
                    )
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('habit_weekly_summaries');
    }
};
