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
