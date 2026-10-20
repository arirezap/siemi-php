# Panduan Pemasangan SIEMI PKTJ (PHP + MySQL) — untuk Operator IT

Aplikasi: Sistem Informasi Evaluasi Mutu Internal PKTJ. Front end React (sudah dibangun, berkas statis) + API PHP murni (tanpa Composer) + MySQL/MariaDB.

## 1. Kebutuhan server

- Apache 2.4 dengan `mod_rewrite` dan `AllowOverride All` (aplikasi memakai `.htaccess`). LiteSpeed/cPanel biasa sudah cukup. Untuk Nginx, lihat bagian 9.
- PHP **8.1 atau lebih baru**, ekstensi: `pdo_mysql`, `mbstring`, `json`. (Tidak butuh Composer, GD, cURL, atau ZipArchive.)
- MySQL 5.7+ / 8.x atau MariaDB 10.3+, karakter `utf8mb4`.
- Sertifikat SSL (HTTPS) untuk domain.
- Ruang disk: ± 50 MB awal; folder unggahan akan bertambah.

## 2. Isi paket

```
PANDUAN-IT.md
database/schema.sql   struktur 33 tabel
database/data.sql     data awal (±2 MB, 10.293 baris)
public_html/          akar situs: index.html, assets/, .htaccess, uploads/, api/
public_html/api/      API PHP (index.php, lib/, routes/, config.sample.php)
```

## 3. Buat database

1. Buat database kosong (collation `utf8mb4_unicode_ci`) dan satu pengguna database dengan hak penuh hanya atas database itu.
2. Impor berurutan (phpMyAdmin → Import, atau CLI):

```
mysql -u USER -p NAMA_DB < database/schema.sql
mysql -u USER -p NAMA_DB < database/data.sql
```

Impor `data.sql` memakai kode UTF-8; jika lewat phpMyAdmin dan terkena batas ukuran, gunakan CLI atau naikkan `upload_max_filesize`/`post_max_size` sementara. Jangan mengimpor ulang ke database yang sudah dipakai: `schema.sql` memuat `DROP TABLE` dan akan menghapus isi.

3. Verifikasi: `SELECT COUNT(*) FROM users;` harus **25**; jumlah tabel **33**.

## 4. Unggah berkas

Unggah isi `public_html/` ke akar situs (document root domain), **termasuk berkas tersembunyi `.htaccess`** (di `public_html/`, `public_html/api/`, `public_html/uploads/`). Pastikan ketiganya ikut tersalin.

Bila aplikasi diletakkan di subfolder, tetap berfungsi untuk API, tetapi domain/subdomain khusus lebih disarankan.

## 5. Buat `public_html/api/config.php`

Salin `config.sample.php` menjadi `config.php`, lalu isi:

| Kunci | Nilai |
|---|---|
| `db_host` | biasanya `localhost` |
| `db_port` | `0` (bawaan), isi hanya bila port non-standar |
| `db_name`, `db_user`, `db_pass` | dari langkah 3 |
| `jwt_secret` | teks acak ≥ 32 karakter, mis. `openssl rand -hex 32`. **Rahasia, jangan dibagikan, jangan di-commit.** Mengganti nilai ini memutus seluruh sesi login yang berjalan. |
| `timezone`, `db_timezone` | `Asia/Jakarta`, `+07:00` |
| `upload_dir`, `upload_url` | biarkan bawaan (`../uploads`, `/uploads`) |
| `debug` | **`false`** di produksi |

Izin: `config.php` mode `640` atau `600`.

## 6. Izin folder

- `public_html/uploads/` harus **dapat ditulis** oleh pengguna web server (mis. `755` atau `775` sesuai pemilik proses PHP). Berkas `.htaccess` di dalamnya jangan dihapus: ia melarang eksekusi skrip dari folder unggahan.
- Folder lain cukup dapat dibaca.

## 7. HTTPS

`public_html/.htaccess` memaksa HTTPS (blok "Paksa HTTPS", 3 baris). Pastikan SSL aktif sebelum situs dibuka. Bila TLS dihentikan di proxy/load balancer, header `X-Forwarded-Proto: https` harus diteruskan agar tidak terjadi redirect berulang.

## 8. Pengujian setelah pasang

1. `https://DOMAIN/api/health` → `{"ok":true,...}`
2. `https://DOMAIN/api/health/db` → `{"ok":true,"tabel":33,"pengguna":25,...}`. Jika `ok` false atau galat 500: periksa `config.php` dan log galat PHP.
3. Buka `https://DOMAIN/` → halaman beranda tampil; muat ulang pada alamat dalam (mis. `/masuk`) tidak boleh 404.
4. Masuk dengan akun yang diberikan pemilik aplikasi (kata sandi awal diserahkan terpisah, bukan melalui IT). Buka menu Dashboard, Penetapan Standar, EMI, SPME.
5. Uji unggah berkas pada fitur yang menyediakan lampiran; berkas harus muncul di `public_html/uploads/`.

Jika ada 500: sementara ubah `debug` ke `true`, ulangi tindakan, catat pesan, lalu kembalikan ke `false`.

## 9. Catatan Nginx (bila bukan Apache)

`.htaccess` tidak dibaca. Setara konfigurasinya:

```nginx
root /path/public_html;
location /api/ { try_files $uri /api/index.php?$query_string; }
location ~ ^/api/(lib|routes)/ { return 403; }
location ~ ^/api/config(\.sample)?\.php$ { return 403; }
location /uploads/ { location ~ \.(php|phtml|phar|html?)$ { return 403; } }
location / { try_files $uri $uri/ /index.html; }
location ~ \.php$ { include fastcgi_params; fastcgi_pass unix:/run/php/php8.1-fpm.sock;
  fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; }
```

Teruskan juga header `Authorization` ke PHP-FPM (`fastcgi_param HTTP_AUTHORIZATION $http_authorization;`). Tambahkan header keamanan (nosniff, X-Frame-Options DENY) dan batas `client_max_body_size` ≥ 20m.

## 10. Keamanan dan operasional

- Folder `lib/`, `routes/`, dan `config*.php` di `api/` tidak boleh dapat diakses dari web (sudah diblokir `.htaccess`; uji dengan membuka `/api/config.sample.php` → harus 403).
- Nonaktifkan directory listing (sudah diatur lewat `IndexIgnore`).
- Batasi `/api/health/db` bila dianggap sensitif (hanya menampilkan jumlah tabel/pengguna dan versi PHP).
- Matikan `display_errors` di PHP (aplikasi sudah mematikannya sendiri) dan arahkan log galat ke berkas di luar web root.
- **Cadangan:** jadwalkan `mysqldump --single-transaction --default-character-set=utf8mb4 NAMA_DB` harian dan salinan `public_html/uploads/` mingguan, simpan di luar server. Uji pemulihan minimal sekali.
- Pembaruan aplikasi: ganti isi `public_html/` (kecuali `api/config.php` dan `uploads/`), tanpa mengimpor ulang database kecuali pemilik aplikasi memberi skrip migrasi.

## 11. Yang dilaporkan kembali ke pemilik

URL produksi, hasil poin 8.1–8.5, versi PHP & MySQL, jadwal cadangan, dan setiap pesan galat yang muncul (salin teks lengkapnya).
