<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

// bcrypt hanya membaca 72 byte pertama password. Tanpa batas ini, dua password panjang yang
// hanya beda di ujung akan dianggap sama dan user tidak sadar.
class PasswordMaxBytes implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && strlen($value) > 72) {
            $fail('Password maksimal 72 karakter.');
        }
    }
}
