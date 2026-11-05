<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Satu user bisa punya banyak perangkat (HP, tablet, PWA di browser).
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Token FCM unik secara global: kalau HP yang sama dipakai login akun lain,
            // barisnya dipindahkan (upsert), bukan digandakan, supaya push tidak nyasar.
            $table->string('fcm_token', 512)->unique();
            $table->string('platform', 16); // android | ios | web
            $table->timestampTz('last_seen_at')->nullable();

            $table->timestampsTz();

            $table->index('user_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE devices
                    ADD CONSTRAINT devices_platform_check CHECK (platform IN ('android', 'ios', 'web'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
