<?php

namespace App\Enums;

// individual = tiap orang dihitung sendiri; together = hari sah kalau KEDUA anggota check-in
enum CompletionRule: string
{
    case Individual = 'individual';
    case Together = 'together';
}
