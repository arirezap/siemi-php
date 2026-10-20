<?php
declare(strict_types=1);

final class Auth
{
    private static function b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
    private static function unb64(string $s): string { return base64_decode(strtr($s, '-_', '+/')) ?: ''; }

    public static function buatToken(array $user): string
    {
        $now = time();
        $h = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $p = self::b64(json_encode([
            'sub' => (int)$user['id'], 'role' => $user['role'], 'unit_id' => $user['unit_id'],
            'name' => $user['name'], 'iat' => $now, 'exp' => $now + (int)Config::get('jwt_ttl', 43200),
        ], JSON_UNESCAPED_UNICODE));
        $sig = self::b64(hash_hmac('sha256', "$h.$p", (string)Config::get('jwt_secret'), true));
        return "$h.$p.$sig";
    }

    /** Mengembalikan payload bila token sah, null bila tidak. */
    public static function periksaToken(string $t): ?array
    {
        $bagian = explode('.', $t);
        if (count($bagian) !== 3) return null;
        [$h, $p, $s] = $bagian;
        $hdr = json_decode(self::unb64($h), true);
        if (!is_array($hdr) || ($hdr['alg'] ?? '') !== 'HS256') return null;
        $ok = hash_equals(self::b64(hash_hmac('sha256', "$h.$p", (string)Config::get('jwt_secret'), true)), $s);
        if (!$ok) return null;
        $pl = json_decode(self::unb64($p), true);
        if (!is_array($pl) || ($pl['exp'] ?? 0) < time()) return null;
        return $pl;
    }

    private static function tokenDariHeader(): ?string
    {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($h === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) if (strtolower($k) === 'authorization') $h = $v;
        }
        return str_starts_with($h, 'Bearer ') ? substr($h, 7) : null;
    }

    /** Middleware: wajib login. Mengisi $req->user. */
    public static function wajibLogin(Request $req): void
    {
        $t = self::tokenDariHeader();
        if (!$t) throw new HttpError('Token tidak ditemukan', 401);
        $pl = self::periksaToken($t);
        if (!$pl) throw new HttpError('Token tidak valid atau kedaluwarsa', 401);
        $u = Db::one('SELECT u.id, u.nip, u.name, u.email, u.role, u.jabatan, u.unit_id, u.is_active,
                             un.name AS unit_name, un.code AS unit_code
                        FROM users u LEFT JOIN units un ON un.id = u.unit_id WHERE u.id = ?', [(int)$pl['sub']]);
        if (!$u || !$u['is_active']) throw new HttpError('Pengguna tidak aktif', 401);
        $req->user = $u;
    }

    /** Pembuat middleware peran. admin selalu lolos. */
    public static function peran(string ...$roles): callable
    {
        return function (Request $req) use ($roles): void {
            if (!$req->user) throw new HttpError('Belum login', 401);
            if ($req->user['role'] === 'admin') return;
            if (!in_array($req->user['role'], $roles, true))
                throw new HttpError('Akses ditolak. Dibutuhkan peran: ' . implode(' / ', $roles), 403);
        };
    }

    public static function bisaAksesUnit(array $user, mixed $unitId): bool
    {
        if (in_array($user['role'], ['admin', 'spm', 'pimpinan', 'auditor'], true)) return true;
        return (int)$user['unit_id'] === (int)$unitId;
    }
}
