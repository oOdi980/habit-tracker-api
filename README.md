# Habit Tracker API

Backend REST API untuk aplikasi pemantau kebiasaan yang dipakai berdua: tiap orang punya habit sendiri, saling memantau progres, dan hasil mingguan menentukan reward atau punishment. Dibangun dengan **Laravel**, **PostgreSQL**, dan autentikasi token **Sanctum**.

> **Status:** dalam pengembangan aktif. Backend Fase 1a sampai 1e selesai dan teruji, belum di-deploy.
> Klien: [habit-tracker-app](https://github.com/oOdi980/habit-tracker-app) (Flutter).

## Fitur

- **Sesi privat** berisi tepat 2 orang. Akun berikutnya mendaftar lewat kode undangan, dan timezone sesi dikunci saat dibuat.
- **Habit personal dan habit bersama.** Pada habit bersama, hari baru dihitung bila keduanya check-in di hari yang sama.
- **Check-in dengan detail tugas** (misalnya push up, pull up) dan target per tugas. Hari baru terhitung check-in hanya bila semua tugas mencapai batasnya.
- **Evaluasi mingguan otomatis** (minggu dihitung Senin sampai Minggu 23.59), dijalankan scheduler pada Senin 00:10 waktu lokal sesi.
- **Izin libur habit** per minggu dengan kuota bulanan, perlu persetujuan partner.
- **Daftar reward dan punishment** yang diisi bersama. Punishment diacak server dan diselesaikan setelah disetujui partner.
- **Laporan progres** mingguan, bulanan, dan tahunan.

## Stack

Laravel (PHP) · PostgreSQL · Laravel Sanctum · PHPUnit

## Keputusan desain

- **Aturan data dijaga di database.** Selain validasi di aplikasi, invarian penting dipasang sebagai `CHECK` constraint PostgreSQL.
- **Scheduler yang tahan telat.** Scheduler berjalan tiap 10 menit dan mengejar evaluasi yang terlewat, jadi server mati sebentar tidak menghilangkan evaluasi mingguan.
- **Batas hari tegas.** Check-in ditutup tepat 23:59 waktu sesi, tanpa masa tenggang.
- **Perubahan habit berlaku Senin depan**, supaya minggu yang sedang berjalan tidak berubah di tengah jalan.
- **Item reward/punishment baru harus disetujui partner** dan tidak ikut diacak untuk punishment yang sudah diputuskan, supaya tidak ada yang bisa menyelipkan hukuman ringan lalu kena acak.

## Menjalankan secara lokal

Prasyarat: PHP, Composer, PostgreSQL.

```bash
git clone https://github.com/oOdi980/habit-tracker-api.git
cd habit-tracker-api
composer install
cp .env.example .env
php artisan key:generate
```

Atur koneksi PostgreSQL di `.env` (`DB_CONNECTION=pgsql`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`), lalu:

```bash
php artisan migrate
php artisan serve
```

Untuk mencoba scheduler secara lokal: `php artisan schedule:work`.

Daftar endpoint ada di `routes/api.php`.

## Testing

```bash
php artisan test
```

Per November 2026, 215 tes backend lulus. Tes memakai database terpisah, jadi buat dulu database tes dan sesuaikan konfigurasinya di `phpunit.xml`.

## Rencana

- Deployment ke hosting
- Pengingat (reminder) dua lapis: statis dan dinamis
