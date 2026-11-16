<?php

namespace App\Enums;

// simple    = satu tap check-in (seperti sebelumnya), untuk habit tanpa angka (tidur, minum obat)
// checklist = punya daftar tugas; check-in tercatat OTOMATIS saat semua tugas hari itu tercapai
enum HabitMode: string
{
    case Simple = 'simple';
    case Checklist = 'checklist';
}
