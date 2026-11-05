<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pengaman anti-dobel: satu reminder maksimal satu kali per tanggal lokal.
        // Service menyisipkan baris ini DULU, baru mengirim push. Kalau job retry atau dua
        // proses overlap, insert kedua gagal di unique constraint dan pengiriman dibatalkan.
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reminder_id')->constrained()->cascadeOnDelete();
            $table->date('local_date'); // tanggal lokal menurut timezone reminder
            $table->timestampTz('sent_at')->useCurrent();

            $table->unique(['reminder_id', 'local_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
