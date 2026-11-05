<?php

namespace App\Enums;

// personal = milik satu orang; shared = milik berdua (butuh persetujuan partner)
enum HabitScope: string
{
    case Personal = 'personal';
    case Shared = 'shared';
}
