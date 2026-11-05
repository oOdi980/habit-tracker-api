<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kode undangan sekali pakai untuk bergabung ke sesi. Kode hangus saat dipakai
        // (used_at terisi) atau lewat expires_at. Sesinya sendiri tidak punya masa berlaku.
        Schema::create('circle_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('circle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            // Dibuat dengan random_bytes() di service (bukan rand()), lalu dicek unik di sini.
            $table->string('code', 16)->unique();
            $table->timestampTz('expires_at');

            // Dipakai oleh siapa dan kapan. Kalau akun pemakai dihapus, jejaknya cukup dikosongkan.
            $table->foreignId('used_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('used_at')->nullable();

            $table->timestampsTz();

            $table->index('circle_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('circle_invites');
    }
};
