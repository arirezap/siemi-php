<?php
declare(strict_types=1);

final class Config
{
    private static array $c = [];

    public static function muat(): void
    {
        $f = __DIR__ . '/../config.php';
        if (!is_file($f)) {
            Http::kirimJson(['error' => 'config.php belum dibuat. Salin api/config.sample.php menjadi api/config.php.'], 500);
            exit;
        }
        self::$c = require $f;
        date_default_timezone_set(self::$c['timezone'] ?? 'Asia/Jakarta');
        $s = (string)(self::$c['jwt_secret'] ?? '');
        if (strlen($s) < 32 || str_starts_with($s, 'GANTI-')) {
            Http::kirimJson(['error' => 'jwt_secret di config.php belum diatur (minimal 32 karakter acak).'], 500);
            exit;
        }
    }

    public static function get(string $k, mixed $default = null): mixed
    {
        return self::$c[$k] ?? $default;
    }
}
