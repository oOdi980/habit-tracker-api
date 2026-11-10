<?php

namespace App\Enums;

enum SummaryStatus: string
{
    case Open = 'open';
    case Achieved = 'achieved';
    case Missed = 'missed';
    // Habit diberi izin (libur) di minggu itu: tidak masuk pembagi persentase.
    case Excused = 'excused';
}
