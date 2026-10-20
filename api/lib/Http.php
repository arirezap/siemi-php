<?php
declare(strict_types=1);

class HttpError extends Exception
{
    public function __construct(string $pesan, public int $status = 400) { parent::__construct($pesan); }
}

final class Request
{
    public array $params = [];
    public ?array $user = null;
    private ?array $body = null;

    public function __construct(public string $method, public string $path) {}

    public function body(): array
    {
        if ($this->body === null) {
            $raw = file_get_contents('php://input');
            $d = $raw !== '' && $raw !== false ? json_decode($raw, true) : [];
            $this->body = is_array($d) ? $d : [];
        }
        return $this->body;
    }

    /** Ambil satu field body (null bila tidak ada). */
    public function in(string $k, mixed $default = null): mixed
    {
        return $this->body()[$k] ?? $default;
    }

    public function query(string $k, mixed $default = null): mixed
    {
        return $_GET[$k] ?? $default;
    }

    public function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? 'tanpa-ip';
    }
}

final class Http
{
    public static function kirimJson(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public static function headerKeamanan(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Cache-Control: no-store');
    }

    /** Pembatas laju sederhana berbasis berkas (fixed window per IP). */
    public static function batasiLaju(string $ip, int $batas = 120, int $jendela = 60): void
    {
        $f = sys_get_temp_dir() . '/siemi_laju_' . md5($ip) . '.json';
        $now = time();
        $e = ['n' => 0, 'reset' => $now + $jendela];
        $h = @fopen($f, 'c+');
        if (!$h) return;
        flock($h, LOCK_EX);
        $isi = stream_get_contents($h);
        $d = $isi ? json_decode($isi, true) : null;
        if (is_array($d) && ($d['reset'] ?? 0) > $now) $e = $d;
        $e['n']++;
        ftruncate($h, 0); rewind($h); fwrite($h, json_encode($e));
        flock($h, LOCK_UN); fclose($h);
        if ($e['n'] > $batas) {
            header('Retry-After: ' . max(1, $e['reset'] - $now));
            throw new HttpError('Terlalu banyak permintaan, coba lagi sebentar lagi.', 429);
        }
    }
}

/** Router mini: pola seperti "/sasaran/:id/capaian". Middleware = fungsi (Request): void yang boleh melempar HttpError. */
final class Router
{
    private array $rute = [];

    public function tambah(string $method, string $pola, callable $handler, array $mw = []): void
    {
        $regex = '#^' . preg_replace('#:([A-Za-z_]+)#', '(?P<$1>[^/]+)', $pola) . '/?$#';
        $this->rute[] = [$method, $regex, $handler, $mw];
    }

    public function get(string $p, callable $h, array $mw = []): void    { $this->tambah('GET', $p, $h, $mw); }
    public function post(string $p, callable $h, array $mw = []): void   { $this->tambah('POST', $p, $h, $mw); }
    public function put(string $p, callable $h, array $mw = []): void    { $this->tambah('PUT', $p, $h, $mw); }
    public function delete(string $p, callable $h, array $mw = []): void { $this->tambah('DELETE', $p, $h, $mw); }

    public function jalankan(Request $req): mixed
    {
        $adaPath = false;
        foreach ($this->rute as [$m, $regex, $h, $mw]) {
            if (!preg_match($regex, $req->path, $mt)) continue;
            $adaPath = true;
            if ($m !== $req->method) continue;
            foreach ($mt as $k => $v) if (is_string($k)) $req->params[$k] = urldecode($v);
            foreach ($mw as $fn) $fn($req);
            return $h($req);
        }
        throw new HttpError($adaPath ? 'Metode tidak didukung' : 'Rute tidak ditemukan', $adaPath ? 405 : 404);
    }
}

/** Pembungkus hasil handler bila perlu status selain 200 (mis. 201 Created). */
final class Respons
{
    public function __construct(public mixed $data, public int $status = 200) {}
}
