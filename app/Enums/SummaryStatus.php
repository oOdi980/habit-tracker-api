<?php

namespace App\Enums;

enum SummaryStatus: string
{
    case Open = 'open';
    case Achieved = 'achieved';
    case Missed = 'missed';
}
