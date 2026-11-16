<?php

// Aturan bisnis aplikasi di satu tempat, supaya angka tidak tersebar di banyak file.
return [

    // Batas keras jumlah anggota satu sesi. Ini aturan aplikasi, bukan constraint database,
    // jadi nanti gampang dilonggarkan kalau aturannya berubah.
    'max_circle_members' => 2,

    // Batas sesi per user (dibuat + diikuti). Mencegah spam pembuatan sesi.
    'max_circles_per_user' => 5,

    // Batas token aktif per user (kira-kira satu per perangkat). Yang terlama dibuang otomatis.
    'max_tokens_per_user' => 10,

    // Batas habit yang belum berakhir (aktif + akan datang + menunggu persetujuan) per sesi.
    'max_habits_per_circle' => 30,

    'default_timezone' => 'Asia/Jakarta',

    'invite' => [
        'ttl_hours' => 24,
        'length' => 8,
        // 32 karakter tanpa I, O, 0, 1 (gampang tertukar). 32 = 2^5, jadi tiap karakter
        // dipilih seragam, tanpa bias dari operasi modulo.
        'alphabet' => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789',
    ],

    'checkin' => [
        // Jam dari HP dipercaya hanya bila selisihnya dengan jam server <= nilai ini (menit).
        'client_ts_tolerance_minutes' => 10,
    ],

    // Tugas harian pada habit bermode "checklist" (hammer curl, push up, lari, jalan kaki, ...).
    // Semua jumlah memakai satuan dasar bilangan bulat: kali, menit, METER, langkah, dan GRAM
    // (bukan desimal), supaya tidak ada bug pembulatan dan penjumlahan.
    'task' => [
        // Banyak tugas yang berlaku bersamaan untuk satu orang di satu habit.
        'max_per_user_per_habit' => 10,
        'name_max' => 60,

        // Batas target harian dan batas SATU entri, per jenis ukuran.
        'max_amount' => [
            'count' => 10000,
            'minutes' => 1440,      // sehari hanya 24 jam
            'meters' => 1000000,    // 1000 km
            'steps' => 200000,
        ],

        'max_weight_g' => 1000000,  // 1000 kg

        // Mencegah satu tugas dibanjiri ratusan entri dalam sehari (ada "ubah angka" untuk koreksi).
        'max_entries_per_task_per_day' => 100,

        'note_max' => 500,

        // Riwayat: berapa HARI beraktivitas per halaman.
        'history_default_days' => 14,
        'history_max_days' => 31,
    ],

    'evaluation' => [
        // Minggu W baru dievaluasi setelah Senin 00:00 (waktu sesi) + nilai ini (menit).
        // Selalu dipaksa minimal toleransi client_ts + 1 menit (lihat EvaluationService),
        // supaya check-in yang antre offline di pergantian minggu sudah pasti masuk.
        'grace_minutes' => 11,

        // Batas jumlah minggu yang boleh diminta sekali panggil daftar evaluasi.
        'max_list_weeks' => 52,
    ],

    'leave' => [
        // Kuota izin per orang per bulan (satu izin = satu habit di satu minggu).
        'per_month' => 4,
    ],

    // Batas item reward + punishment yang aktif (termasuk yang menunggu persetujuan) per sesi.
    'max_consequences_per_circle' => 100,
];
