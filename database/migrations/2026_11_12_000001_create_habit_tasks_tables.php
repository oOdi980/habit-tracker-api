<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mode habit. Habit yang sudah ada otomatis 'simple' (satu tap), check-in lamanya tetap sah.
        Schema::table('habits', function (Blueprint $table) {
            $table->string('mode', 16)->default('simple');
        });

        // Tugas harian milik SATU orang di satu habit (habit bersama: tiap anggota punya daftar sendiri).
        //
        // Tugas tidak pernah diubah di tempat (kecuali tampilan nama/urutan). Mengubah target atau
        // jenis ukuran = menutup baris lama (valid_until) dan membuka baris baru (valid_from),
        // keduanya jatuh di Senin depan. Jadi entri hari-hari lalu selalu menunjuk ke tugas dengan
        // target yang berlaku SAAT ITU, dan analisis nanti tidak salah baca riwayat.
        //   valid_from  NULL = sejak habit dibuat; tanggal lokal sesi = mulai berlaku hari itu
        //   valid_until NULL = masih berlaku;      selalu SENIN (batas eksklusif)
        Schema::create('habit_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('habit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name', 60);        // tampilan (spasi dirapikan, huruf asli dipertahankan)
            $table->string('name_key', 120);   // kunci banding: huruf kecil. "Push Up" == "push up"
            $table->string('metric', 16);
            $table->unsignedInteger('target');  // target HARIAN, satuan dasar (kali/menit/meter/langkah)
            $table->unsignedSmallInteger('position')->default(0);

            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->timestampsTz();

            $table->index(['habit_id', 'user_id']);
        });

        // Catatan progres. Satu tugas di satu hari bisa punya banyak entri (push up 20 + 20 + 10);
        // total hari itu = SUM(amount). habit_id/user_id sengaja diulang dari tugasnya supaya papan
        // dan riwayat bisa memfilter tanpa JOIN; service menjaga keduanya selalu sama dengan tugasnya.
        Schema::create('habit_task_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('habit_tasks')->cascadeOnDelete();
            $table->foreignId('habit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Tanggal LOKAL sesi, dihitung server (sama seperti habit_logs.log_date).
            $table->date('log_date');
            $table->unsignedInteger('amount');
            $table->unsignedInteger('weight_g')->nullable(); // beban dalam gram, hanya tugas 'count'

            // 'tap'    = tombol ceklis (tercatat sebesar sisa target)
            // 'manual' = angka diisi sendiri (penanda "angka sebenarnya" untuk analisis)
            $table->string('source', 8);

            // Kunci idempotensi dari klien: kirim ulang (jaringan putus) tidak menggandakan entri.
            // NULL boleh berulang (Postgres menganggap NULL berbeda-beda), jadi opsional.
            $table->string('client_key', 40)->nullable();

            $table->timestampTz('created_at')->useCurrent();

            $table->index(['habit_id', 'user_id', 'log_date']);
            $table->index(['task_id', 'log_date']);
            $table->unique(['task_id', 'log_date', 'client_key']);
        });

        // Catatan bebas per orang per hari (maks 500 karakter).
        Schema::create('habit_day_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('habit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('log_date');
            $table->string('note', 500);
            $table->timestampsTz();

            $table->unique(['habit_id', 'user_id', 'log_date']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE habits
                    ADD CONSTRAINT habits_mode_check CHECK (mode IN ('simple', 'checklist'))
                SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE habit_tasks
                    ADD CONSTRAINT habit_tasks_metric_check CHECK (metric IN ('count', 'minutes', 'meters', 'steps')),
                    ADD CONSTRAINT habit_tasks_target_check CHECK (target > 0),
                    ADD CONSTRAINT habit_tasks_until_monday_check CHECK (
                        valid_until IS NULL OR EXTRACT(ISODOW FROM valid_until) = 1
                    ),
                    ADD CONSTRAINT habit_tasks_period_check CHECK (
                        valid_from IS NULL OR valid_until IS NULL OR valid_until > valid_from
                    )
                SQL);

            // Di antara tugas yang MASIH BERLAKU (valid_until kosong), nama tidak boleh kembar.
            // Versi lama yang sudah ditutup keluar dari indeks ini, jadi riwayat tidak bentrok.
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX habit_tasks_live_name_unique
                    ON habit_tasks (habit_id, user_id, name_key)
                    WHERE valid_until IS NULL
                SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE habit_task_entries
                    ADD CONSTRAINT habit_task_entries_amount_check CHECK (amount > 0),
                    ADD CONSTRAINT habit_task_entries_weight_check CHECK (weight_g IS NULL OR weight_g > 0),
                    ADD CONSTRAINT habit_task_entries_source_check CHECK (source IN ('tap', 'manual'))
                SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE habit_day_notes
                    ADD CONSTRAINT habit_day_notes_length_check CHECK (char_length(note) BETWEEN 1 AND 500)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('habit_day_notes');
        Schema::dropIfExists('habit_task_entries');
        Schema::dropIfExists('habit_tasks');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE habits DROP CONSTRAINT IF EXISTS habits_mode_check');
        }

        Schema::table('habits', function (Blueprint $table) {
            $table->dropColumn('mode');
        });
    }
};
