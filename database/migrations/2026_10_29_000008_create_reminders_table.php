<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tiga jenis reminder dalam satu tabel:
        //   static          : notifikasi di jam yang diatur user (mis. 06:00, 17:00)
        //   smart_check     : saat fire, cek sisa tugas; kirim HANYA kalau ada yang tertinggal
        //   weekly_deadline : peringatan Minggu 23:30 per sesi (dibuat otomatis oleh sistem)
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('habit_id')->nullable()->constrained()->cascadeOnDelete(); // null = berlaku umum
            $table->foreignId('circle_id')->nullable()->constrained()->cascadeOnDelete(); // wajib utk weekly_deadline

            $table->string('type', 20);
            $table->time('time_of_day'); // jam lokal, dibaca memakai kolom timezone di bawah

            // Zona waktu IANA tempat time_of_day dibaca. Disimpan per reminder karena user bisa
            // ada di beberapa sesi; untuk weekly_deadline diisi dari timezone sesinya.
            $table->string('timezone', 64)->default('Asia/Jakarta');

            // Bitmask hari: Senin = bit 0 ... Minggu = bit 6. 127 = setiap hari.
            $table->unsignedSmallInteger('days_mask')->default(127);

            // Kapan reminder ini berikutnya harus dikirim (UTC). Dispatcher cukup query
            // "next_fire_at <= now()" tanpa menghitung zona waktu per baris.
            $table->timestampTz('next_fire_at')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestampsTz();

            $table->index(['is_active', 'next_fire_at']);
            $table->index('user_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE reminders
                    ADD CONSTRAINT reminders_type_check CHECK (type IN ('static', 'smart_check', 'weekly_deadline')),
                    ADD CONSTRAINT reminders_days_check CHECK (days_mask BETWEEN 1 AND 127),
                    ADD CONSTRAINT reminders_deadline_check CHECK (type <> 'weekly_deadline' OR circle_id IS NOT NULL)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
