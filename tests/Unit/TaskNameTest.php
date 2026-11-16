<?php

namespace Tests\Unit;

use App\Support\TaskName;
use PHPUnit\Framework\TestCase;

// Tes murni untuk penyeragaman nama tugas (dasar pengelompokan gerakan yang sama saat analisis).
class TaskNameTest extends TestCase
{
    public function test_clean_merapikan_spasi_tetapi_mempertahankan_huruf(): void
    {
        $this->assertSame('Hammer Curl', TaskName::clean('  Hammer   Curl  '));
        $this->assertSame('Push up', TaskName::clean("Push\t\nup"));
        $this->assertSame('Push up', TaskName::clean("Push\u{00A0}up")); // spasi tak-putus dari keyboard HP
    }

    public function test_key_sama_untuk_penulisan_yang_berbeda_tetapi_gerakannya_sama(): void
    {
        $this->assertSame('push up', TaskName::key('Push Up'));
        $this->assertSame(TaskName::key('Push Up'), TaskName::key(' push   UP '));
        $this->assertNotSame(TaskName::key('Push up'), TaskName::key('Pushup')); // sengaja tidak ditebak-tebak
    }

    public function test_huruf_non_latin_ikut_diturunkan(): void
    {
        $this->assertSame('ünï', TaskName::key('ÜNÏ'));
    }

    public function test_utf8_tidak_valid_tidak_melempar_error(): void
    {
        $this->assertIsString(TaskName::clean("\xff\xfe jelek"));
        $this->assertIsString(TaskName::key("\xff\xfe jelek"));
    }
}
