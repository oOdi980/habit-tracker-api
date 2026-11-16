<?php

namespace App\Enums;

// Jenis ukuran sebuah tugas. Satuan dasar selalu bilangan bulat.
//   count   = jumlah (rep, kali)
//   minutes = durasi dalam menit
//   meters  = jarak dalam METER (aplikasi menampilkannya sebagai km)
//   steps   = jumlah langkah
enum TaskMetric: string
{
    case Count = 'count';
    case Minutes = 'minutes';
    case Meters = 'meters';
    case Steps = 'steps';

    /** Batas target harian sekaligus batas satu entri (lihat config/habit.php). */
    public function maxAmount(): int
    {
        return (int) config('habit.task.max_amount.'.$this->value);
    }

    /** Beban (kg) hanya masuk akal untuk tugas hitungan (hammer curl, squat, ...). */
    public function allowsWeight(): bool
    {
        return $this === self::Count;
    }
}
