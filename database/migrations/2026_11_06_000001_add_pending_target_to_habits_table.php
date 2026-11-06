<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Perubahan target di tengah minggu TIDAK boleh langsung berlaku (aturan: berlaku Senin
        // depan). Targetnya ditahan di sini bersama tanggal berlakunya; job pembuka minggu
        // (fase berikutnya) memindahkannya ke target_per_week saat Senin itu tiba.
        // Tanggal berlaku disimpan eksplisit, bukan "pokoknya Senin depan", supaya edit yang
        // dilakukan Senin 00:00:30 (sebelum job jalan) tetap berlaku Senin MINGGU DEPAN.
        Schema::table('habits', function (Blueprint $table) {
            $table->unsignedSmallInteger('pending_target_per_week')->nullable();
            $table->date('pending_target_from')->nullable(); // selalu Senin
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE habits
                    ADD CONSTRAINT habits_pending_check CHECK (
                        (pending_target_per_week IS NULL AND pending_target_from IS NULL)
                        OR (
                            -- IS NOT NULL wajib eksplisit: CHECK meloloskan hasil NULL, jadi
                            -- "NULL BETWEEN 1 AND 7" saja akan meloloskan baris yang cuma punya tanggal.
                            pending_target_per_week IS NOT NULL
                            AND pending_target_per_week BETWEEN 1 AND 7
                            AND pending_target_from IS NOT NULL
                            AND EXTRACT(ISODOW FROM pending_target_from) = 1
                        )
                    )
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE habits DROP CONSTRAINT IF EXISTS habits_pending_check');
        }

        Schema::table('habits', function (Blueprint $table) {
            $table->dropColumn(['pending_target_per_week', 'pending_target_from']);
        });
    }
};
