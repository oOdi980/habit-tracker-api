# Fase 1 (e): detail check-in (tugas harian) — backend + tes

Habit bermode **checklist** punya daftar tugas (mis. hammer curl >= 30 kali, push up >= 50, lari >= 3 km).
Check-in hari itu **tercatat otomatis** saat SEMUA tugas sudah mencapai targetnya. Tidak ada tombol check-in lagi
untuk habit seperti ini. Habit **simple** (tidur, minum obat) tetap satu tap seperti sebelumnya.

Baris di `habit_logs` tetap berarti "hari ini lengkap", jadi evaluasi mingguan, izin, punishment, dan laporan
**tidak diubah sama sekali**.

## Cara pasang (Laragon, di folder habit-tracker-api)

1. Salin isi zip ke project (timpa file yang sama; ada 1 migrasi baru, 3 model baru, 2 service baru, dst).
2. `php artisan migrate` (database dev `habit_tracker`).
3. `php artisan test` — harapan: **215 lulus** (172 lama + 43 baru: 15 di HabitTaskTest, 24 di TaskProgressTest, 4 di TaskNameTest).
4. Kalau ada yang merah, kirim keluaran `php artisan test --filter=NamaTes` (seperti sebelumnya).

Catatan jujur: Laravel tidak bisa dijalankan di lingkungan gw, jadi tes PHP belum pernah jalan. Yang SUDAH gw uji di Postgres sungguhan
(16.x): migrasi naik-turun-naik, semua CHECK constraint, indeks unik parsial, kunci idempotensi, dan query papan/riwayat.
Semua file lolos `php -l`.

Habit lama di database dev (Olahraga/Raga dll.) otomatis jadi `simple`. Mode tidak bisa diubah setelah dibuat
(sama seperti scope), jadi untuk mencoba checklist buat habit baru (lewat form Flutter di tahap 2).

## Aturan

- Tugas: nama (<= 60 karakter, "Push Up" == "push up"), jenis ukuran, target HARIAN (>=).
  Jenis: `count` (kali), `minutes`, `meters`, `steps`. Semua satuan dasar bilangan bulat (jarak dalam METER, beban dalam GRAM).
  Maks 10 tugas per orang per habit. Batas target = batas satu entri: count 10000, minutes 1440, meters 1.000.000, steps 200.000.
- Progres menumpuk dalam sehari (push up 20 + 20 + 10 = 50). Boleh melebihi target.
- Ceklis (`complete`) = tercatat sebesar SISA target (source `tap`). Angka sendiri = source `manual` (penanda "angka sebenarnya").
- "Ubah angka" mengganti total hari ini persis; 0/DELETE mengosongkan. Kalau total jatuh di bawah target, check-in hari itu hilang otomatis.
- Hanya HARI INI yang bisa diubah (waktu circle). Hari kemarin terkunci. Pengecualian: antrean offline dengan `client_ts` sah
  di dalam toleransi 10 menit saat pergantian hari (sama seperti check-in biasa).
- Mengubah daftar tugas di habit yang sudah berjalan **berlaku Senin depan** (versi lama ditutup, versi baru dibuka; riwayat tidak berubah).
  Habit yang belum mulai: diganti langsung. Orang yang belum punya tugas berlaku (mis. partner di habit bersama): langsung berlaku hari ini.
- Habit bersama: tiap anggota punya daftar sendiri (boleh beda). Hari dihitung kalau keduanya lengkap (aturan together yang sudah ada).
- Catatan bebas per orang per hari, maks 500 karakter, hanya habit checklist.
- Check-in langsung (`POST/DELETE /habits/{id}/check-in`) ke habit checklist ditolak 422 (key `habit`).

## API baru (untuk Flutter tahap 2)

Semua butuh token; non-anggota 404; partner di habit personal: 403 untuk penulisan.

| Metode | Path | Isi |
|---|---|---|
| POST | `/circles/{id}/habits` | tambah `mode` ("simple" default / "checklist") dan `tasks:[{name,metric,target}]` (wajib untuk checklist, dilarang untuk simple) |
| GET | `/habits/{id}/tasks` | `{habit_id, mode, today, next_monday, members:[{user_id, current:[...], next:[...], has_pending_change}]}` |
| PUT | `/habits/{id}/tasks` | `{tasks:[{name,metric,target}]}` = daftar LENGKAP milik pemanggil; respons sama seperti GET |
| POST | `/tasks/{id}/entries` | `{complete:true}` ATAU `{amount:int>=1}`; opsional `weight_g` (hanya count), `client_key`, `client_ts`. 201 bila ada yang baru, 200 bila tidak |
| PUT | `/tasks/{id}/progress` | `{amount:int>=0, weight_g?}` total hari ini diganti persis |
| DELETE | `/tasks/{id}/progress` | kosongkan hari ini |
| PUT | `/habits/{id}/note` | `{note: string<=500 | null}` (kunci wajib ada) |
| GET | `/habits/{id}/history?before=YYYY-MM-DD&limit=1..31` | `{habit_id, days:[{date, members:[...]}], next_before}` terbaru dulu |

Respons penulisan: `{created?, day:{habit_id, log_date, user_id, complete, note, tasks:[...], progress:{week_start,done,target,achieved}}}`.
Satu tugas: `{id, name, metric, target, total, done, has_manual, max_weight_g}`.

Papan `GET /circles/{id}/today`: tiap habit kini punya `mode` dan `detail` (`null` untuk simple; untuk checklist
`[{user_id, complete, note, tasks:[...]}]` per anggota yang ikut — kedua anggota terlihat di habit bersama).

## Yang sengaja BELUM ada

- Flutter (tahap 2): form habit dengan daftar tugas, tile berisi ceklis dan +angka, layar riwayat.
- Analisis (beban/volume per gerakan, rekor, frekuensi, vs partner): data sudah tersimpan terstruktur; dibangun setelah ada data.
- Target mingguan kumulatif ("lari 15 km per minggu"); saat ini target hanya harian.
- Membaca langkah otomatis dari HP (Health Connect Android / tidak mungkin di PWA iPhone).
