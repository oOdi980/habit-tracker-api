<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Sesi" (circle): wadah privat untuk dua orang. Semua habit, evaluasi, dan
        // consequence menempel ke sini, jadi data antar-sesi terisolasi otomatis.
        Schema::create('circles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);

            // Zona waktu IANA (mis. "Asia/Jakarta"). Satu-satunya acuan "hari" dan "minggu"
            // untuk seluruh isi sesi, jadi kedua anggota selalu punya batas hari yang sama.
            $table->string('timezone', 64)->default('Asia/Jakarta');

            // Ambang evaluasi mingguan, dalam persen habit yang tercapai:
            //   rasio >= reward_min_ratio        -> reward
            //   rasio <  punishment_below_ratio  -> punishment
            //   di antaranya                     -> netral
            $table->unsignedSmallInteger('reward_min_ratio')->default(90);
            $table->unsignedSmallInteger('punishment_below_ratio')->default(60);

            $table->timestampsTz();
        });

        // Schema builder Laravel tidak punya helper CHECK, jadi pakai SQL langsung.
        // Dilewati di driver lain (mis. SQLite untuk tes) supaya migrasi tetap bisa jalan.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE circles
                    ADD CONSTRAINT circles_ratio_check CHECK (
                        reward_min_ratio BETWEEN 1 AND 100
                        AND punishment_below_ratio BETWEEN 0 AND 100
                        AND punishment_below_ratio <= reward_min_ratio
                    )
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('circles');
    }
};
