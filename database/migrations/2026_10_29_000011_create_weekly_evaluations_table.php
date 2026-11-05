<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hasil evaluasi mingguan per orang per sesi. Satu baris per (sesi, user, minggu),
        // dijaga unique supaya job evaluasi yang jalan dua kali tidak menghukum dua kali.
        Schema::create('weekly_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('circle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->date('week_start'); // selalu Senin (dijaga CHECK)
            $table->unsignedSmallInteger('habits_total');
            $table->unsignedSmallInteger('habits_achieved');
            $table->string('result', 16); // reward | punishment | neutral

            // Consequence yang terpilih. Kalau katalognya terhapus, hasil evaluasi tetap ada.
            $table->foreignId('consequence_id')->nullable()->constrained()->nullOnDelete();

            // Punishment harus dikonfirmasi selesai oleh PARTNER (bukan yang dihukum).
            // Aturan "bukan diri sendiri" dijaga di aplikasi.
            $table->timestampTz('fulfilled_at')->nullable();
            $table->foreignId('fulfilled_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampTz('evaluated_at')->useCurrent();
            $table->timestampsTz();

            $table->unique(['circle_id', 'user_id', 'week_start']);
            $table->index('week_start');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE weekly_evaluations
                    ADD CONSTRAINT we_result_check CHECK (result IN ('reward', 'punishment', 'neutral')),
                    ADD CONSTRAINT we_counts_check CHECK (habits_achieved BETWEEN 0 AND habits_total),
                    ADD CONSTRAINT we_monday_check CHECK (EXTRACT(ISODOW FROM week_start) = 1),
                    ADD CONSTRAINT we_empty_week_check CHECK (habits_total > 0 OR result = 'neutral')
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_evaluations');
    }
};
