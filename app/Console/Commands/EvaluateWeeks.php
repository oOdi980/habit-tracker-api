<?php

namespace App\Console\Commands;

use App\Services\EvaluationService;
use Illuminate\Console\Command;

class EvaluateWeeks extends Command
{
    protected $signature = 'habits:evaluate-weeks';

    protected $description = 'Evaluasi minggu yang sudah berakhir untuk semua sesi (aman dijalankan berulang).';

    public function handle(EvaluationService $evaluations): int
    {
        $stats = $evaluations->evaluateDueCircles();

        $this->info("Selesai: {$stats['weeks']} minggu dievaluasi di {$stats['circles']} sesi, {$stats['failed']} sesi gagal.");

        // Kode keluar non-nol bila ada sesi yang gagal, supaya terlihat di log/monitor cron.
        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
