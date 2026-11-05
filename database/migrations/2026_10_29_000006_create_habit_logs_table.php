<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hanya check-in yang disimpan. "Belum" = barisnya tidak ada, jadi tidak perlu cron
        // harian untuk membuat baris kosong. Undo = hapus barisnya.
        Schema::create('habit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('habit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Tanggal LOKAL sesi (zona waktu circle), dihitung server, bukan diambil dari
            // completed_at. Ini mencegah bug "check-in 00:30 masuk hari yang salah".
            $table->date('log_date');

            // Waktu server saat check-in diterima (default now() dari database).
            $table->timestampTz('completed_at')->useCurrent();
            // Waktu di perangkat, dikirim klien. Dipakai untuk antrian offline dengan toleransi
            // beberapa menit, tidak pernah dipercaya mentah-mentah.
            $table->timestampTz('client_ts')->nullable();

            // Kunci idempotensi: tombol ditekan dua kali tidak membuat dua baris.
            $table->unique(['habit_id', 'user_id', 'log_date']);
            // Untuk habit 'together': hitung tanggal yang punya log dari kedua anggota.
            $table->index(['habit_id', 'log_date']);
            // Untuk progres bulanan/tahunan per orang.
            $table->index(['user_id', 'log_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('habit_logs');
    }
};
