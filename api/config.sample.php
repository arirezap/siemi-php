<?php
// Salin berkas ini menjadi config.php lalu isi sesuai hosting. JANGAN unggah config.php ke Git.
return [
    'db_host'     => 'localhost',
    'db_port'     => 0,                       // 0 = bawaan. MAMP: isi 8889 dan db_host '127.0.0.1'
    'db_name'     => 'cpaneluser_siemi',      // nama database MySQL (cPanel biasanya berawalan nama akun)
    'db_user'     => 'cpaneluser_siemi',
    'db_pass'     => 'GANTI-DENGAN-SANDI-DATABASE',
    // Kunci penanda token login. Wajib acak, minimal 32 karakter. Contoh pembuatan: lihat panduan.
    'jwt_secret'  => 'GANTI-DENGAN-TEKS-ACAK-PANJANG-MINIMAL-32-KARAKTER',
    'jwt_ttl'     => 43200,                   // masa berlaku login (detik) = 12 jam
    'timezone'    => 'Asia/Jakarta',
    'db_timezone' => '+07:00',
    // Folder penyimpanan berkas unggahan (harus bisa ditulis) dan alamat webnya
    'upload_dir'  => __DIR__ . '/../uploads',
    'upload_url'  => '/uploads',
    'debug'       => false,                   // true hanya saat mencari masalah; tampilkan pesan galat rinci
];
