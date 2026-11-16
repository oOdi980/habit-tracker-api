<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Catatan bebas satu orang untuk satu habit di satu hari (maks 500 karakter). */
class HabitDayNote extends Model
{
    protected $fillable = ['habit_id', 'user_id', 'log_date', 'note'];
}
