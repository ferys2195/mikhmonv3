# [BUG] Komentar (Comment) User Hotspot Active Tidak Berubah Menjadi Tanggal Kedaluwarsa Akibat Syntax Error pada Script on-login Profile

## 1. Ringkasan Masalah (Summary)
Saat voucher hotspot melakukan *login* ke MikroTik, komentar (*comment*) pada user di `/ip hotspot user` maupun sesi login aktif di `/ip hotspot active` tidak berubah menjadi tanggal kedaluwarsa (*expiration date*). Komentar voucher tetap berupa kode generate awal (contoh: `vc-xxx-10.19.26-...` atau `up-xxx-...`) atau kosong.

Akibatnya, script monitor background Mikhmon (`$bgservice` pada `/system scheduler`) yang bertugas mengevaluasi masa berlaku voucher tidak pernah mendeteksi tanggal kedaluwarsa pada user tersebut. Hal ini menyebabkan voucher hotspot **tidak pernah kedaluwarsa (*never expired*)**, pengguna tidak pernah terputus (*never disconnected*), dan akun voucher tidak pernah terhapus (*never removed*) dari MikroTik.

Akar masalah terkonfirmasi berasal dari **kesalahan penulisan karakter escape (`\"`) pada awal string `$onlogin`** di file [adduserprofile.php](file:///e:/Projects/InProgress/mikhmonv3/hotspot/adduserprofile.php#L65) dan [userprofilebyname.php](file:///e:/Projects/InProgress/mikhmonv3/hotspot/userprofilebyname.php#L142).

---

## 2. Gejala dan Dampak (Symptoms & Impact)

1. **Komentar User Tidak Berubah Menjadi Tanggal**:
   - Setelah voucher berhasil login dan statusnya aktif di `/ip hotspot active`, kolom *Comment* pada `/ip hotspot active` dan `/ip hotspot user` tidak berubah menjadi tanggal/jam kedaluwarsa (misal format ROS v7: `2026-10-19 12:00:00` atau ROS v6: `oct/19/2026 12:00:00`).
   - Komentar tetap mempertahankan string awal hasil generate Mikhmon (`vc-` / `up-`).
2. **Scheduler Monitor `$bgservice` Tidak Berfungsi**:
   - Script scheduler monitor berjalan periodik (setiap 2 menit), namun selalu mengabaikan user aktif karena kondisi format tanggal (`isros7` maupun `isros6`) pada komentar user selalu bernilai `false`.
3. **Voucher Tidak Pernah Expired**:
   - Paket voucher berdurasi (misal 1 jam, 1 hari) dapat terus digunakan tanpa batas waktu meskipun masa aktif riil sudah terlewati.
4. **Log MikroTik Menampilkan Script Error**:
   - Pada MikroTik Log (`/log print where topics~"error"` atau `topics~"script"`), muncul pesan kesalahan kompilasi/eksekusi script:
     `script error: syntax error (line 1 column ...)` saat user hotspot pertama kali login.

---

## 3. Lingkungan Terpengaruh (Environment)
- **Aplikasi**: MIKHMON V3 (v3.20 / v3.21)
- **RouterOS**: MikroTik RouterOS v6.x dan RouterOS v7.x (semua versi yang menggunakan User Profile hasil generate Mikhmon)
- **File Terkait**:
  - [hotspot/adduserprofile.php](file:///e:/Projects/InProgress/mikhmonv3/hotspot/adduserprofile.php#L65)
  - [hotspot/userprofilebyname.php](file:///e:/Projects/InProgress/mikhmonv3/hotspot/userprofilebyname.php#L142)

---

## 4. Analisis Akar Masalah (Root Cause Analysis - RCA)

### A. Kesalahan Escape Quote `\"` pada String PHP Single-Quote
Di file [hotspot/adduserprofile.php](file:///e:/Projects/InProgress/mikhmonv3/hotspot/adduserprofile.php#L65) dan [hotspot/userprofilebyname.php](file:///e:/Projects/InProgress/mikhmonv3/hotspot/userprofilebyname.php#L142), kode pembentukan script `on-login` tertulis sebagai berikut:

```php
$onlogin = ':put (",'.$expmode.',' . $price . ',' . $validity . ','.$sprice.',,' . $getlock . ',\"); {:local comment [ /ip hotspot user get [/ip hotspot user find where name="$user"] comment]; ...';
```

Perhatikan bagian:
```php
' ... ' . $getlock . ',\"); { ... '
```

1. **Perilaku di Sisi PHP**:
   - Variabel `$onlogin` dideklarasikan menggunakan string bertanda petik tunggal (`'...'`).
   - Dalam aturan PHP, tanda *backslash* di dalam *single quote* hanya meng-*escape* karakter `'` (`\'`) dan `\` (`\\`).
   - Penulisan `\"` di dalam *single quote* **TIDAK** dihilangkan atau diubah menjadi `"`, melainkan di-output secara literal sebagai karakter garis miring terbalik diikuti tanda petik dua: `\"`.
   - String yang dihasilkan dan dikirim ke MikroTik API via `/ip/hotspot/user/profile/add` atau `/set` bernilai:
     ```routeros
     :put (",rem,10000,1d,0,,Disable,\"); {:local comment ...
     ```

2. **Perilaku di Sisi Parser RouterOS Script**:
   - Di RouterOS script engine, tanda `\` adalah karakter *escape sequence*.
   - Dalam string RouterOS, `\"` merepresentasikan **literal double quote** (tanda petik ganda sebagai teks di dalam string, bukan penutup string).
   - Akibatnya, parser RouterOS menganggap string `:put ("...` **belum ditutup** pada karakter `\"`.
   - Parser terus membaca sisa script yang ada di belakangnya sebagai isi string hingga menemukan tanda petik berikutnya (misal pada `name="$user"`).
   - Ini memicu **Fatal Syntax Error**:
     ```text
     syntax error (line 1 column 38)
     expected end of command
     ```
   - RouterOS langsung menghentikan dan membatalkan eksekusi script `on-login`.

3. **Akibat Terputusnya Eksekusi Script**:
   Karena script gagal dieksekusi sejak baris pertama, seluruh baris logika di bawahnya **tidak pernah dijalankan**:
   - Logika pembuatan scheduler temporary:
     ```routeros
     /sys sch add name="$user" disable=no start-date=$date interval="1d";
     ```
   - Pengambilan nilai `next-run`:
     ```routeros
     :local exp [ /sys sch get [ /sys sch find where name="$user" ] next-run];
     ```
   - Perubahan komentar user:
     ```routeros
     /ip hotspot user set comment="$exp" [find where name="$user"];
     ```
   - Komentar user tetap utuh berupa `vc-...` atau `up-...` dan tidak pernah berubah menjadi tanggal kedaluwarsa.

---

### B. Potensi Kegagalan Lain pada Alur Script `on-login`

Selain masalah utama sintaks `\"`, terdapat potensi *edge-case* yang dapat menyebabkan kegagalan perubahan komentar pada skenario tertentu:

1. **Tabrakan Nama Scheduler (*Scheduler Name Collision*)**:
   Jika sebelumnya voucher pernah mencoba login dan gagal di tengah jalan saat `:delay 5s`, scheduler dengan nama `name="$user"` mungkin masih tersisa di `/system scheduler`. Saat user login kembali, perintah `/sys sch add name="$user"` akan menghasilkan error `failure: already have such item` dan membatalkan proses berikutnya.
   > **Mitigasi**: Selalu jalankan pembersihan scheduler lama terlebih dahulu:
   > `/sys sch remove [find where name="$user"];` sebelum melakukan `add`.

2. **Delay dan Pengambilan Properti `next-run` pada RouterOS v7**:
   Pada RouterOS v7, perhitungan `next-run` memerlukan jeda waktu singkat. Jika delay terlalu cepat atau scheduler belum aktif, `:local exp` dapat mengembalikan nilai kosong (*nil*). Penggunaan `:delay 5s;` sudah tepat, namun harus dipastikan scheduler dihapus kembali pada akhir script.

3. **Sinkronisasi Profil pada MikroTik Eksisting**:
   Memperbaiki file PHP di Mikhmon hanya akan memperbaiki profil yang baru dibuat atau disimpan ulang (*Save Profile*). Profil yang sudah terlanjur tersimpan di MikroTik masih menyimpan script lama yang rusak dan harus di-update ulang dari Mikhmon.

---

## 5. Langkah-Langkah Mereproduksi Masalah (Steps to Reproduce)

1. Buka Mikhmon dan login ke router MikroTik.
2. Masuk ke menu **Hotspot** -> **User Profiles** -> **Add Profile** (atau buka profil yang sudah ada).
3. Buat profil dengan:
   - *Validity*: `1d`
   - *Expired Mode*: `Remove` atau `Remove & Record`
4. Masuk ke MikroTik Winbox -> **IP** -> **Hotspot** -> **User Profiles**, lalu buka profil yang baru saja dibuat.
5. Periksa tab **Script** -> **On Login**:
   - Perhatikan baris pertama script:
     ```routeros
     :put (",rem,0,1d,0,,Disable,\"); {:local comment ...
     ```
     Terdapat tanda `\");` yang merusak sintaks string.
6. Buat voucher baru menggunakan profil tersebut melalui menu **Generate User** (misal user: `vc-test`).
7. Login menggunakan akun voucher tersebut pada Hotspot login page.
8. Periksa di Winbox pada menu **IP** -> **Hotspot** -> **Active** dan **Users**:
   - Kolom **Comment** pada user `vc-test` tetap berisi kode awal (contoh `vc-xxx-10.19.26-...`).
   - Komentar tidak berubah menjadi format tanggal `yyyy-mm-dd hh:mm:ss` atau `mmm/dd/yyyy hh:mm:ss`.
9. Cek log MikroTik di menu **Log**:
   - Ditemukan entri error script `on-login`.

---

## 6. Rencana Perbaikan (Proposed Fix)

### Perbaikan 1: Koreksi String Penutup pada `adduserprofile.php` & `userprofilebyname.php`
Hapus karakter *backslash* (`\`) sebelum tanda petik dua penutup pada pembentukan `$onlogin`.

#### File: `hotspot/adduserprofile.php` (Baris 65)
```diff
-    $onlogin = ':put (",'.$expmode.',' . $price . ',' . $validity . ','.$sprice.',,' . $getlock . ',\"); {:local comment [ /ip hotspot user get [/ip hotspot user find where name="$user"] comment]; :local ucode [:pick $comment 0 2]; :if ($ucode = "vc" or $ucode = "up" or $comment = "") do={ :local date [ /system clock get date ];:local year;:local month;:local day;:local sdate;:if ([:find $date "-"] > 0) do={:set year [:pick $date 0 4];:local montharray ( "jan","feb","mar","apr","may","jun","jul","aug","sep","oct","nov","dec" );:set month [:pick $montharray ([:tonum [:pick $date 5 7]] - 1)];:set day [:pick $date 8 10];:set sdate ("$month/$day/$year");} else={:set year [ :pick $date 7 11 ];:set month [ :pick $date 0 3 ];:set day [ :pick $date 4 6 ];:set sdate $date;}; /sys sch add name="$user" disable=no start-date=$date interval="' . $validity . '"; :delay 5s; :local exp [ /sys sch get [ /sys sch find where name="$user" ] next-run]; :local getxp [:len $exp]; :if ($getxp = 15) do={ :local d [:pick $exp 0 6]; :local t [:pick $exp 7 16]; :local s ("/"); :local exp ("$d$s$year $t"); /ip hotspot user set comment="$exp" [find where name="$user"];}; :if ($getxp = 8) do={ /ip hotspot user set comment="$date $exp" [find where name="$user"];}; :if ($getxp > 15) do={ /ip hotspot user set comment="$exp" [find where name="$user"];};:delay 5s; /sys sch remove [find where name="$user"]';
+    $onlogin = ':put (",'.$expmode.',' . $price . ',' . $validity . ','.$sprice.',,' . $getlock . ',"); {:local comment [ /ip hotspot user get [/ip hotspot user find where name="$user"] comment]; :local ucode [:pick $comment 0 2]; :if ($ucode = "vc" or $ucode = "up" or $comment = "") do={ :local date [ /system clock get date ];:local year;:local month;:local day;:local sdate;:if ([:find $date "-"] > 0) do={:set year [:pick $date 0 4];:local montharray ( "jan","feb","mar","apr","may","jun","jul","aug","sep","oct","nov","dec" );:set month [:pick $montharray ([:tonum [:pick $date 5 7]] - 1)];:set day [:pick $date 8 10];:set sdate ("$month/$day/$year");} else={:set year [ :pick $date 7 11 ];:set month [ :pick $date 0 3 ];:set day [ :pick $date 4 6 ];:set sdate $date;}; /sys sch remove [find where name="$user"]; /sys sch add name="$user" disable=no start-date=$date interval="' . $validity . '"; :delay 5s; :local exp [ /sys sch get [ /sys sch find where name="$user" ] next-run]; :local getxp [:len $exp]; :if ($getxp = 15) do={ :local d [:pick $exp 0 6]; :local t [:pick $exp 7 16]; :local s ("/"); :local exp ("$d$s$year $t"); /ip hotspot user set comment="$exp" [find where name="$user"];}; :if ($getxp = 8) do={ /ip hotspot user set comment="$date $exp" [find where name="$user"];}; :if ($getxp > 15) do={ /ip hotspot user set comment="$exp" [find where name="$user"];};:delay 5s; /sys sch remove [find where name="$user"]';
```

#### File: `hotspot/userprofilebyname.php` (Baris 142)
```diff
-    $onlogin = ':put (",'.$expmode.',' . $price . ',' . $validity . ','.$sprice.',,' . $getlock . ',\"); {:local comment [ /ip hotspot user get [/ip hotspot user find where name="$user"] comment]; :local ucode [:pick $comment 0 2]; :if ($ucode = "vc" or $ucode = "up" or $comment = "") do={ :local date [ /system clock get date ];:local year;:local month;:local day;:local sdate;:if ([:find $date "-"] > 0) do={:set year [:pick $date 0 4];:local montharray ( "jan","feb","mar","apr","may","jun","jul","aug","sep","oct","nov","dec" );:set month [:pick $montharray ([:tonum [:pick $date 5 7]] - 1)];:set day [:pick $date 8 10];:set sdate ("$month/$day/$year");} else={:set year [ :pick $date 7 11 ];:set month [ :pick $date 0 3 ];:set day [ :pick $date 4 6 ];:set sdate $date;}; /sys sch add name="$user" disable=no start-date=$date interval="' . $validity . '"; :delay 5s; :local exp [ /sys sch get [ /sys sch find where name="$user" ] next-run]; :local getxp [:len $exp]; :if ($getxp = 15) do={ :local d [:pick $exp 0 6]; :local t [:pick $exp 7 16]; :local s ("/"); :local exp ("$d$s$year $t"); /ip hotspot user set comment="$exp" [find where name="$user"];}; :if ($getxp = 8) do={ /ip hotspot user set comment="$date $exp" [find where name="$user"];}; :if ($getxp > 15) do={ /ip hotspot user set comment="$exp" [find where name="$user"];};:delay 5s; /sys sch remove [find where name="$user"]';
+    $onlogin = ':put (",'.$expmode.',' . $price . ',' . $validity . ','.$sprice.',,' . $getlock . ',"); {:local comment [ /ip hotspot user get [/ip hotspot user find where name="$user"] comment]; :local ucode [:pick $comment 0 2]; :if ($ucode = "vc" or $ucode = "up" or $comment = "") do={ :local date [ /system clock get date ];:local year;:local month;:local day;:local sdate;:if ([:find $date "-"] > 0) do={:set year [:pick $date 0 4];:local montharray ( "jan","feb","mar","apr","may","jun","jul","aug","sep","oct","nov","dec" );:set month [:pick $montharray ([:tonum [:pick $date 5 7]] - 1)];:set day [:pick $date 8 10];:set sdate ("$month/$day/$year");} else={:set year [ :pick $date 7 11 ];:set month [ :pick $date 0 3 ];:set day [ :pick $date 4 6 ];:set sdate $date;}; /sys sch remove [find where name="$user"]; /sys sch add name="$user" disable=no start-date=$date interval="' . $validity . '"; :delay 5s; :local exp [ /sys sch get [ /sys sch find where name="$user" ] next-run]; :local getxp [:len $exp]; :if ($getxp = 15) do={ :local d [:pick $exp 0 6]; :local t [:pick $exp 7 16]; :local s ("/"); :local exp ("$d$s$year $t"); /ip hotspot user set comment="$exp" [find where name="$user"];}; :if ($getxp = 8) do={ /ip hotspot user set comment="$date $exp" [find where name="$user"];}; :if ($getxp > 15) do={ /ip hotspot user set comment="$exp" [find where name="$user"];};:delay 5s; /sys sch remove [find where name="$user"]';
```

*(Catatan: Penambahan `/sys sch remove [find where name="$user"];` sebelum `add` juga disematkan sebagai tindakan preventif terhadap scheduler collision).*

---

### Perbaikan 2: Sinkronisasi Ulang Profil di Router MikroTik
Setelah source code diperbaiki:
1. Masuk ke antarmuka web Mikhmon.
2. Buka menu **Hotspot** -> **User Profiles**.
3. Buka masing-masing profil yang aktif lalu klik tombol **Save** tanpa perlu mengubah konfigurasi.
4. Mikhmon akan mengunggah skrip `on-login` yang baru dan bebas error ke MikroTik RouterOS.

---

## 7. Checklist Verifikasi (Testing & Validation Checklist)

- [x] Kode PHP lolos uji sintaks (`php -l hotspot/adduserprofile.php` & `php -l hotspot/userprofilebyname.php`).
- [ ] Profil baru berhasil dibuat dan profil lama berhasil diperbarui tanpa karakter `\"` di script on-login Winbox.
- [ ] User baru dengan komentar `vc-...` melakukan login:
  - [ ] Log MikroTik tidak lagi menampilkan syntax error.
  - [ ] Komentar pada `/ip hotspot user` berubah menjadi format tanggal dan jam kedaluwarsa.
  - [ ] Komentar pada `/ip hotspot active` menampilkan komentar yang sama.
- [ ] Scheduler `$bgservice` mendeteksi format tanggal kedaluwarsa dengan tepat dan memutus sesi user saat waktu *validity* telah habis.
- [ ] Kompatibel dengan RouterOS v6 (format `mmm/dd/yyyy`) dan RouterOS v7 (format `yyyy-mm-dd`).

---

## 8. Referensi Berkas Terkait
- [hotspot/adduserprofile.php](file:///e:/Projects/InProgress/mikhmonv3/hotspot/adduserprofile.php#L65)
- [hotspot/userprofilebyname.php](file:///e:/Projects/InProgress/mikhmonv3/hotspot/userprofilebyname.php#L142)
- [hotspot/generateuser.php](file:///e:/Projects/InProgress/mikhmonv3/hotspot/generateuser.php#L98)
- [hotspot/hotspotactive.php](file:///e:/Projects/InProgress/mikhmonv3/hotspot/hotspotactive.php#L114)
