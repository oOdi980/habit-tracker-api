<?php

namespace App\Enums;

enum ReminderType: string
{
    case Static = 'static';
    case SmartCheck = 'smart_check';
    case WeeklyDeadline = 'weekly_deadline';
}
