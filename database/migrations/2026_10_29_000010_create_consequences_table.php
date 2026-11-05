<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Katalog reward/punishment dunia nyata yang disepakati kedua anggota sesi.
        // Saat evaluasi, punishment dipilih ACAK dari baris yang is_active = true.
        // Jangan dihapus kalau sudah pernah dipakai; nonaktifkan lewat is_active.
        Schema::create('consequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('circle_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16); // reward | punishment
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestampsTz();

            $table->index(['circle_id', 'type', 'is_active']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE consequences
                    ADD CONSTRAINT consequences_type_check CHECK (type IN ('reward', 'punishment'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('consequences');
    }
};
