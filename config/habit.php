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

    'default_timezone' => 'Asia/Jakarta',

    'invite' => [
        'ttl_hours' => 24,
        'length' => 8,
        // 32 karakter tanpa I, O, 0, 1 (gampang tertukar). 32 = 2^5, jadi tiap karakter
        // dipilih seragam, tanpa bias dari operasi modulo.
        'alphabet' => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789',
    ],
];
