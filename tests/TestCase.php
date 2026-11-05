<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // Pagar terakhir SEBELUM aplikasi menyala (dan sebelum RefreshDatabase sempat menghapus
        // tabel): tolak jalan kalau database yang dituju bukan database tes.
        $database = (string) ($_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: '');

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException(
                "Tes ditolak: DB_DATABASE='{$database}' tidak berakhiran '_test'. "
                .'Periksa phpunit.xml agar data asli tidak terhapus.'
            );
        }

        parent::setUp();
    }
}
