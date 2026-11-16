<?php

namespace App\Support;

/**
 * Penyeragaman nama tugas. Murni PHP supaya bisa diuji tanpa Laravel.
 *
 *  clean() : untuk TAMPILAN. Spasi di tepi dibuang dan spasi ganda dirapikan, huruf asli dipertahankan.
 *  key()   : untuk PERBANDINGAN/analisis. Sama seperti clean() lalu huruf kecil, jadi
 *            "Push Up", " push  up " dan "PUSH UP" dianggap satu gerakan yang sama.
 */
final class TaskName
{
    public static function clean(string $name): string
    {
        // \s + /u mencakup spasi unicode (mis. NBSP dari keyboard HP). Jika $name bukan UTF-8 yang
        // valid, preg_replace mengembalikan null; request sudah menolak yang seperti itu, tetapi
        // fallback ke aslinya menjaga fungsi ini tidak pernah melempar TypeError.
        $collapsed = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return trim($collapsed);
    }

    public static function key(string $name): string
    {
        return mb_strtolower(self::clean($name), 'UTF-8');
    }
}
