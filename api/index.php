<?php
declare(strict_types=1);

/* Pintu masuk tunggal API SIEMI PKTJ (PHP + MySQL). Semua permintaan /api/* diarahkan ke sini oleh .htaccess. */

ini_set('display_errors', '0');
error_reporting(E_ALL);

foreach (['Http', 'Config', 'Db', 'Auth', 'Slug', 'Peringkat', 'SkorSkala', 'Berkas', 'Util', 'Xlsx'] as $f) require __DIR__ . "/lib/$f.php";
foreach (['auth', 'master', 'konten', 'publik', 'dashboard', 'pelaksanaan', 'sasaranMutu', 'standar', 'acuan', 'kompetensi', 'emi', 'pengendalian', 'rtm', 'spme'] as $f) require __DIR__ . "/routes/$f.php";

Http::headerKeamanan();
Config::muat();

set_exception_handler(function (Throwable $e): void {
    if ($e instanceof HttpError) { Http::kirimJson(['error' => $e->getMessage()], $e->status); return; }
    error_log('[SIEMI] ' . $e);
    $debug = (bool)Config::get('debug', false);
    Http::kirimJson(['error' => $debug ? $e->getMessage() : 'Kesalahan server'], 500);
});

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(204); exit; }

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
// Buang awalan sampai "/api" (aplikasi boleh berada di subfolder)
$pos = strpos($uri, '/api/');
$path = $pos !== false ? substr($uri, $pos + 4) : (str_ends_with($uri, '/api') ? '/' : $uri);
$path = '/' . trim($path, '/');

$router = new Router();
$router->get('/health', fn() => ['ok' => true, 'app' => 'EMI PKTJ (PHP)', 'time' => gmdate('c')]);
$router->get('/health/db', function () {
    $n = (int)Db::val('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()');
    $u = (int)Db::val('SELECT COUNT(*) FROM users');
    return ['ok' => $n > 0 && $u > 0, 'tabel' => $n, 'pengguna' => $u, 'php' => PHP_VERSION];
});
rute_auth($router);
rute_master($router);
rute_konten($router);
rute_publik($router);
rute_dashboard($router);
rute_pelaksanaan($router);
rute_sasaranMutu($router);
rute_standar($router);
rute_acuan($router);
rute_kompetensi($router);
rute_emi($router);
rute_pengendalian($router);
rute_rtm($router);
rute_spme($router);

$hasil = $router->jalankan(new Request($method, $path));
if ($hasil instanceof Respons) Http::kirimJson($hasil->data, $hasil->status);
else Http::kirimJson($hasil);
