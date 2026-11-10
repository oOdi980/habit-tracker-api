<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Evaluasi mingguan. Tiap 5 menit karena murah: sesi yang tidak punya pekerjaan dilewati hanya
// dengan membandingkan dua tanggal di memori. Evaluasi sebenarnya jatuh sekitar Senin 00:11-00:16
// waktu masing-masing sesi (lihat habit.evaluation.grace_minutes).
// withoutOverlapping(10): jalan yang masih berjalan tidak ditumpuk; kunci otomatis lepas setelah
// 10 menit kalau prosesnya mati. Kebenaran data TIDAK bergantung pada ini (ada kunci sesi + UNIQUE).
// Produksi butuh satu cron:  * * * * * cd /path/ke/app && php artisan schedule:run >> /dev/null 2>&1
Schedule::command('habits:evaluate-weeks')->everyFiveMinutes()->withoutOverlapping(10);
