<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Penghubung user <-> sesi. Satu user boleh ada di banyak sesi (mis. Anjal di sesi
        // bersama Odi dan di sesi bersama mamanya). Batas "tepat 2 anggota per sesi" dijaga
        // di aplikasi, bukan di database, supaya gampang dilonggarkan nanti.
        Schema::create('circle_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('circle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('joined_at')->useCurrent();

            $table->unique(['circle_id', 'user_id']);
            $table->index('user_id'); // "sesi apa saja milik user ini"
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('circle_members');
    }
};
