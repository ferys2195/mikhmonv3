# [BUG] Voucher Validity / Expired Hotspot Tidak Bekerja pada MikroTik RouterOS v7 (Khususnya ROS 7.19.6)

## 1. Informasi Singkat (Summary)
Pada RouterOS v7 (khususnya versi **7.19.6** dan rilis v7.x lainnya), voucher hotspot yang dibuat dengan batas masa berlaku (*Validity*, contoh: `1d`, `3h`, `30d`) tidak terputus (*disconnect*) atau terhapus (*remove*) saat masa berlakunya telah habis. Pengguna dapat terus mengakses internet tanpa batas waktu meskipun telah melewati waktu kedaluwarsa yang ditentukan pada profil voucher.

Akar permasalahan terkonfirmasi berasal dari **perbedaan format tanggal sistem (`/system clock get date`) dan format timestamp scheduler antara RouterOS v6 dan RouterOS v7**, yang menyebabkan script internal Mikhmon (`on-login`, scheduler `$bgservice`, pengecekan status, dan laporan penjualan) gagal melakukan *parsing* string tanggal dan waktu.

---

## 2. Lingkungan Terpengaruh (Environment)
- **Aplikasi**: MIKHMON V3 (v3.20)
- **Versi RouterOS Terdampak**: MikroTik RouterOS v7.x (teruji secara spesifik pada **v7.19.6**)
- **Versi RouterOS Normal**: MikroTik RouterOS v6.x (misal: v6.48.x, v6.49.x)
- **Fitur Terdampak**:
  - Hotspot User Profile (*Expired Mode*: `Remove`, `Notice`, `Remove & Record`, `Notice & Record`)
  - Validity Voucher Hotspot
  - Monitor Profile Scheduler (`$bgservice`)
  - Halaman Cek Status Voucher (`status/index.php`)
  - Laporan Penjualan & User Log (`report/selling.php`, `report/userlog.php`)

---

## 3. Gejala Masalah (Symptoms)
1. **User Tidak Pernah Terputus / Terhapus**:
   Ketika waktu voucher habis (misal voucher 2 jam atau 1 hari), user tidak di-*kick* dari `/ip hotspot active` dan user tidak di-*remove* dari `/ip hotspot user`.
2. **Komentar User Tidak Terformat / Gagal Diverifikasi**:
   Komentar tanggal kedaluwarsa pada `/ip hotspot user` tersimpan dalam format baru (`2026-10-18 09:00:00`) atau gagal diolah.
3. **Scheduler Monitor Tetap Berjalan Tetapi Tidak Menghapus Apapun**:
   Scheduler monitor profile (misal `default_monitor`) berjalan setiap 2 menit, namun tidak ada aksi eksekusi pembersihan user kedaluwarsa.
4. **Halaman Status Menampilkan "Not Registered"**:
   Saat voucher dicek di portal `status/index.php`, sistem melaporkan voucher *not registered* karena validasi format karakter pemisah slash (`/`) gagal.
5. **Laporan Penjualan Bulan Tidak Valid**:
   Metadata script laporan penjualan tersimpan dengan format nama/owner bulan yang cacat (misal `owner="202-18"` alih-alih `"oct2026"`).

---

## 4. Analisis Akar Masalah (Root Cause Analysis - RCA)

Perubahan mendasar pada RouterOS v7 adalah standardisasi format tanggal dan waktu mengikuti standar ISO-8601 (`yyyy-mm-dd`). Sedangkan RouterOS v6 menggunakan format tanggal lama (`mmm/dd/yyyy`).

| Parameter / Properti | RouterOS v6 | RouterOS v7 (7.19.6) |
|---|---|---|
| `/system clock get date` | `oct/18/2026` (`mmm/dd/yyyy`) | `2026-10-18` (`yyyy-mm-dd`) |
| Panjang string tanggal | 11 karakter | 10 karakter |
| Karakter pemisah tanggal | Garis miring (`/`) | Tanda minus/strip (`-`) |
| Scheduler `next-run` | `oct/19/2026 09:00:00` / `oct/19 09:00:00` | `2026-10-19 09:00:00` |
| Separator jam / waktu | Titik dua (`:`) | Titik dua (`:`) |

Perbedaan ini mengakibatkan kegagalan di beberapa titik skrip Mikhmon:

### A. Kegagalan Skrip Monitor Profile Scheduler (`$bgservice`)
File: `hotspot/adduserprofile.php` (baris 86) & `hotspot/userprofilebyname.php` (baris 163).

Skrip `$bgservice` yang diinjeksi ke `/system scheduler` bertugas melakukan evaluasi berkala terhadap tanggal & jam kedaluwarsa:

```routeros
:foreach i in [ /ip hotspot user find where profile="'.$name.'" ] do={ 
  :local comment [ /ip hotspot user get $i comment]; 
  :local name [ /ip hotspot user get $i name]; 
  :local gettime [:pic $comment 12 20]; 
  :if ([:pic $comment 3] = "/" and [:pic $comment 6] = "/") do={
    :local expd [$dateint d=$comment] ; 
    :local expt [$timeint t=$gettime] ; 
    :if (($expd < $today and $expt < $curtime) or ($expd < $today and $expt > $curtime) or ($expd = $today and $expt < $curtime)) do={ 
      [ /ip hotspot user '.$mode.' $i ]; 
      [ /ip hotspot active remove [find where user=$name] ];
    }
  }
}
```

#### Masalah Utama pada `$bgservice`:
1. **Validasi Karakter Garis Miring Gagal Total**:
   - Skrip mengecek: `:if ([:pic $comment 3] = "/" and [:pic $comment 6] = "/")`
   - Pada RouterOS v6, format komentar adalah `oct/18/2026 09:00:00`. Karakter ke-3 (`/`) dan ke-6 (`/`) sesuai.
   - Pada RouterOS v7, format komentar adalah `2026-10-18 09:00:00`. Karakter ke-3 adalah `'6'` dan karakter ke-6 adalah `'0'`.
   - **Kondisi IF selalu bernilai FALSE**, sehingga blok eksekusi penghapusan user (`user remove` / `active remove`) **tidak pernah dieksekusi sama sekali!**
2. **Fungsi Konversi Tanggal `$dateint` Rusak**:
   ```routeros
   :local montharray ( "jan","feb","mar","apr","may","jun","jul","aug","sep","oct","nov","dec" );
   :local days [ :pick $d 4 6 ];
   :local month [ :pick $d 0 3 ];
   :local year [ :pick $d 7 11 ];
   ```
   - Skrip mengasumsikan karakter 0-3 adalah nama bulan teks (`oct`). Di ROS v7, indeks 0-3 menghasilkan `202`.
   - Nilai `monthint` dari `[:find $montharray $month]` mengembalikan *nil*, sehingga `$today` dan `$expd` menghasilkan nilai rusak/kosong.
3. **Offset Jam (`gettime`) Bergeser**:
   - `:local gettime [:pic $comment 12 20];`
   - Di ROS v6 panjang tanggal adalah 11 karakter + 1 spasi, sehingga waktu jam dimulai pada indeks 12.
   - Di ROS v7 panjang tanggal adalah 10 karakter + 1 spasi, sehingga jam dimulai pada indeks 11. Mengambil indeks 12-20 memotong digit pertama jam (`09:00:00` terambil menjadi `9:00:00`).

---

### B. Kegagalan Skrip Profile `on-login`
File: `hotspot/adduserprofile.php` (baris 65) & `hotspot/userprofilebyname.php` (baris 142).

```routeros
:local date [ /system clock get date ];
:local year [ :pick $date 7 11 ];
:local month [ :pick $date 0 3 ]; 
/sys sch add name="$user" disable=no start-date=$date interval="' . $validity . '"; 
:delay 5s; 
:local exp [ /sys sch get [ /sys sch find where name="$user" ] next-run]; 
:local getxp [len $exp]; 
:if ($getxp = 15) do={ ... }; 
:if ($getxp = 8) do={ ... }; 
:if ($getxp > 15) do={ ... };
```

#### Masalah Utama pada `on-login`:
1. Nilai variabel `:local year [ :pick $date 7 11 ]` pada ROS v7 (`2026-10-18`) menghasilkan `-18`, bukan tahun `2026`.
2. Nilai variabel `:local month [ :pick $date 0 3 ]` menghasilkan `202`, bukan nama bulan `oct`.
3. Pembuatan pencatatan script laporan penjualan:
   ```routeros
   /system script add ... owner="$month$year" source="$date" comment="mikhmon"
   ```
   menghasilkan nilai `owner="202-18"`, sehingga query laporan penjualan bulanan di Mikhmon tidak dapat menemukan data.

---

### C. Kegagalan pada Cek Status Voucher Web (`status/index.php`)
File: `status/index.php` (baris 122):

```php
if ($user == "" || (substr($exp,3,1) != "/" && substr($exp,6,1) != "/")) {
    echo "<h3 class='text-center'>User <i style='color:#008CCA;'>$name</i> $title[9]</h3>";
}
```
PHP memeriksa apakah karakter ke-3 dan ke-6 adalah `/`. Pada RouterOS v7, format tanggal komentar adalah `2026-10-18 09:00:00`, sehingga halaman cek status selalu menampilkan bahwa voucher tidak terdaftar (*not registered*).

---

## 5. Langkah-Langkah Mereproduksi Masalah (Steps to Reproduce)
1. Sambungkan Mikhmon v3.20 ke MikroTik yang menjalankan **RouterOS v7.19.6**.
2. Buat profil Hotspot baru di Mikhmon:
   - *Validity*: `1h` (atau `1d`)
   - *Expired Mode*: `Remove` (atau `Remove & Record`)
3. Generate voucher hotspot baru menggunakan profil tersebut.
4. Login menggunakan voucher melalui halaman login Hotspot MikroTik.
5. Periksa komentar user pada `/ip hotspot user`:
   - Di ROS 7, komentar berubah menjadi format ISO (contoh `2026-10-18 09:00:00`).
6. Tunggu hingga masa berlaku voucher melewati batas waktu (misal setelah 1 jam / 1 hari).
7. **Hasil yang Terjadi**:
   - User tetap aktif di `/ip hotspot active`.
   - User tidak dihapus dari `/ip hotspot user`.
   - Pengguna masih dapat berinternet ria secara gratis tanpa batasan waktu.
8. **Hasil yang Diharapkan**:
   - Setelah waktu *validity* habis, user langsung diputus dari `/ip hotspot active` dan akun dihapus/dinonaktifkan sesuai *Expired Mode*.

---

## 6. Usulan Solusi & Perbaikan (Proposed Fixes)

Untuk memastikan kompatibilitas penuh dengan RouterOS v7 tanpa merusak kompatibilitas RouterOS v6, diperlukan pendekatan yang adaptif terhadap format tanggal.

### Opsi Solusi 1: Deteksi Versi RouterOS / Format Tanggal pada RouterOS Script
Di dalam script RouterOS, kita dapat mendeteksi versi RouterOS atau mendeteksi apakah tanggal mengandung strip (`-`) atau slash (`/`).

Contoh logika parsing adaptif pada `$bgservice`:
```routeros
:local date [ /system clock get date ];
:local time [ /system clock get time ];
:local today;

# Cek apakah tanggal menggunakan format ISO (ROS v7: YYYY-MM-DD) atau legacy (ROS v6: mmm/dd/yyyy)
:if ([:find $date "-"] > 0) do={
  # RouterOS v7: "2026-10-18"
  :local yy [:pick $date 0 4];
  :local mm [:pick $date 5 7];
  :local dd [:pick $date 8 10];
  :set today [:tonum ("$yy$mm$dd")];
} else={
  # RouterOS v6: "oct/18/2026"
  :local montharray ( "jan","feb","mar","apr","may","jun","jul","aug","sep","oct","nov","dec" );
  :local dd [:pick $date 4 6];
  :local mm ([ :find $montharray [:pick $date 0 3] ] + 1);
  :if ([:len $mm] = 1) do={ :set mm ("0" . $mm); };
  :local yy [:pick $date 7 11];
  :set today [:tonum ("$yy$mm$dd")];
};
```

Pada pengecekan komentar user di loop `$bgservice`:
```routeros
:foreach i in [ /ip hotspot user find where profile="'.$name.'" ] do={
  :local comment [ /ip hotspot user get $i comment];
  :local name [ /ip hotspot user get $i name];
  
  # Cek jika format ROS 7 (terdapat tanda strip di indeks 4 dan 7)
  :if ([:pick $comment 4] = "-" and [:pick $comment 7] = "-") do={
    :local expyy [:pick $comment 0 4];
    :local expmm [:pick $comment 5 7];
    :local expdd [:pick $comment 8 10];
    :local expd [:tonum ("$expyy$expmm$expdd")];
    :local gettime [:pick $comment 11 19];
    :local expt (([:pick $gettime 0 2] * 60) + [:pick $gettime 3 5]);
    :if (($expd < $today) or ($expd = $today and $expt <= $curtime)) do={
      [ /ip hotspot user '.$mode.' $i ];
      [ /ip hotspot active remove [find where user=$name] ];
    }
  } else={
    # Fallback logika ROS v6 (garis miring pada indeks 3 dan 6)
    :if ([:pick $comment 3] = "/" and [:pick $comment 6] = "/") do={
      :local expd [$dateint d=$comment];
      :local gettime [:pic $comment 12 20];
      :local expt [$timeint t=$gettime];
      :if (($expd < $today and $expt < $curtime) or ($expd < $today and $expt > $curtime) or ($expd = $today and $expt < $curtime)) do={
        [ /ip hotspot user '.$mode.' $i ];
        [ /ip hotspot active remove [find where user=$name] ];
      }
    }
  }
}
```

### Opsi Solusi 2: Penyesuaian di Sisi PHP Mikhmon
1. **`status/index.php`**:
   Ubah validasi format tanggal agar menerima pemisah `-` (ROS 7) dan `/` (ROS 6):
   ```php
   $is_ros6 = (substr($exp, 3, 1) == "/" && substr($exp, 6, 1) == "/");
   $is_ros7 = (substr($exp, 4, 1) == "-" && substr($exp, 7, 1) == "-");
   if ($user == "" || (!$is_ros6 && !$is_ros7)) {
       // user belum aktif / expired
   }
   ```
2. **`report/selling.php` & `report/print.php`**:
   Dukung pemecahan tanggal baik dengan `-` maupun `/`.

---

## 7. Referensi File Kode Terkait
- [adduserprofile.php](file:///e:/Projects/InProgress/mikhmonv3/hotspot/adduserprofile.php#L63-L87)
- [userprofilebyname.php](file:///e:/Projects/InProgress/mikhmonv3/hotspot/userprofilebyname.php#L140-L164)
- [generateuser.php](file:///e:/Projects/InProgress/mikhmonv3/hotspot/generateuser.php#L98)
- [status/index.php](file:///e:/Projects/InProgress/mikhmonv3/status/index.php#L122)
- [report/selling.php](file:///e:/Projects/InProgress/mikhmonv3/report/selling.php#L27)
