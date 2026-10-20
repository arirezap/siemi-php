<?php
// Router untuk PHP Built-in Server (php -S localhost:8000 router.php)
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$file = __DIR__ . $uri;

// 1. Berkas statis yang ada (assets, css, js, gambar, dsb.) dilayani langsung
if ($uri !== '/' && file_exists($file) && !is_dir($file)) {
    return false;
}

// 2. Permintaan API diteruskan ke api/index.php
if (str_starts_with($uri, '/api')) {
    require __DIR__ . '/api/index.php';
    return;
}

// 3. Halaman frontend React SPA
require __DIR__ . '/index.html';
